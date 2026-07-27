<?php

namespace Tests\Feature\Dashboard;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardActivityTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    public function test_guest_can_retrieve_activity_feed_with_expected_shape(): void
    {
        $user = User::factory()->create(['name' => 'Activity User']);
        $question = Question::factory()->published()->create([
            'user_id' => $user->id,
            'title' => 'Activity Question',
        ]);
        $this->logQuestionCreated($question, $user);

        $response = $this->getJson('/api/dashboard/activity')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('message', 'فعالیت‌ها با موفقیت دریافت شد')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'user_name',
                        'user_id',
                        'user_image',
                        'created_at',
                        'month',
                        'type',
                        'description',
                    ],
                ],
                'grouped_data',
                'pagination' => [
                    'limit',
                    'offset',
                    'next_offset',
                    'has_more',
                    'total',
                ],
            ])
            ->assertJsonPath('data.0.type', 'question')
            ->assertJsonPath('data.0.user_name', 'Activity User')
            ->assertJsonPath('data.0.title', 'Activity Question')
            ->assertJsonPath('pagination.limit', 30)
            ->assertJsonPath('pagination.offset', 0)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('pagination.has_more', false);
    }

    public function test_activity_defaults_to_limit_thirty_and_offset_zero(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 35) as $i) {
            $question = Question::factory()->published()->create([
                'user_id' => $user->id,
                'title' => "Q{$i}",
            ]);
            $this->logQuestionCreated($question, $user);
            Activity::query()->latest('id')->first()?->forceFill([
                'created_at' => now()->subSeconds($i),
            ])->save();
        }

        $response = $this->getJson('/api/dashboard/activity')->assertOk();

        $this->assertCount(30, $response->json('data'));
        $this->assertSame(30, $response->json('pagination.limit'));
        $this->assertSame(0, $response->json('pagination.offset'));
        $this->assertSame(30, $response->json('pagination.next_offset'));
        $this->assertTrue($response->json('pagination.has_more'));
        $this->assertSame(35, $response->json('pagination.total'));
    }

    public function test_activity_respects_limit_and_offset_pagination(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            $question = Question::factory()->published()->create([
                'user_id' => $user->id,
                'title' => "Paged {$i}",
            ]);
            $this->logQuestionCreated($question, $user);
            Activity::query()->latest('id')->first()?->forceFill([
                'created_at' => now()->subMinutes(6 - $i),
            ])->save();
        }

        $page1 = $this->getJson('/api/dashboard/activity?limit=2&offset=0')->assertOk();
        $page2 = $this->getJson('/api/dashboard/activity?limit=2&offset=2')->assertOk();
        $page3 = $this->getJson('/api/dashboard/activity?limit=2&offset=4')->assertOk();

        $this->assertCount(2, $page1->json('data'));
        $this->assertCount(2, $page2->json('data'));
        $this->assertCount(1, $page3->json('data'));
        $this->assertTrue($page1->json('pagination.has_more'));
        $this->assertTrue($page2->json('pagination.has_more'));
        $this->assertFalse($page3->json('pagination.has_more'));
        $this->assertSame(2, $page1->json('pagination.next_offset'));
        $this->assertSame(4, $page2->json('pagination.next_offset'));
    }

    public function test_activity_is_ordered_newest_first(): void
    {
        $user = User::factory()->create();
        $older = Question::factory()->published()->create(['user_id' => $user->id, 'title' => 'Older']);
        $newer = Question::factory()->published()->create(['user_id' => $user->id, 'title' => 'Newer']);

        $this->logQuestionCreated($older, $user);
        Activity::query()->latest('id')->first()?->forceFill([
            'created_at' => now()->subHour(),
        ])->save();

        $this->logQuestionCreated($newer, $user);
        Activity::query()->latest('id')->first()?->forceFill([
            'created_at' => now(),
        ])->save();

        $titles = collect($this->getJson('/api/dashboard/activity')->assertOk()->json('data'))
            ->pluck('title')
            ->all();

        $this->assertSame(['Newer', 'Older'], $titles);
    }

    public function test_activity_groups_entries_by_persian_month(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create([
            'user_id' => $user->id,
            'title' => 'Grouped',
        ]);
        $this->logQuestionCreated($question, $user);

        $response = $this->getJson('/api/dashboard/activity')->assertOk();
        $month = $response->json('data.0.month');
        $grouped = $response->json('grouped_data');

        $this->assertIsString($month);
        $this->assertNotSame('', $month);
        $this->assertArrayHasKey($month, $grouped);
        $this->assertCount(1, $grouped[$month]);
        $this->assertSame('Grouped', $grouped[$month][0]['title']);
    }

    public function test_activity_transforms_all_supported_log_types(): void
    {
        $user = User::factory()->create(['name' => 'Logger']);
        $publisher = User::factory()->admin()->create(['name' => 'Publisher']);
        $question = Question::factory()->published()->create([
            'user_id' => $user->id,
            'title' => 'Subject Question',
        ]);
        $answer = Answer::factory()->published()->create([
            'user_id' => $user->id,
            'question_id' => $question->id,
        ]);
        $comment = Comment::factory()->published()->forQuestion($question)->create([
            'user_id' => $user->id,
        ]);

        $this->logQuestionCreated($question, $user);
        $this->logAnswerCreated($answer, $user);
        $this->logCommentCreated($comment, $user);
        $this->logVote($question, $user, 'up');
        $this->logPublishing($question, $publisher);
        $this->logFeaturing($question, $publisher, true);
        $this->logFeaturing($question, $publisher, false);
        $this->logAnswerCorrectness($answer, $publisher, true);

        $types = collect($this->getJson('/api/dashboard/activity?limit=100')->assertOk()->json('data'))
            ->pluck('type')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['answer', 'comment', 'feature', 'publish', 'question', 'vote'], $types);
        $this->assertDatabaseCount('activity_log', 8);
    }

    public function test_activity_filters_out_unknown_log_descriptions(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create(['user_id' => $user->id]);

        activity()
            ->causedBy($user)
            ->performedOn($question)
            ->log('unknown_event');

        $this->logQuestionCreated($question, $user);

        $response = $this->getJson('/api/dashboard/activity')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('question', $response->json('data.0.type'));
        // pagination.total counts raw activity_log rows, including unknown ones
        $this->assertSame(2, $response->json('pagination.total'));
    }

    public function test_activity_handles_deleted_subject_via_properties_fallback(): void
    {
        $user = User::factory()->create(['name' => 'Causer']);
        $question = Question::factory()->published()->create([
            'user_id' => $user->id,
            'title' => 'Will Be Deleted',
            'slug' => 'will-be-deleted',
        ]);
        $this->logQuestionCreated($question, $user);
        $question->delete();

        $response = $this->getJson('/api/dashboard/activity')->assertOk();

        $this->assertSame('Will Be Deleted', $response->json('data.0.title'));
        $this->assertSame('will-be-deleted', $response->json('data.0.slug'));
        $this->assertNull($response->json('data.0.question_id'));
        $this->assertStringContainsString('Will Be Deleted', $response->json('data.0.description'));
    }

    public function test_activity_anonymous_causer_falls_back_to_persian_label(): void
    {
        $question = Question::factory()->published()->create(['title' => 'Orphan Subject']);

        activity()
            ->performedOn($question)
            ->withProperties([
                'title' => $question->title,
                'slug' => $question->slug,
            ])
            ->log('created_question');

        $this->getJson('/api/dashboard/activity')
            ->assertOk()
            ->assertJsonPath('data.0.user_name', 'کاربر ناشناس')
            ->assertJsonPath('data.0.user_id', null);
    }

    public function test_activity_empty_feed_returns_empty_collections(): void
    {
        $this->getJson('/api/dashboard/activity')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('grouped_data', [])
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('pagination.has_more', false);
    }

    public function test_authenticated_users_can_access_activity(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/dashboard/activity')->assertOk();

        $this->actingAsAdmin();
        $this->getJson('/api/dashboard/activity')->assertOk();
    }

    #[DataProvider('validPaginationProvider')]
    public function test_activity_accepts_valid_pagination(int $limit, int $offset): void
    {
        $this->getJson("/api/dashboard/activity?limit={$limit}&offset={$offset}")
            ->assertOk()
            ->assertJsonPath('pagination.limit', $limit)
            ->assertJsonPath('pagination.offset', $offset);
    }

    public static function validPaginationProvider(): array
    {
        return [
            'min limit' => [1, 0],
            'max limit' => [100, 0],
            'max offset' => [10, 5000],
            'mid values' => [25, 10],
        ];
    }

    #[DataProvider('invalidPaginationProvider')]
    public function test_activity_rejects_invalid_pagination(
        string $query,
        string $errorFragment
    ): void {
        $response = $this->getJson('/api/dashboard/activity?'.$query);

        $this->assertDashboardValidationFailure($response, $errorFragment);
    }

    public static function invalidPaginationProvider(): array
    {
        return [
            'limit zero' => ['limit=0', 'at least 1'],
            'limit above max' => ['limit=101', 'must not be greater than 100'],
            'limit string' => ['limit=abc', 'must be an integer'],
            'offset negative' => ['offset=-1', 'must be at least 0'],
            'offset above max' => ['offset=5001', 'must not be greater than 5000'],
            'offset string' => ['offset=xyz', 'must be an integer'],
        ];
    }

    public function test_activity_does_not_expose_causer_tokens_or_emails(): void
    {
        $user = User::factory()->create([
            'email' => 'causer@example.com',
            'access_token' => 'activity-access',
            'refresh_token' => 'activity-refresh',
        ]);
        $question = Question::factory()->published()->create(['user_id' => $user->id]);
        $this->logQuestionCreated($question, $user);

        $encoded = json_encode($this->getJson('/api/dashboard/activity')->assertOk()->json());

        $this->assertStringNotContainsString('causer@example.com', $encoded);
        $this->assertStringNotContainsString('activity-access', $encoded);
        $this->assertStringNotContainsString('activity-refresh', $encoded);
    }
}
