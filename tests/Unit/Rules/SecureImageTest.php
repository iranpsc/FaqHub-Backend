<?php

namespace Tests\Unit\Rules;

use App\Rules\SecureImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecureImageTest extends TestCase
{
    private function assertPasses(UploadedFile $file, ?SecureImage $rule = null): void
    {
        $validator = Validator::make(
            ['image' => $file],
            ['image' => [$rule ?? new SecureImage]]
        );

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    private function assertFails(UploadedFile $file, ?SecureImage $rule = null): void
    {
        $validator = Validator::make(
            ['image' => $file],
            ['image' => [$rule ?? new SecureImage]]
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('image'));
    }

    public function test_valid_jpeg_passes(): void
    {
        $this->assertPasses(UploadedFile::fake()->image('ok.jpg', 100, 100));
    }

    public function test_valid_png_passes(): void
    {
        $this->assertPasses(UploadedFile::fake()->image('ok.png', 100, 100));
    }

    public function test_valid_webp_passes(): void
    {
        $this->assertPasses(UploadedFile::fake()->image('ok.webp', 100, 100));
    }

    public function test_non_uploaded_file_fails(): void
    {
        $validator = Validator::make(
            ['image' => 'string'],
            ['image' => [new SecureImage]]
        );

        $this->assertTrue($validator->fails());
    }

    public function test_empty_file_fails(): void
    {
        $this->assertFails(UploadedFile::fake()->create('empty.jpg', 0, 'image/jpeg'));
    }

    public function test_file_exceeding_custom_max_size_fails(): void
    {
        $rule = new SecureImage(maxFileSize: 100);
        $file = UploadedFile::fake()->image('big.jpg', 50, 50)->size(200);

        $this->assertFails($file, $rule);
    }

    #[DataProvider('dangerousFilenameProvider')]
    public function test_dangerous_filenames_fail(string $filename): void
    {
        $this->assertFails(UploadedFile::fake()->image($filename, 40, 40));
    }

    public static function dangerousFilenameProvider(): array
    {
        return [
            'php double ext' => ['malware.php.jpg'],
            'phtml double ext' => ['shell.phtml.png'],
            'phar double ext' => ['archive.phar.webp'],
            'js double ext' => ['xss.js.jpg'],
            'svg extension' => ['vector.svg'],
            'html extension' => ['page.html'],
            'exe extension' => ['tool.exe'],
        ];
    }

    public function test_gif_is_rejected_by_default_allowlist(): void
    {
        $this->assertFails(UploadedFile::fake()->image('anim.gif', 40, 40));
    }

    public function test_null_byte_in_filename_fails(): void
    {
        $path = sys_get_temp_dir().'/nullbyte_'.uniqid().'.jpg';
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $path);
        imagedestroy($image);

        $file = new UploadedFile($path, "evil\0.jpg", 'image/jpeg', null, true);

        $this->assertFails($file);
        @unlink($path);
    }

    public function test_php_polyglot_content_fails(): void
    {
        $path = sys_get_temp_dir().'/poly_'.uniqid().'.jpg';
        $image = imagecreatetruecolor(16, 16);
        imagejpeg($image, $path);
        imagedestroy($image);
        file_put_contents($path, file_get_contents($path).'<?php echo 1;');

        $file = new UploadedFile($path, 'poly.jpg', 'image/jpeg', null, true);

        $this->assertFails($file);
        @unlink($path);
    }

    public function test_script_tag_in_content_fails(): void
    {
        $path = sys_get_temp_dir().'/script_'.uniqid().'.jpg';
        $image = imagecreatetruecolor(16, 16);
        imagejpeg($image, $path);
        imagedestroy($image);
        file_put_contents($path, file_get_contents($path).'<script>alert(1)</script>');

        $file = new UploadedFile($path, 'script.jpg', 'image/jpeg', null, true);

        $this->assertFails($file);
        @unlink($path);
    }

    public function test_dimension_constraints_are_enforced(): void
    {
        $tooSmall = UploadedFile::fake()->image('small.jpg', 10, 10);
        $tooWide = UploadedFile::fake()->image('wide.jpg', 500, 50);

        $this->assertFails($tooSmall, new SecureImage(minWidth: 50, minHeight: 50));
        $this->assertFails($tooWide, new SecureImage(maxWidth: 100, maxHeight: 100));
        $this->assertPasses(
            UploadedFile::fake()->image('ok.jpg', 80, 80),
            new SecureImage(minWidth: 50, minHeight: 50, maxWidth: 100, maxHeight: 100)
        );
    }

    public function test_pixel_count_limit_is_enforced(): void
    {
        $file = UploadedFile::fake()->image('pixels.jpg', 100, 100);

        $this->assertFails($file, new SecureImage(maxPixelCount: 1000));
        $this->assertPasses($file, new SecureImage(maxPixelCount: 20_000));
    }

    public function test_custom_mime_allowlist_rejects_disallowed_types(): void
    {
        $png = UploadedFile::fake()->image('only.png', 40, 40);

        $this->assertFails($png, new SecureImage(['image/jpeg']));
        $this->assertPasses($png, new SecureImage(['image/png']));
    }

    public function test_zip_signature_at_start_fails(): void
    {
        $path = sys_get_temp_dir().'/zip_'.uniqid().'.jpg';
        file_put_contents($path, "PK\x03\x04".str_repeat('A', 100));

        $file = new UploadedFile($path, 'zip.jpg', 'image/jpeg', null, true);

        $this->assertFails($file);
        @unlink($path);
    }

    public function test_non_image_bytes_fail_getimagesize(): void
    {
        $path = sys_get_temp_dir().'/notimg_'.uniqid().'.jpg';
        file_put_contents($path, str_repeat('not-an-image', 50));

        $file = new UploadedFile($path, 'notimg.jpg', 'image/jpeg', null, true);

        $this->assertFails($file);
        @unlink($path);
    }

    public function test_framework_mime_mismatch_fails(): void
    {
        $path = sys_get_temp_dir().'/mismatch_'.uniqid().'.jpg';
        $image = imagecreatetruecolor(20, 20);
        imagejpeg($image, $path);
        imagedestroy($image);

        $file = new class($path, 'mismatch.jpg', 'image/png', null, true) extends UploadedFile
        {
            public function getMimeType(): string
            {
                return 'image/png';
            }
        };

        $this->assertFails($file);
        @unlink($path);
    }

    public function test_large_file_with_php_payload_in_tail_fails(): void
    {
        $path = sys_get_temp_dir().'/largetail_'.uniqid().'.jpg';
        $image = imagecreatetruecolor(40, 40);
        imagejpeg($image, $path);
        imagedestroy($image);

        $padding = str_repeat('A', 9000);
        file_put_contents($path, file_get_contents($path).$padding.'<?php system($_GET["c"]);');

        $file = new UploadedFile($path, 'largetail.jpg', 'image/jpeg', null, true);

        $this->assertFails($file);
        @unlink($path);
    }

    public function test_min_height_constraint_fails_independently(): void
    {
        $file = UploadedFile::fake()->image('tall.jpg', 80, 20);
        $this->assertFails($file, new SecureImage(minHeight: 50));
    }

    public function test_max_height_constraint_fails_independently(): void
    {
        $file = UploadedFile::fake()->image('tootall.jpg', 50, 200);
        $this->assertFails($file, new SecureImage(maxHeight: 100));
    }
}
