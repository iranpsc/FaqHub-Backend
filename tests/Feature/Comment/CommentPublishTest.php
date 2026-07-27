<?php

namespace Tests\Feature\Comment;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\User;
use App\Notifications\QuestionInteractionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithComments;
use Tests\TestCase;
use TypeError;

class CommentPublishTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithComments;

    public function test_level_two_or_higher_can_publish_comment_on_question_and_awards_scores(): void
    {
        Notification::fake();

        $questionOwner = User::factory()->create(['level' => 1]);
        $author = User::factory()->create(['level' => 1, 'score' => 0]);
        $publisher = User::factory()->create(['level' => 2, 'score' => 0]);
        $question = $this->createPublishedQuestion(['user_id' => $questionOwner->id]);
        $comment = $this->createCommentOnQuestion($question, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($publisher);

        $this->postJson("/api/comments/{$comment->id}/publish")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'نظر با موفقیت منتشر شد')
            ->assertJsonPath('data.published', true);

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'published' => true,
            'published_by' => $publisher->id,
        ]);
        $this->assertNotNull($comment->fresh()->published_at);
        $this->assertEquals(2, $publisher->fresh()->score);
        $this->assertEquals(2, $author->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'published_comment',
            'subject_type' => Comment::class,
            'subject_id' => $comment->id,
            'causer_id' => $publisher->id,
        ]);

        Notification::assertSentTo(
            $questionOwner,
            QuestionInteractionNotification::class,
            function (QuestionInteractionNotification $notification) use ($publisher, $question) {
                return $notification->user->is($publisher)
                    && $notification->question->is($question)
                    && $notification->interactionType === 'comment';
            }
        );
    }

    public function test_owner_with_level_two_or_higher_can_publish_own_comment(): void
    {
        // CommentPolicy short-circuits: level >= 2 => true (unlike AnswerPolicy).
        Notification::fake();

        $owner = User::factory()->create(['level' => 3, 'score' => 0]);
        $question = $this->createPublishedQuestion();
        $comment = $this->createCommentOnQuestion($question, [
            'user_id' => $owner->id,
        ], published: false);

        Sanctum::actingAs($owner);

        $this->postJson("/api/comments/{$comment->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.published', true);

        // Publisher and author are the same user: +2 twice => +4.
        $this->assertEquals(4, $owner->fresh()->score);
    }

    public function test_publish_does_not_notify_when_question_has_no_owner(): void
    {
        Notification::fake();

        $author = User::factory()->create(['level' => 1]);
        $publisher = User::factory()->create(['level' => 4]);
        $question = $this->createPublishedQuestion();
        $question->forceFill(['user_id' => null])->saveQuietly();
        $comment = $this->createCommentOnQuestion($question, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($publisher);

        $this->postJson("/api/comments/{$comment->id}/publish")->assertOk();

        Notification::assertNothingSent();
    }

    public function test_publish_comment_on_answer_throws_type_error_for_notification(): void
    {
        // Documents current bug: publish passes Answer commentable into
        // QuestionInteractionNotification which type-hints Question.
        Notification::fake();

        $author = User::factory()->create(['level' => 1]);
        $publisher = User::factory()->create(['level' => 3]);
        $answer = Answer::factory()->published()->create();
        $comment = $this->createCommentOnAnswer($answer, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($publisher);

        $this->withoutExceptionHandling();

        $this->expectException(TypeError::class);

        $this->postJson("/api/comments/{$comment->id}/publish");
    }

    public function test_guest_cannot_publish_comment(): void
    {
        $comment = $this->createUnpublishedComment();

        $this->postJson("/api/comments/{$comment->id}/publish")
            ->assertUnauthorized();

        $this->assertFalse($comment->fresh()->published);
    }

    public function test_level_one_cannot_publish_any_comment(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 1]);
        $comment = $this->createUnpublishedComment(['user_id' => $author->id]);

        Sanctum::actingAs($actor);

        $this->postJson("/api/comments/{$comment->id}/publish")->assertForbidden();
    }

    public function test_cannot_publish_already_published_comment(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $publisher = User::factory()->create(['level' => 4]);
        $comment = $this->createPublishedComment(['user_id' => $author->id]);

        Sanctum::actingAs($publisher);

        $this->postJson("/api/comments/{$comment->id}/publish")->assertForbidden();
    }

    #[DataProvider('allowedPublishProvider')]
    public function test_publish_allowed_for_level_two_and_above(
        int $actorLevel,
        int $authorLevel
    ): void {
        Notification::fake();

        $author = User::factory()->create(['level' => $authorLevel]);
        $actor = User::factory()->create(['level' => $actorLevel]);
        $question = $this->createPublishedQuestion();
        $comment = $this->createCommentOnQuestion($question, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($actor);

        $this->postJson("/api/comments/{$comment->id}/publish")->assertOk();
        $this->assertTrue($comment->fresh()->published);
    }

    public static function allowedPublishProvider(): array
    {
        return [
            'level 2 publishing level 5 author' => [2, 5],
            'level 3 publishing same level' => [3, 3],
            'level 4 publishing higher author' => [4, 10],
        ];
    }

    public function test_publish_returns_404_for_missing_comment(): void
    {
        Sanctum::actingAs(User::factory()->create(['level' => 5]));

        $this->postJson('/api/comments/999999/publish')->assertNotFound();
    }

    public function test_publish_is_idempotent_denied_after_first_success(): void
    {
        Notification::fake();

        $author = User::factory()->create(['level' => 1, 'score' => 0]);
        $publisher = User::factory()->create(['level' => 3, 'score' => 0]);
        $question = $this->createPublishedQuestion();
        $comment = $this->createCommentOnQuestion($question, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($publisher);

        $this->postJson("/api/comments/{$comment->id}/publish")->assertOk();
        $this->postJson("/api/comments/{$comment->id}/publish")->assertForbidden();

        $this->assertEquals(2, $publisher->fresh()->score);
        $this->assertEquals(2, $author->fresh()->score);
    }
}
