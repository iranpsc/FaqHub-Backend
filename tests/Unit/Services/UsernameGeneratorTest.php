<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\UsernameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsernameGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_name_generates_user_username(): void
    {
        $username = UsernameGenerator::generate('');
        $this->assertSame('user', $username);
    }

    public function test_whitespace_only_name_generates_user_username(): void
    {
        $username = UsernameGenerator::generate('   ');
        $this->assertSame('user', $username);
    }

    public function test_latin_name_is_slugified(): void
    {
        $username = UsernameGenerator::generate('John Doe');
        $this->assertSame('john-doe', $username);
    }

    public function test_latin_name_with_uppercase_is_lowercased(): void
    {
        $username = UsernameGenerator::generate('Alice Smith');
        $this->assertSame('alice-smith', $username);
    }

    public function test_persian_name_preserves_characters(): void
    {
        $username = UsernameGenerator::generate('علی محمدی');
        $this->assertSame('علی-محمدی', $username);
    }

    public function test_persian_name_with_extra_spaces_is_normalized(): void
    {
        $username = UsernameGenerator::generate('رضا   احمدی');
        $this->assertSame('رضا-احمدی', $username);
    }

    public function test_collision_appends_numeric_suffix(): void
    {
        // Create a user with the desired username
        User::factory()->create(['username' => 'john-doe']);

        $username = UsernameGenerator::generate('John Doe');
        $this->assertSame('john-doe-1', $username);
    }

    public function test_multiple_collisions_increment_suffix(): void
    {
        User::factory()->create(['username' => 'john-doe']);
        User::factory()->create(['username' => 'john-doe-1']);
        User::factory()->create(['username' => 'john-doe-2']);

        $username = UsernameGenerator::generate('John Doe');
        $this->assertSame('john-doe-3', $username);
    }

    public function test_ignore_user_id_excludes_own_user_from_uniqueness_check(): void
    {
        $existingUser = User::factory()->create(['username' => 'jane-doe']);

        // Generating for the same user should return the same username (not add suffix)
        $username = UsernameGenerator::generate('Jane Doe', $existingUser->id);
        $this->assertSame('jane-doe', $username);
    }

    public function test_ignore_user_id_still_avoids_other_users(): void
    {
        $existingUser = User::factory()->create(['username' => 'jane-doe']);
        $otherUser = User::factory()->create(['username' => 'jane-doe-1']);

        // Generating for existingUser: ignores existingUser's own record, so 'jane-doe' is available
        $username = UsernameGenerator::generate('Jane Doe', $existingUser->id);
        $this->assertSame('jane-doe', $username);

        // Without ignoreUserId both are taken
        $username2 = UsernameGenerator::generate('Jane Doe');
        $this->assertSame('jane-doe-2', $username2);
    }

    public function test_long_name_is_truncated_to_60_chars(): void
    {
        $longName = str_repeat('a', 80);
        $username = UsernameGenerator::generate($longName);
        $this->assertLessThanOrEqual(60, strlen($username));
    }

    public function test_long_latin_name_is_truncated(): void
    {
        $longName = 'This Is A Very Long Name That Exceeds The Sixty Character Limit For Usernames In The System';
        $username = UsernameGenerator::generate($longName);
        $this->assertLessThanOrEqual(60, strlen($username));
    }

    public function test_generated_username_is_unique_in_database(): void
    {
        $username = UsernameGenerator::generate('Test User');
        User::factory()->create(['username' => $username]);

        $username2 = UsernameGenerator::generate('Test User');
        $this->assertNotSame($username, $username2);

        $count = User::where('username', $username2)->count();
        $this->assertSame(0, $count);
    }

    public function test_punctuation_only_latin_name_falls_back_to_user(): void
    {
        $username = UsernameGenerator::generate('***');
        $this->assertSame('user', $username);
    }
}
