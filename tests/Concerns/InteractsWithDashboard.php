<?php

namespace Tests\Concerns;

use App\Models\Answer;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use App\Models\Vote;
use App\Services\ActivityLogger;
use Laravel\Sanctum\Sanctum;

trait InteractsWithDashboard
{
    protected function actingAsAdmin(array $attributes = []): User
    {
        $user = User::factory()->admin()->create($attributes);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function actingAsUser(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['role' => 'user'], $attributes));

        Sanctum::actingAs($user);

        return $user;
    }

    protected function createPublishedQuestion(array $attributes = [], array $relations = []): Question
    {
        $question = Question::factory()->published()->create($attributes);

        if (isset($relations['tags'])) {
            $question->tags()->attach(collect($relations['tags'])->pluck('id'));
        }

        return $question->fresh(['user', 'tags', 'category']);
    }

    protected function createUnpublishedQuestion(array $attributes = []): Question
    {
        return Question::factory()->unpublished()->create($attributes);
    }

    protected function createPublishedAnswer(array $attributes = []): Answer
    {
        return Answer::factory()->published()->create($attributes);
    }

    protected function createCorrectAnswer(array $attributes = []): Answer
    {
        return Answer::factory()->published()->correct()->create($attributes);
    }

    protected function createPublishedComment(array $attributes = []): Comment
    {
        return Comment::factory()->published()->create($attributes);
    }

    protected function attachVotes(Question $question, int $count, string $type = 'up'): void
    {
        for ($i = 0; $i < $count; $i++) {
            Vote::query()->create([
                'votable_type' => Question::class,
                'votable_id' => $question->id,
                'type' => $type,
                'user_id' => User::factory()->create(['score' => 0])->id,
                'last_voted_at' => now(),
            ]);
        }
    }

    protected function logQuestionCreated(Question $question, ?User $user = null): void
    {
        app(ActivityLogger::class)->logQuestionCreated($question, $user);
    }

    protected function logAnswerCreated(Answer $answer, ?User $user = null): void
    {
        app(ActivityLogger::class)->logAnswerCreated($answer, $user);
    }

    protected function logCommentCreated(Comment $comment, ?User $user = null): void
    {
        app(ActivityLogger::class)->logCommentCreated($comment, $user);
    }

    protected function logVote(Question|Answer|Comment $votable, User $user, string $voteType = 'up'): void
    {
        app(ActivityLogger::class)->logVote($votable, $user, $voteType);
    }

    protected function logPublishing(Question|Answer|Comment $subject, User $publisher): void
    {
        app(ActivityLogger::class)->logPublishing($subject, $publisher);
    }

    protected function logFeaturing(Question $question, User $user, bool $featured = true): void
    {
        $logger = app(ActivityLogger::class);

        if ($featured) {
            $logger->logFeaturing($question, $user);
        } else {
            $logger->logUnfeaturing($question, $user);
        }
    }

    protected function logAnswerCorrectness(Answer $answer, User $user, bool $isCorrect = true): void
    {
        app(ActivityLogger::class)->logAnswerCorrectness($answer, $user, $isCorrect);
    }

    protected function createQuestionWithRelations(array $attributes = []): Question
    {
        $category = Category::factory()->create(['name' => 'Laravel']);
        $tag = Tag::factory()->create(['name' => 'Eloquent']);
        $user = User::factory()->create(['name' => 'Dashboard Author']);

        $question = Question::factory()->published()->create(array_merge([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'title' => 'Dashboard related question',
            'views' => 42,
        ], $attributes));

        $question->tags()->attach($tag);

        return $question->fresh(['user', 'tags', 'category']);
    }

    /**
     * Validation failures inside DashboardController try/catch currently return HTTP 500.
     * Assert that contract so regressions toward proper 422 remain visible.
     */
    protected function assertDashboardValidationFailure($response, string $expectedErrorFragment): void
    {
        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'error']);

        $this->assertStringContainsString(
            $expectedErrorFragment,
            (string) $response->json('error')
        );
    }
}
