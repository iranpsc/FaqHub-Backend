<?php

namespace Tests\Unit\Services;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLoggerEdgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_question_created_returns_early_without_user(): void
    {
        $question = Question::factory()->create(['user_id' => User::factory()->create()->id]);
        $question->setRelation('user', null);
        $question->user_id = null;

        app(ActivityLogger::class)->logQuestionCreated($question, null);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_log_answer_created_returns_early_without_user(): void
    {
        $answer = Answer::factory()->create();
        $answer->setRelation('user', null);

        app(ActivityLogger::class)->logAnswerCreated($answer, null);

        $this->assertDatabaseMissing('activity_log', ['description' => 'created_answer']);
    }

    public function test_log_comment_created_returns_early_without_user(): void
    {
        $comment = Comment::factory()->create();
        $comment->setRelation('user', null);

        app(ActivityLogger::class)->logCommentCreated($comment, null);

        $this->assertDatabaseMissing('activity_log', ['description' => 'created_comment']);
    }

    public function test_log_publishing_default_description_for_unknown_subject(): void
    {
        $user = User::factory()->create();
        $subject = User::factory()->create();

        app(ActivityLogger::class)->logPublishing($subject, $user);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'published',
            'causer_id' => $user->id,
        ]);
    }

    public function test_get_question_helpers_return_null_for_unknown_types(): void
    {
        $logger = app(ActivityLogger::class);
        $unknown = new class
        {
            public int $id = 1;
        };

        $fromVotable = new ReflectionMethod(ActivityLogger::class, 'getQuestionFromVotable');
        $fromSubject = new ReflectionMethod(ActivityLogger::class, 'getQuestionFromSubject');
        $fromCommentable = new ReflectionMethod(ActivityLogger::class, 'getQuestionFromCommentable');

        $this->assertNull($fromVotable->invoke($logger, $unknown));
        $this->assertNull($fromSubject->invoke($logger, $unknown));

        $comment = Comment::factory()->create([
            'commentable_type' => User::class,
            'commentable_id' => User::factory()->create()->id,
        ]);
        $this->assertNull($fromCommentable->invoke($logger, $comment));
    }

    public function test_log_vote_on_answer_and_comment_resolves_question(): void
    {
        $user = User::factory()->create();
        $answer = Answer::factory()->published()->create();
        $comment = Comment::factory()->create([
            'commentable_type' => Question::class,
            'commentable_id' => $answer->question_id,
        ]);

        $logger = app(ActivityLogger::class);
        $logger->logVote($answer, $user, 'up');
        $logger->logVote($comment, $user, 'down');

        $this->assertSame(2, Activity::query()->where('description', 'voted')->count());
    }
}
