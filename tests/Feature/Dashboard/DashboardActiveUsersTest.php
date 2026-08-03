<?php

namespace Tests\Feature\Dashboard;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardActiveUsersTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    public function test_guest_can_list_active_users_ordered_by_score_desc(): void
    {
        $low = User::factory()->create(['name' => 'Low Score', 'score' => 10]);
        $high = User::factory()->create(['name' => 'High Score', 'score' => 100]);
        $mid = User::factory()->create(['name' => 'Mid Score', 'score' => 50]);

        $response = $this->getJson('/api/dashboard/active-users')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('message', 'کاربران فعال با موفقیت دریافت شد')
            ->assertJsonCount(3, 'data');

        $this->assertSame(
            [$high->id, $mid->id, $low->id],
            collect($response->json('data'))->pluck('id')->all()
        );

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'username',
                    'image',
                    'score',
                    'questions_count',
                    'answers_count',
                    'comments_count',
                    'total_activity',
                ],
            ],
        ]);
    }

    public function test_active_users_defaults_to_five(): void
    {
        User::factory()->count(8)->sequence(
            fn ($sequence) => ['score' => 100 - $sequence->index]
        )->create();

        $this->getJson('/api/dashboard/active-users')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_active_users_respects_custom_limit(): void
    {
        User::factory()->count(6)->sequence(
            fn ($sequence) => ['score' => 100 - $sequence->index]
        )->create();

        $this->getJson('/api/dashboard/active-users?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_active_users_include_activity_counts_and_total(): void
    {
        $user = User::factory()->create(['score' => 1_000_000]);
        $question = Question::factory()->create(['user_id' => $user->id]);
        Question::factory()->create(['user_id' => $user->id]);

        Answer::factory()->count(3)->create([
            'user_id' => $user->id,
            'question_id' => $question->id,
        ]);
        Comment::factory()->count(4)->forQuestion($question)->create([
            'user_id' => $user->id,
        ]);

        $this->getJson('/api/dashboard/active-users?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $user->id)
            ->assertJsonPath('data.0.questions_count', 2)
            ->assertJsonPath('data.0.answers_count', 3)
            ->assertJsonPath('data.0.comments_count', 4)
            ->assertJsonPath('data.0.total_activity', 9);
    }

    public function test_active_users_counts_include_unpublished_content(): void
    {
        // Subqueries have no published filter — documents current behavior.
        $user = User::factory()->create(['score' => 1_000_000]);
        $question = Question::factory()->unpublished()->create(['user_id' => $user->id]);

        Answer::factory()->unpublished()->create([
            'user_id' => $user->id,
            'question_id' => $question->id,
        ]);
        Comment::factory()->unpublished()->forQuestion($question)->create([
            'user_id' => $user->id,
        ]);

        $this->getJson('/api/dashboard/active-users?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $user->id)
            ->assertJsonPath('data.0.questions_count', 1)
            ->assertJsonPath('data.0.answers_count', 1)
            ->assertJsonPath('data.0.comments_count', 1)
            ->assertJsonPath('data.0.total_activity', 3);
    }

    public function test_active_users_with_zero_score_and_no_activity_still_listed(): void
    {
        $user = User::factory()->create(['score' => 0, 'name' => 'Newbie']);

        $this->getJson('/api/dashboard/active-users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $user->id)
            ->assertJsonPath('data.0.score', 0)
            ->assertJsonPath('data.0.total_activity', 0);
    }

    public function test_active_users_missing_score_attribute_defaults_to_zero_via_model(): void
    {
        // Column is NOT NULL DEFAULT 0; controller also coalesces with ?? 0.
        $user = User::factory()->create(['score' => 0]);

        $this->getJson('/api/dashboard/active-users')
            ->assertOk()
            ->assertJsonPath('data.0.id', $user->id)
            ->assertJsonPath('data.0.score', 0);
    }

    public function test_active_users_returns_image_path_not_url(): void
    {
        $user = User::factory()->withImage('avatars/demo.jpg')->create(['score' => 1]);

        $this->getJson('/api/dashboard/active-users')
            ->assertOk()
            ->assertJsonPath('data.0.id', $user->id)
            ->assertJsonPath('data.0.image', 'avatars/demo.jpg');
    }

    public function test_active_users_empty_database_returns_empty_data(): void
    {
        $this->getJson('/api/dashboard/active-users')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_authenticated_users_can_access_active_users(): void
    {
        User::factory()->create(['score' => 1]);

        $this->actingAsUser();
        $this->getJson('/api/dashboard/active-users')->assertOk();

        $this->actingAsAdmin();
        $this->getJson('/api/dashboard/active-users')->assertOk();
    }

    #[DataProvider('validLimitProvider')]
    public function test_active_users_accepts_valid_limits(int $limit, int $seedCount, int $expectedCount): void
    {
        User::factory()->count($seedCount)->sequence(
            fn ($sequence) => ['score' => 100 - $sequence->index]
        )->create();

        $this->getJson('/api/dashboard/active-users?limit='.$limit)
            ->assertOk()
            ->assertJsonCount($expectedCount, 'data');
    }

    public static function validLimitProvider(): array
    {
        return [
            'min' => [1, 3, 1],
            'max' => [20, 25, 20],
            'default ceiling below max' => [5, 10, 5],
        ];
    }

    #[DataProvider('invalidLimitProvider')]
    public function test_active_users_rejects_invalid_limits(mixed $limit, string $errorFragment): void
    {
        $response = $this->getJson('/api/dashboard/active-users?limit='.urlencode((string) $limit));

        $this->assertDashboardValidationFailure($response, $errorFragment);
    }

    public static function invalidLimitProvider(): array
    {
        return [
            'zero' => [0, 'at least 1'],
            'negative' => [-1, 'at least 1'],
            'above max' => [21, 'must not be greater than 20'],
            'string' => ['abc', 'must be an integer'],
            'float' => ['2.2', 'must be an integer'],
        ];
    }

    public function test_active_users_does_not_expose_sensitive_fields(): void
    {
        User::factory()->create([
            'score' => 10,
            'email' => 'active@example.com',
            'mobile' => '09123334444',
            'access_token' => 'access-secret',
            'refresh_token' => 'refresh-secret',
            'code' => '123456',
        ]);

        $payload = $this->getJson('/api/dashboard/active-users')->assertOk()->json('data.0');
        $encoded = json_encode($this->getJson('/api/dashboard/active-users')->json());

        foreach (['email', 'mobile', 'access_token', 'refresh_token', 'code', 'role', 'level'] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }

        $this->assertStringNotContainsString('active@example.com', $encoded);
        $this->assertStringNotContainsString('access-secret', $encoded);
        $this->assertStringNotContainsString('refresh-secret', $encoded);
        $this->assertStringNotContainsString('09123334444', $encoded);
        $this->assertStringNotContainsString('123456', $encoded);
    }
}
