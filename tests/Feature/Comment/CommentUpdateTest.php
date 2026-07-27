<?php

namespace Tests\Feature\Comment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithComments;
use Tests\TestCase;

class CommentUpdateTest extends TestCase
{
    use InteractsWithComments;
    use RefreshDatabase;

    public function test_owner_can_update_unpublished_comment(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $comment = $this->createUnpublishedComment([
            'user_id' => $owner->id,
            'content' => 'Old content here',
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/comments/{$comment->id}", [
            'content' => 'Updated comment content',
        ])
            ->assertOk()
            ->assertJsonPath('data.content', 'Updated comment content')
            ->assertJsonPath('data.id', $comment->id);

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'content' => 'Updated comment content',
            'user_id' => $owner->id,
            'published' => false,
        ]);
    }

    public function test_guest_cannot_update_comment(): void
    {
        $comment = $this->createUnpublishedComment();

        $this->putJson("/api/comments/{$comment->id}", $this->makeCommentPayload())
            ->assertUnauthorized();
    }

    public function test_non_owner_cannot_update_unpublished_comment(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $intruder = User::factory()->create(['level' => 5]);
        $comment = $this->createUnpublishedComment([
            'user_id' => $owner->id,
            'content' => 'Original content',
        ]);

        Sanctum::actingAs($intruder);

        $this->putJson("/api/comments/{$comment->id}", [
            'content' => 'Hijacked content here',
        ])->assertForbidden();

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'content' => 'Original content',
        ]);
    }

    public function test_owner_cannot_update_published_comment(): void
    {
        $owner = User::factory()->create(['level' => 3]);
        $comment = $this->createPublishedComment([
            'user_id' => $owner->id,
            'content' => 'Published body text',
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/comments/{$comment->id}", [
            'content' => 'Trying to edit published',
        ])->assertForbidden();

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'content' => 'Published body text',
        ]);
    }

    public function test_update_returns_404_for_missing_comment(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/comments/999999', $this->makeCommentPayload())
            ->assertNotFound();
    }

    #[DataProvider('invalidUpdatePayloadProvider')]
    public function test_update_validation_rejects_invalid_payloads(array $payload, array $errorKeys): void
    {
        $owner = User::factory()->create();
        $comment = $this->createUnpublishedComment(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/comments/{$comment->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKeys);
    }

    public static function invalidUpdatePayloadProvider(): array
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
            'below min length' => [
                ['content' => 'abcd'],
                ['content'],
            ],
            'boolean content coerced below min' => [
                // strip_tags() coerces true to "1", which fails min:5.
                ['content' => true],
                ['content'],
            ],
            'content exceeding max length' => [
                ['content' => str_repeat('x', 20_001)],
                ['content'],
            ],
            'html-only content stripped below min' => [
                ['content' => '<b>ab</b>'],
                ['content'],
            ],
        ];
    }

    public function test_update_array_content_causes_strip_tags_type_error(): void
    {
        // Documents current bug: prepareForValidation calls strip_tags() without
        // an is_string guard, so array payloads yield HTTP 500 instead of 422.
        $owner = User::factory()->create();
        $comment = $this->createUnpublishedComment(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->withoutExceptionHandling();
        $this->expectException(\TypeError::class);

        $this->putJson("/api/comments/{$comment->id}", [
            'content' => ['nested' => 'array'],
        ]);
    }

    public function test_update_accepts_content_at_min_and_max_boundaries(): void
    {
        $owner = User::factory()->create();
        $comment = $this->createUnpublishedComment(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/comments/{$comment->id}", ['content' => 'abcde'])
            ->assertOk();

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'content' => 'abcde',
        ]);

        $max = str_repeat('y', 20_000);
        $this->putJson("/api/comments/{$comment->id}", ['content' => $max])
            ->assertOk();

        $this->assertSame(20_000, mb_strlen($comment->fresh()->content));
    }

    public function test_update_sanitizes_and_escapes_html_via_validated(): void
    {
        $owner = User::factory()->create();
        $comment = $this->createUnpublishedComment(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/comments/{$comment->id}", [
            'content' => '<p>Safe</p><script>alert(1)</script>',
        ])->assertOk();

        $content = $comment->fresh()->content;
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringNotContainsString('<p>', $content);
        $this->assertStringContainsString('Safe', $content);
        // HtmlSanitizer::escape runs on validated() for updates.
        $this->assertStringContainsString('alert(1)', $content);
    }

    public function test_update_escapes_special_characters(): void
    {
        $owner = User::factory()->create();
        $comment = $this->createUnpublishedComment(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/comments/{$comment->id}", [
            'content' => 'A & B < C > D "quoted"',
        ])->assertOk();

        $this->assertSame(
            'A &amp; B &lt; C &gt; D &quot;quoted&quot;',
            $comment->fresh()->content
        );
    }

    public function test_update_ignores_mass_assignment_of_privileged_fields(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $comment = $this->createUnpublishedComment(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/comments/{$comment->id}", [
            'content' => 'Only content should change',
            'published' => true,
            'user_id' => $other->id,
            'published_by' => $other->id,
            'published_at' => now()->toISOString(),
        ])->assertOk();

        $comment->refresh();

        $this->assertSame('Only content should change', $comment->content);
        $this->assertFalse($comment->published);
        $this->assertEquals($owner->id, $comment->user_id);
        $this->assertNull($comment->published_by);
        $this->assertNull($comment->published_at);
    }

    public function test_idor_attacker_cannot_update_another_users_draft_by_id(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create(['level' => 5]);
        $comment = $this->createUnpublishedComment([
            'user_id' => $victim->id,
            'content' => 'Victim draft text',
        ]);

        Sanctum::actingAs($attacker);

        $this->putJson("/api/comments/{$comment->id}", [
            'content' => 'Hijacked content text',
        ])->assertForbidden();

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'content' => 'Victim draft text',
            'user_id' => $victim->id,
        ]);
    }
}
