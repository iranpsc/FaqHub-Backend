<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateSitemaps;
use App\Models\Category;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateSitemapsTest extends TestCase
{
    use RefreshDatabase;

    private string $sitemapDir;

    private string $ftpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sitemapDir = public_path('sitemap');
        $this->ftpRoot = storage_path('framework/testing/ftp');

        // Reconfigure FTP disk to local for testing
        Config::set('filesystems.disks.ftp', [
            'driver' => 'local',
            'root' => $this->ftpRoot,
        ]);

        File::ensureDirectoryExists($this->ftpRoot);

        // Clean public/sitemap before each test
        if (File::exists($this->sitemapDir)) {
            File::cleanDirectory($this->sitemapDir);
        }
    }

    protected function tearDown(): void
    {
        // Clean up generated sitemap files
        if (File::exists($this->sitemapDir)) {
            File::cleanDirectory($this->sitemapDir);
        }
        if (File::exists($this->ftpRoot)) {
            File::cleanDirectory($this->ftpRoot);
        }

        parent::tearDown();
    }

    public function test_generates_sitemap_files_for_seeded_data(): void
    {
        $user = User::factory()->create(['username' => 'testauthor']);
        $category = Category::factory()->create(['slug' => 'test-category']);
        $tag = Tag::factory()->create(['slug' => 'test-tag']);
        $question = Question::factory()->published()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
        ]);
        $question->tags()->attach($tag);

        (new GenerateSitemaps)->handle();

        $this->assertFileExists($this->sitemapDir.'/questions-sitemap-1.xml');
        $this->assertFileExists($this->sitemapDir.'/categories-sitemap.xml');
        $this->assertFileExists($this->sitemapDir.'/tags-sitemap.xml');
        $this->assertFileExists($this->sitemapDir.'/authors-sitemap.xml');
        $this->assertFileExists($this->sitemapDir.'/sitemap.xml');
    }

    public function test_sitemap_xml_contains_expected_url_patterns(): void
    {
        $user = User::factory()->create(['username' => 'johndoe']);
        $category = Category::factory()->create(['slug' => 'programming']);
        $tag = Tag::factory()->create(['slug' => 'php']);
        $question = Question::factory()->published()->create([
            'slug' => 'what-is-laravel',
            'category_id' => $category->id,
            'user_id' => $user->id,
        ]);
        $question->tags()->attach($tag);

        (new GenerateSitemaps)->handle();

        $questionsSitemap = file_get_contents($this->sitemapDir.'/questions-sitemap-1.xml');
        $this->assertStringContainsString('faqhub.ir', $questionsSitemap);
        $this->assertStringContainsString('what-is-laravel', $questionsSitemap);

        $categoriesSitemap = file_get_contents($this->sitemapDir.'/categories-sitemap.xml');
        $this->assertStringContainsString('faqhub.ir', $categoriesSitemap);
        $this->assertStringContainsString('programming', $categoriesSitemap);

        $tagsSitemap = file_get_contents($this->sitemapDir.'/tags-sitemap.xml');
        $this->assertStringContainsString('faqhub.ir', $tagsSitemap);
        $this->assertStringContainsString('php', $tagsSitemap);

        $authorsSitemap = file_get_contents($this->sitemapDir.'/authors-sitemap.xml');
        $this->assertStringContainsString('faqhub.ir', $authorsSitemap);
        $this->assertStringContainsString('johndoe', $authorsSitemap);
    }

    public function test_sitemap_index_references_individual_sitemaps(): void
    {
        $user = User::factory()->create(['username' => 'author1']);
        $category = Category::factory()->create(['slug' => 'general']);
        $tag = Tag::factory()->create(['slug' => 'laravel']);
        $question = Question::factory()->published()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
        ]);
        $question->tags()->attach($tag);

        (new GenerateSitemaps)->handle();

        $indexContent = file_get_contents($this->sitemapDir.'/sitemap.xml');
        $this->assertStringContainsString('questions-sitemap-1.xml', $indexContent);
        $this->assertStringContainsString('categories-sitemap.xml', $indexContent);
        $this->assertStringContainsString('tags-sitemap.xml', $indexContent);
        $this->assertStringContainsString('authors-sitemap.xml', $indexContent);
    }

    public function test_files_are_uploaded_to_ftp_disk(): void
    {
        $user = User::factory()->create(['username' => 'ftpuser']);
        $category = Category::factory()->create(['slug' => 'ftp-category']);
        $tag = Tag::factory()->create(['slug' => 'ftp-tag']);
        $question = Question::factory()->published()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
        ]);
        $question->tags()->attach($tag);

        (new GenerateSitemaps)->handle();

        // Check FTP disk (reconfigured to local) has the files
        $ftpDisk = Storage::disk('ftp');
        $this->assertTrue($ftpDisk->exists('questions-sitemap-1.xml'));
        $this->assertTrue($ftpDisk->exists('categories-sitemap.xml'));
        $this->assertTrue($ftpDisk->exists('tags-sitemap.xml'));
        $this->assertTrue($ftpDisk->exists('authors-sitemap.xml'));
        $this->assertTrue($ftpDisk->exists('sitemap.xml'));
    }

    public function test_empty_database_does_not_crash_and_generates_no_sitemap_index(): void
    {
        // No entities in DB

        (new GenerateSitemaps)->handle();

        // No sitemap.xml should be created (generatedFiles is empty)
        $this->assertFileDoesNotExist($this->sitemapDir.'/sitemap.xml');

        // FTP upload loop is still called but iterates over nothing - no crash
        $ftpDisk = Storage::disk('ftp');
        $this->assertFalse($ftpDisk->exists('sitemap.xml'));
    }

    public function test_tags_without_questions_are_excluded_from_tags_sitemap(): void
    {
        // Tag has no questions attached, so it should not appear in sitemap
        Tag::factory()->create(['slug' => 'unused-tag']);
        $category = Category::factory()->create(['slug' => 'cat1']);
        $user = User::factory()->create(['username' => null]);

        // No questions, no tags with questions, no users with username
        // Only the category sitemap should be created
        (new GenerateSitemaps)->handle();

        $this->assertFileExists($this->sitemapDir.'/categories-sitemap.xml');
        $this->assertFileDoesNotExist($this->sitemapDir.'/tags-sitemap.xml');
    }

    public function test_users_without_username_are_excluded_from_authors_sitemap(): void
    {
        User::factory()->withoutUsername()->create();

        (new GenerateSitemaps)->handle();

        // No authors with username → no authors sitemap
        $this->assertFileDoesNotExist($this->sitemapDir.'/authors-sitemap.xml');
    }

    public function test_ftp_failure_logs_error_and_rethrows_exception(): void
    {
        $category = Category::factory()->create(['slug' => 'cat']);
        $user = User::factory()->create(['username' => 'author']);
        Question::factory()->published()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
        ]);

        // Mock Storage::disk('ftp') to throw
        $fakeDisk = \Mockery::mock(Filesystem::class);
        $fakeDisk->shouldReceive('writeStream')->andThrow(new \RuntimeException('FTP connection failed'));

        Storage::shouldReceive('disk')
            ->with('ftp')
            ->andReturn($fakeDisk);

        Log::shouldReceive('error')->once()->with(
            \Mockery::on(fn ($msg) => str_contains($msg, 'FTP')),
            \Mockery::any()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FTP connection failed');

        (new GenerateSitemaps)->handle();
    }

    public function test_upload_skips_missing_local_files(): void
    {
        $category = Category::factory()->create(['slug' => 'skip-missing']);
        (new GenerateSitemaps)->handle();

        $job = new GenerateSitemaps;
        $reflection = new \ReflectionClass($job);
        $generated = $reflection->getProperty('generatedFiles');
        $generated->setValue($job, ['missing-file.xml', 'categories-sitemap.xml']);

        $upload = $reflection->getMethod('uploadSitemapsToFtp');
        $upload->invoke($job, $this->sitemapDir);

        $this->assertTrue(Storage::disk('ftp')->exists('categories-sitemap.xml'));
        $this->assertFalse(Storage::disk('ftp')->exists('missing-file.xml'));
    }

    public function test_multipart_sitemap_when_links_exceed_limit(): void
    {
        // Create enough categories to force a second sitemap part (MAX_LINKS_PER_FILE = 5000).
        // Use a bulk insert for speed.
        $now = now();
        $rows = [];
        for ($i = 1; $i <= 5001; $i++) {
            $rows[] = [
                'name' => "Category {$i}",
                'slug' => "cat-{$i}",
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($rows) === 500) {
                Category::query()->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            Category::query()->insert($rows);
        }

        (new GenerateSitemaps)->handle();

        $this->assertFileExists($this->sitemapDir.'/categories-sitemap-1.xml');
        $this->assertFileExists($this->sitemapDir.'/categories-sitemap-2.xml');
        $this->assertFileDoesNotExist($this->sitemapDir.'/categories-sitemap.xml');
    }
}
