<?php

namespace Tests\Feature\Dashboard;

use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardPopularQuestionsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithDashboard;

    public function test_guest_can_list_popular_questions_ordered_by_views_then_votes(): void
    {
        $lowViews = Question::factory()->published()->create(['views' => 10, 'title' => 'Low']);
        $highViews = Question::factory()->published()->create(['views' => 100, 'title' => 'High']);
        $midViewsMoreVotes = Question::factory()->published()->create(['views' => 50, 'title' => 'Mid']);
        $midViewsFewerVotes = Question::factory()->published()->create(['views' => 50, 'title' => 'Mid Low Votes']);

        $this->attachVotes($midViewsMoreVotes, 5);
        $this->attachVotes($midViewsFewerVotes, 1);
        $this->attachVotes($lowViews, 20); // votes do not beat higher views

        $response = $this->getJson('/api/questions/popular')->assertOk();

        $this->assertSame([
            $highViews->id,
            $midViewsMoreVotes->id,
            $midViewsFewerVotes->id,
            $lowViews->id,
        ], collect($response->json('data'))->pluck('id')->all());

        $response->assertJsonPath('success', true)
            ->assertJsonPath('message', 'سوالات محبوب با موفقیت دریافت شد')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'slug',
                        'created_at',
                        'answers_count',
                        'votes_count',
                        'views_count',
                        'user' => ['id', 'name'],
                        'category',
                        'tags',
                    ],
                ],
            ]);
    }

    public function test_popular_defaults_to_fifteen_items(): void
    {
        Question::factory()->published()->count(20)->create(['views' => 1]);

        $this->getJson('/api/questions/popular')
            ->assertOk()
            ->assertJsonCount(15, 'data');
    }

    public function test_popular_respects_custom_limit(): void
    {
        Question::factory()->published()->count(8)->create(['views' => 1]);

        $this->getJson('/api/questions/popular?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_popular_excludes_unpublished_questions(): void
    {
        Question::factory()->published()->create(['views' => 5, 'title' => 'Published']);
        Question::factory()->unpublished()->create(['views' => 9999, 'title' => 'Draft Popular']);

        $response = $this->getJson('/api/questions/popular')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Published', $response->json('data.0.title'));
    }

    public function test_popular_returns_empty_when_no_published_questions(): void
    {
        Question::factory()->unpublished()->count(2)->create(['views' => 100]);

        $this->getJson('/api/questions/popular')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_popular_period_all_is_default_and_includes_old_questions(): void
    {
        $this->travelTo(now());

        $recent = Question::factory()->published()->create([
            'views' => 10,
            'created_at' => now()->subDays(2),
        ]);
        $old = Question::factory()->published()->create([
            'views' => 20,
            'created_at' => now()->subYears(2),
        ]);

        $ids = collect($this->getJson('/api/questions/popular')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$old->id, $recent->id], $ids);

        $idsExplicit = collect($this->getJson('/api/questions/popular?period=all')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$old->id, $recent->id], $idsExplicit);
    }

    #[DataProvider('periodFilterProvider')]
    public function test_popular_filters_by_period(
        string $period,
        string $includedRelative,
        string $excludedRelative
    ): void {
        $this->travelTo(now());

        $included = Question::factory()->published()->create([
            'views' => 50,
            'created_at' => now()->modify($includedRelative),
            'title' => 'Included',
        ]);
        Question::factory()->published()->create([
            'views' => 999,
            'created_at' => now()->modify($excludedRelative),
            'title' => 'Excluded',
        ]);

        $response = $this->getJson('/api/questions/popular?period='.$period)->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($included->id, $response->json('data.0.id'));
    }

    public static function periodFilterProvider(): array
    {
        return [
            'week excludes older than week' => ['week', '-3 days', '-10 days'],
            'month excludes older than month' => ['month', '-10 days', '-45 days'],
            'year excludes older than year' => ['year', '-100 days', '-400 days'],
        ];
    }

    public function test_popular_period_week_includes_boundary_recent_items(): void
    {
        $this->travelTo(now());

        $withinWeek = Question::factory()->published()->create([
            'views' => 1,
            'created_at' => now()->subDays(6),
        ]);

        $this->getJson('/api/questions/popular?period=week')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withinWeek->id);
    }

    public function test_popular_includes_relation_payload_and_counts(): void
    {
        $question = $this->createQuestionWithRelations(['views' => 77]);
        $this->createPublishedAnswer(['question_id' => $question->id]);
        $this->attachVotes($question, 2);

        $this->getJson('/api/questions/popular')
            ->assertOk()
            ->assertJsonPath('data.0.views_count', 77)
            ->assertJsonPath('data.0.answers_count', 1)
            ->assertJsonPath('data.0.votes_count', 2)
            ->assertJsonPath('data.0.user.name', 'Dashboard Author')
            ->assertJsonPath('data.0.category.name', 'Laravel')
            ->assertJsonPath('data.0.tags.0.name', 'Eloquent');
    }

    public function test_popular_route_is_not_captured_by_question_slug_show(): void
    {
        Question::factory()->published()->create(['slug' => 'other']);

        $this->getJson('/api/questions/popular')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[DataProvider('validLimitProvider')]
    public function test_popular_accepts_valid_limits(int $limit, int $seedCount, int $expectedCount): void
    {
        Question::factory()->published()->count($seedCount)->create(['views' => 1]);

        $this->getJson('/api/questions/popular?limit='.$limit)
            ->assertOk()
            ->assertJsonCount($expectedCount, 'data');
    }

    public static function validLimitProvider(): array
    {
        return [
            'min' => [1, 3, 1],
            'max' => [50, 55, 50],
        ];
    }

    #[DataProvider('invalidLimitProvider')]
    public function test_popular_rejects_invalid_limits(mixed $limit, string $errorFragment): void
    {
        $response = $this->getJson('/api/questions/popular?limit='.urlencode((string) $limit));

        $this->assertDashboardValidationFailure($response, $errorFragment);
    }

    public static function invalidLimitProvider(): array
    {
        return [
            'zero' => [0, 'at least 1'],
            'negative' => [-5, 'at least 1'],
            'above max' => [51, 'must not be greater than 50'],
            'string' => ['nope', 'must be an integer'],
        ];
    }

    #[DataProvider('invalidPeriodProvider')]
    public function test_popular_rejects_invalid_periods(string $period): void
    {
        $response = $this->getJson('/api/questions/popular?period='.urlencode($period));

        $this->assertDashboardValidationFailure($response, 'selected period is invalid');
    }

    public static function invalidPeriodProvider(): array
    {
        return [
            'day' => ['day'],
            'sql injection' => ["all' OR '1'='1"],
            'numeric' => ['1'],
            'ALL uppercase' => ['ALL'],
        ];
    }

    public function test_popular_empty_period_is_treated_as_null_and_defaults_to_all(): void
    {
        $this->travelTo(now());

        Question::factory()->published()->create([
            'views' => 1,
            'created_at' => now()->subYears(2),
        ]);

        // ConvertEmptyStringsToNull → nullable period passes → defaults to all
        $this->getJson('/api/questions/popular?period=')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_popular_accepts_all_valid_periods(): void
    {
        Question::factory()->published()->create(['views' => 1, 'created_at' => now()]);

        foreach (['week', 'month', 'year', 'all'] as $period) {
            $this->getJson('/api/questions/popular?period='.$period)
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    public function test_popular_does_not_expose_sensitive_user_fields(): void
    {
        $author = User::factory()->create([
            'email' => 'popular-author@example.com',
            'mobile' => '09121111111',
            'refresh_token' => 'refresh-secret',
        ]);
        Question::factory()->published()->create([
            'user_id' => $author->id,
            'views' => 10,
            'content' => 'hidden content body',
        ]);

        $payload = $this->getJson('/api/questions/popular')->assertOk()->json('data.0');
        $encoded = json_encode($this->getJson('/api/questions/popular')->json());

        $this->assertArrayNotHasKey('content', $payload);
        $this->assertArrayNotHasKey('email', $payload['user']);
        $this->assertArrayNotHasKey('mobile', $payload['user']);
        $this->assertStringNotContainsString('popular-author@example.com', $encoded);
        $this->assertStringNotContainsString('refresh-secret', $encoded);
        $this->assertStringNotContainsString('09121111111', $encoded);
    }
}
