<?php

namespace Tests\Feature\Comment;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithComments;
use Tests\TestCase;

class CommentStoreTest extends TestCase
{
    use InteractsWithComments;
    use RefreshDatabase;

    public function test_guest_cannot_create_comment(): void
    {
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/comments", $this->makeCommentPayload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('comments', 0);
    }

    public function test_authenticated_user_can_create_unpublished_comment_on_question(): void
    {
        $user = $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();
        $payload = $this->makeCommentPayload();

        $response = $this->postJson("/api/questions/{$question->id}/comments", $payload);

        $response->assertCreated()
            ->assertJsonPath('data.content', $payload['content'])
            ->assertJsonPath('message', 'نظر با موفقیت اضافه شد');

        // Eloquent create() does not hydrate DB column defaults onto the model,
        // so the JSON resource may expose null while the row stores false.
        $this->assertContains($response->json('data.published'), [false, null]);

        $this->assertDatabaseHas('comments', [
            'commentable_id' => $question->id,
            'commentable_type' => Question::class,
            'user_id' => $user->id,
            'content' => $payload['content'],
            'published' => false,
            'published_at' => null,
            'published_by' => null,
        ]);

        $comment = Comment::first();
        $this->assertEquals(0, $user->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'created_comment',
            'subject_type' => Comment::class,
            'subject_id' => $comment->id,
            'causer_id' => $user->id,
        ]);
    }

    public function test_authenticated_user_can_create_comment_on_answer(): void
    {
        $user = $this->actingAsLevel(1);
        $answer = Answer::factory()->published()->create();
        $payload = $this->makeCommentPayload(['content' => 'Comment on an answer body.']);

        $this->postJson("/api/answers/{$answer->id}/comments", $payload)
            ->assertCreated()
            ->assertJsonPath('data.content', $payload['content']);

        $this->assertDatabaseHas('comments', [
            'commentable_id' => $answer->id,
            'commentable_type' => Answer::class,
            'user_id' => $user->id,
            'content' => $payload['content'],
            'published' => false,
        ]);
    }

    public function test_level_two_or_higher_auto_publishes_comment_on_store(): void
    {
        // CommentPolicy::publish returns true for level >= 2 (including own comments).
        $user = $this->actingAsLevel(2, ['score' => 0]);
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/comments", $this->makeCommentPayload())
            ->assertCreated()
            ->assertJsonPath('data.published', true);

        $comment = Comment::first();

        $this->assertTrue($comment->published);
        $this->assertNotNull($comment->published_at);
        $this->assertEquals($user->id, $comment->published_by);
        // Auto-publish on store does not award score (unlike the publish endpoint).
        $this->assertEquals(0, $user->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'published_comment',
            'subject_type' => Comment::class,
            'subject_id' => $comment->id,
            'causer_id' => $user->id,
        ]);
    }

    public function test_store_does_not_award_score_on_create(): void
    {
        $user = $this->actingAsLevel(5, ['score' => 0]);
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/comments", $this->makeCommentPayload())
            ->assertCreated();

        $this->assertEquals(0, $user->fresh()->score);
        $this->assertTrue(Comment::first()->published);
    }

    public function test_store_returns_404_for_missing_question(): void
    {
        $this->actingAsLevel(1);

        $this->postJson('/api/questions/999999/comments', $this->makeCommentPayload())
            ->assertNotFound();
    }

    public function test_store_returns_404_for_missing_answer(): void
    {
        $this->actingAsLevel(1);

        $this->postJson('/api/answers/999999/comments', $this->makeCommentPayload())
            ->assertNotFound();
    }

    #[DataProvider('invalidStorePayloadProvider')]
    public function test_store_validation_rejects_invalid_payloads(array $payload, array $errorKeys): void
    {
        $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/comments", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKeys);

        $this->assertDatabaseCount('comments', 0);
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
            'boolean content coerced then empty/short' => [
                // strip_tags() coerces bool to "1", which fails required|string only if
                // treated as string — "1" is valid for store (no min). Use empty-after-strip instead.
                ['content' => false],
                ['content'],
            ],
            'content exceeding max length' => [
                ['content' => str_repeat('a', 20_001)],
                ['content'],
            ],
            'html-only content stripped to empty' => [
                ['content' => '<script></script><b></b>'],
                ['content'],
            ],
        ];
    }

    public function test_store_array_content_causes_strip_tags_type_error(): void
    {
        // Documents current bug: prepareForValidation calls strip_tags() without
        // checking that content is a string, so arrays yield HTTP 500 instead of 422.
        $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();

        $this->withoutExceptionHandling();
        $this->expectException(\TypeError::class);

        $this->postJson("/api/questions/{$question->id}/comments", [
            'content' => ['nested' => 'array'],
        ]);
    }

    public function test_store_accepts_content_at_max_length_boundary(): void
    {
        $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();
        $content = str_repeat('b', 20_000);

        $this->postJson("/api/questions/{$question->id}/comments", ['content' => $content])
            ->assertCreated();

        $this->assertSame(20_000, mb_strlen(Comment::first()->content));
    }

    public function test_store_accepts_short_content_without_min_length(): void
    {
        // StoreCommentRequest has no min rule (unlike UpdateCommentRequest min:5).
        $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/comments", ['content' => 'Hi'])
            ->assertCreated();

        $this->assertDatabaseHas('comments', ['content' => 'Hi']);
    }

    public function test_store_ignores_mass_assignment_of_privileged_fields(): void
    {
        $user = $this->actingAsLevel(1);
        $victim = User::factory()->create();
        $publisher = User::factory()->create();
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/comments", $this->makeCommentPayload([
            'published' => true,
            'published_at' => now()->toISOString(),
            'published_by' => $publisher->id,
            'user_id' => $victim->id,
            'commentable_id' => Question::factory()->create()->id,
            'commentable_type' => Answer::class,
        ]))->assertCreated();

        $comment = Comment::first();

        $this->assertFalse($comment->published);
        $this->assertNull($comment->published_at);
        $this->assertNull($comment->published_by);
        $this->assertEquals($user->id, $comment->user_id);
        $this->assertEquals($question->id, $comment->commentable_id);
        $this->assertEquals(Question::class, $comment->commentable_type);
    }

    public function test_store_strips_html_tags_via_prepare_for_validation(): void
    {
        // Store uses $request->content (after strip_tags), not validated()/escape().
        $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/comments", [
            'content' => '<p>Safe</p><script>alert(1)</script>',
        ])->assertCreated();

        $content = Comment::first()->content;
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringNotContainsString('<p>', $content);
        $this->assertStringContainsString('Safe', $content);
        $this->assertStringContainsString('alert(1)', $content);
    }

    public function test_multiple_users_can_comment_on_same_question(): void
    {
        $question = $this->createPublishedQuestion();
        $first = User::factory()->create(['level' => 1]);
        $second = User::factory()->create(['level' => 1]);

        Sanctum::actingAs($first);
        $this->postJson("/api/questions/{$question->id}/comments", $this->makeCommentPayload([
            'content' => 'First comment',
        ]))->assertCreated();

        Sanctum::actingAs($second);
        $this->postJson("/api/questions/{$question->id}/comments", $this->makeCommentPayload([
            'content' => 'Second comment',
        ]))->assertCreated();

        $this->assertDatabaseCount('comments', 2);
        $this->assertEquals(2, $question->comments()->count());
    }

    public function test_store_response_includes_loaded_user(): void
    {
        $user = $this->actingAsLevel(1, ['name' => 'Comment Author']);
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/comments", $this->makeCommentPayload())
            ->assertCreated()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.name', 'Comment Author');
    }
}
