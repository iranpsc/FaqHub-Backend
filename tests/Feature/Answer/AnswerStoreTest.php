<?php

namespace Tests\Feature\Answer;

use App\Models\Answer;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAnswers;
use Tests\TestCase;

class AnswerStoreTest extends TestCase
{
    use InteractsWithAnswers;
    use RefreshDatabase;

    public function test_guest_cannot_create_answer(): void
    {
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/answers", $this->makeAnswerPayload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('answers', 0);
    }

    public function test_authenticated_user_can_create_unpublished_answer(): void
    {
        $user = $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();
        $payload = $this->makeAnswerPayload();

        $response = $this->postJson("/api/questions/{$question->id}/answers", $payload);

        $response->assertCreated()
            ->assertJsonPath('data.content', $payload['content'])
            ->assertJsonPath('data.published', false)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.question_id', $question->id);

        $this->assertDatabaseHas('answers', [
            'question_id' => $question->id,
            'user_id' => $user->id,
            'content' => $payload['content'],
            'published' => false,
            'published_at' => null,
            'published_by' => null,
            'is_correct' => false,
        ]);

        $answer = Answer::first();
        // Create path does not set is_correct on the model instance; DB default is false.
        $this->assertFalse($answer->is_correct);
        $this->assertEquals(0, $user->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'created_answer',
            'subject_type' => Answer::class,
            'subject_id' => $answer->id,
            'causer_id' => $user->id,
        ]);
    }

    public function test_store_does_not_award_score_on_create(): void
    {
        $user = $this->actingAsLevel(5, ['score' => 0]);
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/answers", $this->makeAnswerPayload())
            ->assertCreated();

        $this->assertEquals(0, $user->fresh()->score);
        $this->assertFalse(Answer::first()->published);
    }

    public function test_store_returns_404_for_missing_question(): void
    {
        $this->actingAsLevel(1);

        $this->postJson('/api/questions/999999/answers', $this->makeAnswerPayload())
            ->assertNotFound();
    }

    #[DataProvider('invalidStorePayloadProvider')]
    public function test_store_validation_rejects_invalid_payloads(array $payload, array $errorKeys): void
    {
        $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/answers", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKeys);

        $this->assertDatabaseCount('answers', 0);
    }

    public static function invalidStorePayloadProvider(): array
    {
        return [
            'missing content' => [
                [],
                ['content'],
            ],
            'null content' => [
                ['content' => null],
                ['content'],
            ],
            'empty content' => [
                ['content' => ''],
                ['content'],
            ],
            'non-string content' => [
                ['content' => ['nested' => 'array']],
                ['content'],
            ],
            'content exceeding max length' => [
                ['content' => str_repeat('a', 5_000_001)],
                ['content'],
            ],
        ];
    }

    public function test_store_accepts_content_at_max_length_boundary(): void
    {
        $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();
        $content = str_repeat('b', 5_000_000);

        $this->postJson("/api/questions/{$question->id}/answers", ['content' => $content])
            ->assertCreated();

        $this->assertDatabaseHas('answers', [
            'question_id' => $question->id,
            'user_id' => auth()->id(),
        ]);
        $this->assertSame(5_000_000, mb_strlen(Answer::first()->content));
    }

    public function test_store_ignores_mass_assignment_of_privileged_fields(): void
    {
        $user = $this->actingAsLevel(1);
        $victim = User::factory()->create();
        $publisher = User::factory()->create();
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/answers", $this->makeAnswerPayload([
            'published' => true,
            'published_at' => now()->toISOString(),
            'published_by' => $publisher->id,
            'is_correct' => true,
            'user_id' => $victim->id,
            'question_id' => Question::factory()->create()->id,
        ]))->assertCreated();

        $answer = Answer::first();

        $this->assertFalse($answer->published);
        $this->assertNull($answer->published_at);
        $this->assertNull($answer->published_by);
        $this->assertFalse($answer->is_correct);
        $this->assertEquals($user->id, $answer->user_id);
        $this->assertEquals($question->id, $answer->question_id);
    }

    public function test_store_persists_script_tags_because_controller_skips_validated(): void
    {
        // Documents current behavior: StoreAnswerRequest::validated() sanitizes content,
        // but AnswerController reads $request->content directly.
        $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();
        $payload = $this->makeAnswerPayload([
            'content' => '<p>Safe</p><script>alert(1)</script>',
        ]);

        $this->postJson("/api/questions/{$question->id}/answers", $payload)->assertCreated();

        $this->assertStringContainsString('<script>', Answer::first()->content);
    }

    public function test_multiple_users_can_answer_same_question(): void
    {
        $question = $this->createPublishedQuestion();
        $first = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($first);
        $this->postJson("/api/questions/{$question->id}/answers", $this->makeAnswerPayload([
            'content' => 'First answer',
        ]))->assertCreated();

        Sanctum::actingAs($second);
        $this->postJson("/api/questions/{$question->id}/answers", $this->makeAnswerPayload([
            'content' => 'Second answer',
        ]))->assertCreated();

        $this->assertDatabaseCount('answers', 2);
        $this->assertEquals(2, $question->answers()->count());
    }
}
