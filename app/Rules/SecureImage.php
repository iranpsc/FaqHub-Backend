<?php

namespace App\Rules;

use Closure;
use finfo;
use GdImage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class SecureImage implements ValidationRule
{
    protected array $allowedMimeTypes = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
    ];

    protected array $imagetypeToMime = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /**
     * Dangerous file extensions that should never be allowed regardless of content.
     * These could be executed by misconfigured servers or used in attacks.
     */
    protected const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'exe', 'bat', 'cmd', 'sh', 'bash', 'zsh', 'ps1',
        'js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs',
        'asp', 'aspx', 'jsp', 'jspx', 'cfm', 'cfc',
        'pl', 'py', 'rb', 'cgi', 'htaccess', 'htpasswd',
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'xsl',
        'shtml', 'shtm', 'ssi',
    ];

    /**
     * Dangerous patterns that indicate embedded code or polyglot attacks.
     */
    protected const DANGEROUS_PATTERNS = [
        '<?php',
        '<?=',
        '<script',
        '<%',
        '#!/',
        'eval(',
        'base64_decode(',
        'system(',
        'exec(',
        'shell_exec(',
        'passthru(',
        'popen(',
        'proc_open(',
        '__HALT_COMPILER',
    ];

    protected int $maxPixelCount;

    protected ?int $minWidth;

    protected ?int $minHeight;

    protected ?int $maxWidth;

    protected ?int $maxHeight;

    protected int $maxFileSize;

    public function __construct(
        ?array $allowedMimeTypes = null,
        int $maxPixelCount = 25_000_000,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?int $minWidth = null,
        ?int $minHeight = null,
        int $maxFileSize = 10_485_760 // 10MB default
    ) {
        if ($allowedMimeTypes !== null) {
            $this->allowedMimeTypes = [];
            foreach ($allowedMimeTypes as $mime) {
                $this->allowedMimeTypes[$mime] = true;
            }
            $this->imagetypeToMime = array_filter(
                $this->imagetypeToMime,
                function (string $mime): bool {
                    return isset($this->allowedMimeTypes[$mime]);
                }
            );
        }

        $this->maxPixelCount = $maxPixelCount;
        $this->maxWidth = $maxWidth;
        $this->maxHeight = $maxHeight;
        $this->minWidth = $minWidth;
        $this->minHeight = $minHeight;
        $this->maxFileSize = $maxFileSize;
    }

    /**
     * @param  Closure(string, string|null): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute must be an uploaded file.');

            return;
        }
        if (! $value->isValid() || $value->getSize() === 0) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute is not a valid upload.');

            return;
        }

        // Check file size limit
        if ($value->getSize() > $this->maxFileSize) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute file size exceeds the maximum allowed.');

            return;
        }

        $path = $value->getPathname();

        // Check for dangerous extensions (including double extensions like image.php.jpg)
        if (! $this->hasSecureFilename($value)) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute has a potentially dangerous filename.');

            return;
        }

        // Check for null bytes in filename (path traversal attack)
        if ($this->containsNullByte($value->getClientOriginalName())) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute filename contains invalid characters.');

            return;
        }

        // Scan file content for dangerous patterns (polyglot detection)
        if (! $this->isContentSafe($path)) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute contains potentially malicious content.');

            return;
        }

        $mimeFromExif = null;
        if (function_exists('exif_imagetype')) {
            $imageType = @exif_imagetype($path);
            if ($imageType !== false && isset($this->imagetypeToMime[$imageType])) {
                $mimeFromExif = $this->imagetypeToMime[$imageType];
            }
        }

        $imageSize = @getimagesize($path);
        if ($imageSize === false || ! isset($imageSize[0], $imageSize[1])) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute is not a valid image.');

            return;
        }
        $width = (int) $imageSize[0];
        $height = (int) $imageSize[1];
        $totalPixels = $width * $height;

        if ($mimeFromExif === null && isset($imageSize['mime']) && is_string($imageSize['mime'])) {
            $mimeFromExif = $imageSize['mime'];
        }
        if ($mimeFromExif === null || ! isset($this->allowedMimeTypes[$mimeFromExif])) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute must be a valid image (jpeg, png, webp).');

            return;
        }

        if ($totalPixels <= 0) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute is not a valid image.');

            return;
        }
        if ($totalPixels > $this->maxPixelCount) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute image is too large.');

            return;
        }

        // Validate dimension constraints
        if ($this->minWidth !== null && $width < $this->minWidth) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute width is too small.');

            return;
        }
        if ($this->minHeight !== null && $height < $this->minHeight) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute height is too small.');

            return;
        }
        if ($this->maxWidth !== null && $width > $this->maxWidth) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute width exceeds the maximum allowed.');

            return;
        }
        if ($this->maxHeight !== null && $height > $this->maxHeight) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute height exceeds the maximum allowed.');

            return;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeFromFinfo = $finfo->file($path) ?: '';
        $mimeFromFramework = (string) $value->getMimeType();

        if (! isset($this->allowedMimeTypes[$mimeFromExif])) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute image type is not allowed.');

            return;
        }
        if ($mimeFromFinfo !== $mimeFromExif) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute file type could not be verified.');

            return;
        }
        if ($mimeFromFramework !== '' && $mimeFromFramework !== $mimeFromExif) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute file type does not match its contents.');

            return;
        }

        // Final verification: attempt to actually load the image with GD
        // This catches sophisticated attacks that pass header checks but aren't valid images
        if (! $this->canLoadWithGd($path, $mimeFromExif)) {
            /** @phpstan-ignore-next-line */
            $fail($attribute, 'The :attribute could not be processed as a valid image.');

            return;
        }
    }

    /**
     * Check if the filename is secure (no dangerous extensions, including double extensions).
     */
    protected function hasSecureFilename(UploadedFile $file): bool
    {
        $originalName = $file->getClientOriginalName();

        // Normalize to lowercase for comparison
        $lowerName = strtolower($originalName);

        // Check for double extensions (e.g., image.php.jpg, shell.phtml.png)
        $parts = explode('.', $lowerName);

        // Check each part of the filename for dangerous extensions
        foreach ($parts as $part) {
            if (in_array($part, self::DANGEROUS_EXTENSIONS, true)) {
                return false;
            }
        }

        // Also check the actual extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
            return false;
        }

        // Ensure extension matches allowed image types
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        if (! in_array($extension, $allowedExtensions, true)) {
            return false;
        }

        return true;
    }

    /**
     * Check for null bytes which can be used for path traversal attacks.
     */
    protected function containsNullByte(string $filename): bool
    {
        return str_contains($filename, "\0");
    }

    /**
     * Scan file content for dangerous patterns that indicate polyglot attacks.
     */
    protected function isContentSafe(string $path): bool
    {
        // Read first 8KB for signature scanning (most attacks are in headers)
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $headerContent = fread($handle, 8192);
        fclose($handle);

        if ($headerContent === false) {
            return false;
        }

        // Check for dangerous patterns (case-insensitive for some)
        $lowerContent = strtolower($headerContent);

        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            $searchPattern = strtolower($pattern);
            if (str_contains($lowerContent, $searchPattern)) {
                return false;
            }
        }

        // Check for PHAR archive signatures
        if (str_contains($headerContent, '__HALT_COMPILER')) {
            return false;
        }

        // Check for ZIP signatures (can be used in polyglot attacks)
        // But only if they appear at unexpected positions (not as part of valid image data)
        $zipSignature = "PK\x03\x04";
        if (str_starts_with($headerContent, $zipSignature)) {
            return false;
        }

        // Scan tail of file for appended PHP code (common polyglot technique)
        $fileSize = @filesize($path);
        if ($fileSize !== false && $fileSize > 8192) {
            $handle = @fopen($path, 'rb');
            if ($handle !== false) {
                fseek($handle, -4096, SEEK_END);
                $tailContent = fread($handle, 4096);
                fclose($handle);

                if ($tailContent !== false) {
                    $lowerTail = strtolower($tailContent);
                    foreach (['<?php', '<?=', '<script'] as $dangerousTag) {
                        if (str_contains($lowerTail, $dangerousTag)) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Attempt to load the image with GD library to verify it's a genuine image.
     * This catches sophisticated attacks that pass header/magic byte checks.
     */
    protected function canLoadWithGd(string $path, string $mimeType): bool
    {
        if (! extension_loaded('gd')) {
            // If GD isn't available, we can't do this check - rely on other validations
            return true;
        }

        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            default => false,
        };

        if ($image instanceof GdImage) {
            // Successfully loaded - clean up and return true
            imagedestroy($image);

            return true;
        }

        return false;
    }
}
