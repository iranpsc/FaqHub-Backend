<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserUpdateImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_authenticated_user_can_upload_jpeg_avatar(): void
    {
        $user = User::factory()->create(['image' => null]);

        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $response = $this->postJson('/api/user/update-image', [
            'image' => $file,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'image_url'])
            ->assertJson([
                'message' => 'تصویر پروفایل با موفقیت بروزرسانی شد',
            ]);

        $user->refresh();
        $this->assertNotNull($user->image);
        $this->assertStringStartsWith('avatars/'.$user->id.'_', $user->image);
        Storage::disk('public')->assertExists($user->image);
        $this->assertStringContainsString('storage/'.$user->image, $response->json('image_url'));
    }

    #[DataProvider('allowedImageProvider')]
    public function test_allowed_image_formats_are_accepted(string $filename, string $extension): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image($filename, 120, 120);

        $this->postJson('/api/user/update-image', ['image' => $file])
            ->assertOk();

        $user->refresh();
        $this->assertStringEndsWith('.'.$extension, $user->image);
        Storage::disk('public')->assertExists($user->image);
    }

    public static function allowedImageProvider(): array
    {
        return [
            'jpeg' => ['photo.jpeg', 'jpeg'],
            'jpg' => ['photo.jpg', 'jpg'],
            'png' => ['photo.png', 'png'],
            'webp' => ['photo.webp', 'webp'],
        ];
    }

    public function test_uploading_new_avatar_deletes_previous_file(): void
    {
        Storage::disk('public')->put('avatars/old.jpg', 'old-content');

        $user = User::factory()->withImage('avatars/old.jpg')->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image('new.png', 100, 100);

        $this->postJson('/api/user/update-image', ['image' => $file])->assertOk();

        Storage::disk('public')->assertMissing('avatars/old.jpg');
        Storage::disk('public')->assertExists($user->fresh()->image);
    }

    public function test_missing_image_returns_validation_error(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/user/update-image', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image'])
            ->assertJson([
                'message' => 'خطا در اعتبارسنجی',
                'errors' => [
                    'image' => ['لطفا یک تصویر انتخاب کنید'],
                ],
            ]);
    }

    public function test_non_file_image_value_fails_validation(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/user/update-image', [
            'image' => 'not-a-file',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    public function test_image_larger_than_1mb_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $file = UploadedFile::fake()->image('big.jpg', 800, 800)->size(2048);

        $this->postJson('/api/user/update-image', ['image' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    #[DataProvider('rejectedImageProvider')]
    public function test_insecure_or_unsupported_files_are_rejected(callable $fileFactory): void
    {
        Sanctum::actingAs(User::factory()->create());

        $file = $fileFactory();

        $this->postJson('/api/user/update-image', ['image' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    public static function rejectedImageProvider(): array
    {
        return [
            'gif not allowed' => [fn () => UploadedFile::fake()->image('anim.gif', 50, 50)],
            'bmp not allowed' => [fn () => UploadedFile::fake()->image('photo.bmp', 50, 50)],
            'svg xss vector' => [fn () => UploadedFile::fake()->create('xss.svg', 100, 'image/svg+xml')],
            'php double extension' => [fn () => UploadedFile::fake()->image('shell.php.jpg', 50, 50)],
            'phtml double extension' => [fn () => UploadedFile::fake()->image('backdoor.phtml.png', 50, 50)],
            'html disguised' => [fn () => UploadedFile::fake()->create('page.html', 50, 'text/html')],
            'pdf document' => [fn () => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
            'empty upload' => [fn () => UploadedFile::fake()->create('empty.jpg', 0, 'image/jpeg')],
        ];
    }

    public function test_polyglot_php_in_image_content_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $path = sys_get_temp_dir().'/polyglot_'.uniqid().'.jpg';
        $image = imagecreatetruecolor(20, 20);
        imagejpeg($image, $path);
        imagedestroy($image);
        file_put_contents($path, file_get_contents($path)."\n<?php system('id'); ?>");

        $file = new UploadedFile($path, 'polyglot.jpg', 'image/jpeg', null, true);

        $this->postJson('/api/user/update-image', ['image' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);

        @unlink($path);
    }

    public function test_guest_cannot_upload_avatar(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg');

        $this->postJson('/api/user/update-image', ['image' => $file])
            ->assertUnauthorized();
    }

    public function test_upload_cannot_overwrite_another_users_avatar_idor(): void
    {
        Storage::disk('public')->put('avatars/victim_old.jpg', 'victim');

        $victim = User::factory()->withImage('avatars/victim_old.jpg')->create();
        $attacker = User::factory()->create(['image' => null]);

        Sanctum::actingAs($attacker);

        $file = UploadedFile::fake()->image('attacker.jpg', 80, 80);

        $this->postJson('/api/user/update-image', [
            'image' => $file,
            'user_id' => $victim->id,
            'id' => $victim->id,
        ])->assertOk();

        $attacker->refresh();
        $victim->refresh();

        $this->assertNotNull($attacker->image);
        $this->assertSame('avatars/victim_old.jpg', $victim->image);
        Storage::disk('public')->assertExists('avatars/victim_old.jpg');
    }

    public function test_mass_assignment_via_image_endpoint_cannot_change_privileged_fields(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'score' => 5,
            'level' => 1,
            'email' => 'img@example.com',
        ]);

        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image('ok.jpg', 60, 60);

        $this->postJson('/api/user/update-image', [
            'image' => $file,
            'role' => 'admin',
            'score' => 9999,
            'level' => 13,
            'email' => 'owned@example.com',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('user', $user->role);
        $this->assertSame(5, $user->score);
        $this->assertSame(1, $user->level);
        $this->assertSame('img@example.com', $user->email);
        $this->assertNotNull($user->image);
    }

    public function test_filename_is_normalized_to_user_id_and_timestamp_not_client_name(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image('../../etc/passwd.jpg', 40, 40);

        $this->postJson('/api/user/update-image', ['image' => $file])->assertOk();

        $path = $user->fresh()->image;
        $this->assertStringStartsWith('avatars/'.$user->id.'_', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('etc/passwd', $path);
    }
}
