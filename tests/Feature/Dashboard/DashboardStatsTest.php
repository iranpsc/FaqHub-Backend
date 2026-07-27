<?php

namespace Tests\Feature\Dashboard;

use App\Models\Answer;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithDashboard;

    public function test_guest_can_retrieve_dashboard_stats_with_expected_shape(): void
    {
        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'آمار با موفقیت دریافت شد')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'totalQuestions',
                    'totalAnswers',
                    'totalUsers',
                    'solvedQuestions',
                ],
            ]);
    }

    public function test_stats_are_zero_when_database_is_empty_aside_from_no_users(): void
    {
        // Fresh DB has zero users until factories create them.
        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.totalQuestions', 0)
            ->assertJsonPath('data.totalAnswers', 0)
            ->assertJsonPath('data.totalUsers', 0)
            ->assertJsonPath('data.solvedQuestions', 0);
    }

    public function test_stats_count_only_published_questions_with_published_at(): void
    {
        Question::factory()->published()->count(3)->create();
        Question::factory()->unpublished()->count(2)->create();

        // published flag true but missing published_at must be excluded
        Question::factory()->create([
            'published' => true,
            'published_at' => null,
        ]);

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.totalQuestions', 3);
    }

    public function test_stats_count_published_answers_regardless_of_published_at(): void
    {
        // Controller SQL: COUNT(*) FROM answers WHERE published = 1 (no published_at check)
        Answer::factory()->published()->count(2)->create();
        Answer::factory()->unpublished()->count(3)->create();
        Answer::factory()->create([
            'published' => true,
            'published_at' => null,
        ]);

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.totalAnswers', 3);
    }

    public function test_stats_count_all_users(): void
    {
        User::factory()->count(4)->create();
        User::factory()->admin()->create();

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.totalUsers', 5);
    }

    public function test_stats_count_distinct_solved_questions_with_correct_answers(): void
    {
        $solvedA = Question::factory()->published()->create();
        $solvedB = Question::factory()->published()->create();
        $unsolved = Question::factory()->published()->create();

        Answer::factory()->published()->correct()->create(['question_id' => $solvedA->id]);
        // Multiple correct answers on same question still count as one solved question
        Answer::factory()->published()->correct()->count(2)->create(['question_id' => $solvedB->id]);
        Answer::factory()->published()->incorrect()->create(['question_id' => $unsolved->id]);

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.solvedQuestions', 2);
    }

    public function test_stats_solved_questions_include_unpublished_questions_with_correct_answers(): void
    {
        // Current SQL does not filter published on the solvedQuestions subquery.
        $draft = Question::factory()->unpublished()->create();
        Answer::factory()->correct()->create(['question_id' => $draft->id]);

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.solvedQuestions', 1);
    }

    public function test_stats_reflect_combined_realistic_dataset(): void
    {
        User::factory()->count(2)->create();

        $published = Question::factory()->published()->count(2)->create();
        Question::factory()->unpublished()->create();

        Answer::factory()->published()->create(['question_id' => $published[0]->id]);
        Answer::factory()->published()->correct()->create(['question_id' => $published[1]->id]);
        Answer::factory()->unpublished()->create(['question_id' => $published[0]->id]);

        // Users created by factories above + answer/question authors inflate totalUsers.
        $response = $this->getJson('/api/dashboard/stats')->assertOk();

        $this->assertSame(2, $response->json('data.totalQuestions'));
        $this->assertSame(2, $response->json('data.totalAnswers'));
        $this->assertSame(1, $response->json('data.solvedQuestions'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.totalUsers'));
        $this->assertIsInt($response->json('data.totalQuestions'));
        $this->assertIsInt($response->json('data.totalAnswers'));
        $this->assertIsInt($response->json('data.totalUsers'));
        $this->assertIsInt($response->json('data.solvedQuestions'));
    }

    public function test_authenticated_user_and_admin_can_also_retrieve_stats(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAsAdmin();
        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_stats_endpoint_returns_500_json_when_query_fails(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->andThrow(new \RuntimeException('forced stats failure'));

        $this->getJson('/api/dashboard/stats')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'خطا در دریافت آمار')
            ->assertJsonPath('error', 'forced stats failure');
    }

    public function test_stats_response_does_not_include_unexpected_top_level_keys(): void
    {
        $payload = $this->getJson('/api/dashboard/stats')->assertOk()->json();

        $this->assertSame(['success', 'data', 'message'], array_keys($payload));
        $this->assertSame(
            ['totalQuestions', 'totalAnswers', 'totalUsers', 'solvedQuestions'],
            array_keys($payload['data'])
        );
    }

    #[DataProvider('httpMethodsProvider')]
    public function test_stats_only_allows_get(string $method, int $expectedStatus): void
    {
        $this->json($method, '/api/dashboard/stats')->assertStatus($expectedStatus);
    }

    public static function httpMethodsProvider(): array
    {
        return [
            'get ok' => ['GET', 200],
            'post not allowed' => ['POST', 405],
            'put not allowed' => ['PUT', 405],
            'delete not allowed' => ['DELETE', 405],
            'patch not allowed' => ['PATCH', 405],
        ];
    }
}
