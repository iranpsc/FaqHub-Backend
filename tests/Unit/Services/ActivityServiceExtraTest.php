<?php

namespace Tests\Unit\Services;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use App\Services\ActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityServiceExtraTest extends TestCase
{
    use RefreshDatabase;

    private ActivityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ActivityService::class);
    }

    // ── hasMoreActivities ─────────────────────────────────────────────────────

    public function test_has_more_activities_returns_false_when_no_activities(): void
    {
        $this->assertFalse($this->service->hasMoreActivities(0));
    }

    public function test_has_more_activities_returns_false_when_offset_equals_total(): void
    {
        $this->seedActivities(5);

        $this->assertFalse($this->service->hasMoreActivities(5));
    }

    public function test_has_more_activities_returns_true_when_offset_less_than_total(): void
    {
        $this->seedActivities(5);

        $this->assertTrue($this->service->hasMoreActivities(3));
    }

    public function test_has_more_activities_returns_false_when_offset_greater_than_total(): void
    {
        $this->seedActivities(3);

        $this->assertFalse($this->service->hasMoreActivities(10));
    }

    public function test_has_more_activities_returns_true_for_zero_offset_with_activities(): void
    {
        $this->seedActivities(1);

        $this->assertTrue($this->service->hasMoreActivities(0));
    }

    // ── getActivityStats ──────────────────────────────────────────────────────

    public function test_get_activity_stats_returns_zero_counts_for_empty_db(): void
    {
        $stats = $this->service->getActivityStats();

        $this->assertSame(0, $stats['total_questions']);
        $this->assertSame(0, $stats['total_answers']);
        $this->assertSame(0, $stats['total_comments']);
        $this->assertSame(0, $stats['total_votes']);
    }

    public function test_get_activity_stats_counts_questions_within_period(): void
    {
        $this->createActivityLog('created_question', now()->subMonths(1));
        $this->createActivityLog('created_question', now()->subMonths(4)); // outside 3-month window

        $stats = $this->service->getActivityStats(3);

        $this->assertSame(1, $stats['total_questions']);
    }

    public function test_get_activity_stats_counts_answers_within_period(): void
    {
        $this->createActivityLog('created_answer', now()->subMonths(2));
        $this->createActivityLog('created_answer', now()->subMonths(2));

        $stats = $this->service->getActivityStats(3);

        $this->assertSame(2, $stats['total_answers']);
    }

    public function test_get_activity_stats_counts_comments_within_period(): void
    {
        $this->createActivityLog('created_comment', now()->subWeeks(2));

        $stats = $this->service->getActivityStats(3);

        $this->assertSame(1, $stats['total_comments']);
    }

    public function test_get_activity_stats_counts_votes_within_period(): void
    {
        $this->createActivityLog('voted', now()->subWeek());
        $this->createActivityLog('voted', now()->subWeek());
        $this->createActivityLog('voted', now()->subWeek());

        $stats = $this->service->getActivityStats(3);

        $this->assertSame(3, $stats['total_votes']);
    }

    public function test_get_activity_stats_includes_period_info(): void
    {
        $stats = $this->service->getActivityStats(6, 1);

        $this->assertArrayHasKey('period', $stats);
        $this->assertSame(6, $stats['period']['months']);
        $this->assertSame(1, $stats['period']['offset']);
        $this->assertArrayHasKey('start_date', $stats['period']);
        $this->assertArrayHasKey('end_date', $stats['period']);
    }

    public function test_get_activity_stats_with_offset_shifts_window(): void
    {
        // Activity 2 months ago (within offset=0 window, but not in offset=1 month ago window)
        $this->createActivityLog('created_question', now()->subMonths(2));

        $statsNoOffset = $this->service->getActivityStats(3, 0);
        $this->assertSame(1, $statsNoOffset['total_questions']);

        // With offset=3 months, the window is 3-6 months ago, so the activity is outside
        $statsWithOffset = $this->service->getActivityStats(3, 3);
        $this->assertSame(0, $statsWithOffset['total_questions']);
    }

    public function test_get_activity_stats_excludes_unrelated_activity_types(): void
    {
        $this->createActivityLog('published_question', now()->subWeeks(1));
        $this->createActivityLog('featured_question', now()->subWeeks(1));

        $stats = $this->service->getActivityStats(3);

        $this->assertSame(0, $stats['total_questions']);
        $this->assertSame(0, $stats['total_answers']);
        $this->assertSame(0, $stats['total_comments']);
        $this->assertSame(0, $stats['total_votes']);
    }

    public function test_transform_handles_vote_labels_for_answer_comment_and_default(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create();
        $answer = Answer::factory()->published()->create(['question_id' => $question->id]);
        $comment = Comment::factory()->published()->create([
            'commentable_type' => Question::class,
            'commentable_id' => $question->id,
        ]);

        activity()->causedBy($user)->performedOn($answer)->withProperties([
            'vote_type' => 'up',
            'votable_type' => Answer::class,
            'question_title' => $question->title,
            'question_slug' => $question->slug,
        ])->log('voted');

        activity()->causedBy($user)->performedOn($comment)->withProperties([
            'vote_type' => 'down',
            'votable_type' => Comment::class,
            'question_title' => $question->title,
            'question_slug' => $question->slug,
        ])->log('voted');

        activity()->causedBy($user)->performedOn($question)->withProperties([
            'vote_type' => 'up',
            'votable_type' => 'App\\Models\\Unknown',
            'question_title' => $question->title,
            'question_slug' => $question->slug,
        ])->log('voted');

        // created_comment with non-Comment subject should be filtered out
        activity()->causedBy($user)->performedOn($question)->log('created_comment');

        $result = $this->service->getActivities(10);
        $voteDescriptions = collect($result['activities'])->where('type', 'vote')->pluck('description');

        $this->assertTrue($voteDescriptions->contains(fn ($d) => str_contains($d, 'پاسخ')));
        $this->assertTrue($voteDescriptions->contains(fn ($d) => str_contains($d, 'نظر')));
        $this->assertTrue($voteDescriptions->contains(fn ($d) => str_contains($d, 'محتوا')));
        $this->assertFalse(collect($result['activities'])->contains(fn ($a) => ($a['type'] ?? null) === 'comment'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedActivities(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createActivityLog('created_question', now()->subHours($i + 1));
        }
    }

    private function createActivityLog(string $description, \DateTimeInterface $createdAt): void
    {
        Activity::create([
            'log_name' => 'default',
            'description' => $description,
            'subject_type' => null,
            'subject_id' => null,
            'causer_type' => null,
            'causer_id' => null,
            'properties' => '{}',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
