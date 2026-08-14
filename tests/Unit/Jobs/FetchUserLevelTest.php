<?php

namespace Tests\Unit\Jobs;

use App\Jobs\FetchUserLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FetchUserLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_response_updates_level(): void
    {
        $user = User::factory()->create(['level' => 2, 'score' => 10, 'email' => 'level@example.com']);

        Http::fake([
            'https://api.metarang.com/api/users/level@example.com/level' => Http::response([
                'level' => ['slug' => '5'],
                'score' => 7,
            ], 200),
        ]);

        (new FetchUserLevel($user))->handle();

        $user->refresh();
        $this->assertSame(5, $user->level);
        $this->assertSame(17, $user->score);
    }

    public function test_score_is_not_incremented_when_level_does_not_increase(): void
    {
        $user = User::factory()->create(['level' => 5, 'score' => 10, 'email' => 'same@example.com']);

        Http::fake([
            'https://api.metarang.com/api/users/same@example.com/level' => Http::response([
                'level' => ['slug' => '5'],
                'score' => 100,
            ], 200),
        ]);

        (new FetchUserLevel($user))->handle();

        $user->refresh();
        $this->assertSame(5, $user->level);
        $this->assertSame(10, $user->score);
    }

    public function test_score_is_not_incremented_when_level_decreases(): void
    {
        $user = User::factory()->create(['level' => 8, 'score' => 50, 'email' => 'down@example.com']);

        Http::fake([
            'https://api.metarang.com/api/users/down@example.com/level' => Http::response([
                'level' => ['slug' => '3'],
                'score' => 20,
            ], 200),
        ]);

        (new FetchUserLevel($user))->handle();

        $user->refresh();
        $this->assertSame(3, $user->level);
        $this->assertSame(50, $user->score);
    }

    public function test_failed_http_response_leaves_user_unchanged_and_logs_error(): void
    {
        $user = User::factory()->create(['level' => 4, 'score' => 12, 'email' => 'fail@example.com']);

        Http::fake([
            'https://api.metarang.com/api/users/fail@example.com/level' => Http::response('error', 500),
        ]);

        Log::shouldReceive('error')->once();

        (new FetchUserLevel($user))->handle();

        $user->refresh();
        $this->assertSame(4, $user->level);
        $this->assertSame(12, $user->score);
    }

    public function test_missing_level_slug_leaves_user_unchanged_and_logs_warning(): void
    {
        $user = User::factory()->create(['level' => 4, 'score' => 12, 'email' => 'missing@example.com']);

        Http::fake([
            'https://api.metarang.com/api/users/missing@example.com/level' => Http::response([
                'score' => 5,
            ], 200),
        ]);

        Log::shouldReceive('warning')->once();

        (new FetchUserLevel($user))->handle();

        $user->refresh();
        $this->assertSame(4, $user->level);
        $this->assertSame(12, $user->score);
    }
}
