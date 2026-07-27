<?php

namespace Tests\Feature\Answer;

use App\Models\Answer;
use App\Models\User;
use App\Notifications\QuestionInteractionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAnswers;
use Tests\TestCase;

class AnswerPublishTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAnswers;

    public function test_higher_level_user_can_publish_lower_level_users_answer_and_awards_scores(): void
    {
        Notification::fake();

        $questionOwner = User::factory()->create(['level' => 1]);
        $author = User::factory()->create(['level' => 1, 'score' => 0]);
        $publisher = User::factory()->create(['level' => 3, 'score' => 0]);
        $question = $this->createPublishedQuestion(['user_id' => $questionOwner->id]);
        $answer = $this->createAnswerForQuestion($question, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($publisher);

        $this->postJson("/api/answers/{$answer->id}/publish")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'پاسخ با موفقیت منتشر شد')
            ->assertJsonPath('data.published', true);

        $this->assertDatabaseHas('answers', [
            'id' => $answer->id,
            'published' => true,
            'published_by' => $publisher->id,
        ]);
        $this->assertNotNull($answer->fresh()->published_at);
        $this->assertEquals(3, $publisher->fresh()->score);
        $this->assertEquals(5, $author->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'published_answer',
            'subject_type' => Answer::class,
            'subject_id' => $answer->id,
            'causer_id' => $publisher->id,
        ]);

        Notification::assertSentTo(
            $questionOwner,
            QuestionInteractionNotification::class,
            function (QuestionInteractionNotification $notification) use ($publisher, $question) {
                return $notification->user->is($publisher)
                    && $notification->question->is($question)
                    && $notification->interactionType === 'answer';
            }
        );
    }

    public function test_publish_does_not_notify_when_question_has_no_owner(): void
    {
        Notification::fake();

        $author = User::factory()->create(['level' => 1]);
        $publisher = User::factory()->create(['level' => 4]);
        $question = $this->createPublishedQuestion();
        $question->forceFill(['user_id' => null])->saveQuietly();
        $answer = $this->createAnswerForQuestion($question, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($publisher);

        $this->postJson("/api/answers/{$answer->id}/publish")->assertOk();

        Notification::assertNothingSent();
    }

    public function test_guest_cannot_publish_answer(): void
    {
        $answer = $this->createUnpublishedAnswer();

        $this->postJson("/api/answers/{$answer->id}/publish")
            ->assertUnauthorized();

        $this->assertFalse($answer->fresh()->published);
    }

    public function test_owner_cannot_publish_own_answer(): void
    {
        $owner = User::factory()->create(['level' => 5, 'score' => 0]);
        $answer = $this->createUnpublishedAnswer(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/answers/{$answer->id}/publish")->assertForbidden();

        $this->assertFalse($answer->fresh()->published);
        $this->assertEquals(0, $owner->fresh()->score);
    }

    public function test_level_two_or_below_cannot_publish_any_answer(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 2]);
        $answer = $this->createUnpublishedAnswer(['user_id' => $author->id]);

        Sanctum::actingAs($actor);

        $this->postJson("/api/answers/{$answer->id}/publish")->assertForbidden();
    }

    public function test_cannot_publish_already_published_answer(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $publisher = User::factory()->create(['level' => 4]);
        $answer = $this->createPublishedAnswer(['user_id' => $author->id]);

        Sanctum::actingAs($publisher);

        $this->postJson("/api/answers/{$answer->id}/publish")->assertForbidden();
    }

    #[DataProvider('forbiddenPublishProvider')]
    public function test_cannot_publish_answer_from_same_or_higher_level(
        int $actorLevel,
        int $authorLevel
    ): void {
        $author = User::factory()->create(['level' => $authorLevel]);
        $actor = User::factory()->create(['level' => $actorLevel]);
        $answer = $this->createUnpublishedAnswer(['user_id' => $author->id]);

        Sanctum::actingAs($actor);

        $this->postJson("/api/answers/{$answer->id}/publish")->assertForbidden();
    }

    public static function forbiddenPublishProvider(): array
    {
        return [
            'same level' => [3, 3],
            'lower than author' => [3, 4],
        ];
    }

    public function test_publish_returns_404_for_missing_answer(): void
    {
        Sanctum::actingAs(User::factory()->create(['level' => 5]));

        $this->postJson('/api/answers/999999/publish')->assertNotFound();
    }
}
