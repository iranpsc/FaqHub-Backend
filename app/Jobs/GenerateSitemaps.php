<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Sitemap\SitemapIndex;

class GenerateSitemaps implements ShouldQueue
{
    use Queueable, Dispatchable;

    /**
     * Maximum number of links per sitemap file.
     */
    private const MAX_LINKS_PER_FILE = 5000;

    /**
     * Number of records fetched from the database per lazy iteration step.
     */
    private const LAZY_CHUNK_SIZE = 500;

    /**
     * Collected generated sitemap filenames (relative to public/sitemap).
     *
     * @var array<int, string>
     */
    private array $generatedFiles = [];

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $baseUrl = 'https://faqhub.ir';
        $targetDir = public_path('sitemap');

        if (! File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        $this->generateQuestionsSitemaps($baseUrl, $targetDir);
        $this->generateCategoriesSitemaps($baseUrl, $targetDir);
        $this->generateTagsSitemaps($baseUrl, $targetDir);
        $this->generateAuthorsSitemaps($baseUrl, $targetDir);

        if (! empty($this->generatedFiles)) {
            $index = SitemapIndex::create();
            foreach ($this->generatedFiles as $relativeFile) {
                $index->add($baseUrl . '/sitemap/' . ltrim($relativeFile, '/'));
            }
            $index->writeToFile($targetDir . DIRECTORY_SEPARATOR . 'sitemap.xml');
            $this->generatedFiles[] = 'sitemap.xml';
        }

        $this->uploadSitemapsToFtp($targetDir);
    }

    /**
     * Upload generated sitemap files to the configured FTP disk.
     */
    private function uploadSitemapsToFtp(string $targetDir): void
    {
        try {
            $disk = Storage::disk('ftp');
            foreach ($this->generatedFiles as $relativeFile) {
                $localPath = $targetDir . DIRECTORY_SEPARATOR . $relativeFile;
                if (! File::exists($localPath)) {
                    continue;
                }

                $stream = fopen($localPath, 'rb');
                if ($stream === false) {
                    continue;
                }

                try {
                    $disk->writeStream($relativeFile, $stream);
                } finally {
                    fclose($stream);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to upload sitemaps to FTP: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    private function generateQuestionsSitemaps(string $baseUrl, string $targetDir): void
    {
        $this->streamEntitySitemaps(
            $baseUrl,
            $targetDir,
            Question::query()
                ->published()
                ->select(['id', 'slug', 'updated_at']),
            'questions',
            fn ($question, string $baseUrl): array => [
                $baseUrl . '/questions/' . ltrim((string) $question->slug, '/'),
                $question->updated_at,
            ],
            alwaysNumberParts: true,
        );
    }

    private function generateCategoriesSitemaps(string $baseUrl, string $targetDir): void
    {
        $this->streamEntitySitemaps(
            $baseUrl,
            $targetDir,
            Category::query()->select(['id', 'slug', 'updated_at']),
            'categories',
            fn ($category, string $baseUrl): array => [
                $baseUrl . '/categories/' . ltrim((string) $category->slug, '/'),
                $category->updated_at,
            ],
        );
    }

    private function generateTagsSitemaps(string $baseUrl, string $targetDir): void
    {
        $this->streamEntitySitemaps(
            $baseUrl,
            $targetDir,
            Tag::query()->select(['id', 'slug', 'updated_at']),
            'tags',
            fn ($tag, string $baseUrl): array => [
                $baseUrl . '/tags/' . ltrim((string) $tag->slug, '/'),
                $tag->updated_at,
            ],
        );
    }

    private function generateAuthorsSitemaps(string $baseUrl, string $targetDir): void
    {
        $this->streamEntitySitemaps(
            $baseUrl,
            $targetDir,
            User::query()->select(['id', 'updated_at']),
            'authors',
            fn ($user, string $baseUrl): array => [
                $baseUrl . '/authors/' . (string) $user->id,
                $user->updated_at,
            ],
        );
    }

    /**
     * Stream sitemap XML directly to disk to avoid holding thousands of URLs in memory.
     *
     * @param  callable(mixed, string): array{0: string, 1: DateTimeInterface|null}  $urlBuilder
     */
    private function streamEntitySitemaps(
        string $baseUrl,
        string $targetDir,
        Builder $query,
        string $filenamePrefix,
        callable $urlBuilder,
        bool $alwaysNumberParts = false,
    ): void {
        $part = 1;
        $linksInCurrentFile = 0;
        /** @var resource|null $handle */
        $handle = null;
        $currentFile = null;
        $filesGenerated = [];

        foreach ($query->orderBy('id')->lazyById(self::LAZY_CHUNK_SIZE) as $record) {
            if ($linksInCurrentFile === 0) {
                $currentFile = "{$filenamePrefix}-sitemap-{$part}.xml";
                $handle = $this->openSitemapFile($targetDir . DIRECTORY_SEPARATOR . $currentFile);
            }

            [$loc, $lastMod] = $urlBuilder($record, $baseUrl);
            $this->writeSitemapUrl($handle, $loc, $lastMod);
            $linksInCurrentFile++;

            if ($linksInCurrentFile >= self::MAX_LINKS_PER_FILE) {
                $this->closeSitemapFile($handle);
                $handle = null;
                $filesGenerated[] = $currentFile;
                $this->generatedFiles[] = $currentFile;
                $part++;
                $linksInCurrentFile = 0;
                $currentFile = null;
            }
        }

        if ($handle !== null) {
            $this->closeSitemapFile($handle);
            $filesGenerated[] = $currentFile;
            $this->generatedFiles[] = $currentFile;
        }

        if (! $alwaysNumberParts && count($filesGenerated) === 1 && $part === 1) {
            $numbered = "{$filenamePrefix}-sitemap-1.xml";
            $simple = "{$filenamePrefix}-sitemap.xml";
            $numberedPath = $targetDir . DIRECTORY_SEPARATOR . $numbered;
            $simplePath = $targetDir . DIRECTORY_SEPARATOR . $simple;

            if (File::exists($numberedPath)) {
                File::move($numberedPath, $simplePath);
                $key = array_search($numbered, $this->generatedFiles, true);
                if ($key !== false) {
                    $this->generatedFiles[$key] = $simple;
                }
            }
        }
    }

    /**
     * @return resource
     */
    private function openSitemapFile(string $path)
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open sitemap file for writing: {$path}");
        }

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL);
        fwrite($handle, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL);

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function writeSitemapUrl($handle, string $loc, ?DateTimeInterface $lastMod): void
    {
        fwrite($handle, '  <url>' . PHP_EOL);
        fwrite($handle, '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>' . PHP_EOL);

        if ($lastMod !== null) {
            fwrite($handle, '    <lastmod>' . $lastMod->format(DateTimeInterface::ATOM) . '</lastmod>' . PHP_EOL);
        }

        fwrite($handle, '  </url>' . PHP_EOL);
    }

    /**
     * @param  resource  $handle
     */
    private function closeSitemapFile($handle): void
    {
        fwrite($handle, '</urlset>' . PHP_EOL);
        fclose($handle);
    }
}
