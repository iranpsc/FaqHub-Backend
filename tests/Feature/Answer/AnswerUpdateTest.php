<?php

namespace Tests\Feature\Answer;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAnswers;
use Tests\TestCase;

class AnswerUpdateTest extends TestCase
{
    use InteractsWithAnswers;
    use RefreshDatabase;

    public function test_owner_can_update_unpublished_answer(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $answer = $this->createUnpublishedAnswer([
            'user_id' => $owner->id,
            'content' => 'Old content',
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/answers/{$answer->id}", [
            'content' => 'Updated answer content',
        ])
            ->assertOk()
            ->assertJsonPath('data.content', 'Updated answer content')
            ->assertJsonPath('data.id', $answer->id);

        $this->assertDatabaseHas('answers', [
            'id' => $answer->id,
            'content' => 'Updated answer content',
            'user_id' => $owner->id,
            'published' => false,
        ]);
    }

    public function test_guest_cannot_update_answer(): void
    {
        $answer = $this->createUnpublishedAnswer();

        $this->putJson("/api/answers/{$answer->id}", $this->makeAnswerPayload())
            ->assertUnauthorized();
    }

    public function test_non_owner_cannot_update_unpublished_answer(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $intruder = User::factory()->create(['level' => 5]);
        $answer = $this->createUnpublishedAnswer([
            'user_id' => $owner->id,
            'content' => 'Original',
        ]);

        Sanctum::actingAs($intruder);

        $this->putJson("/api/answers/{$answer->id}", [
            'content' => 'Hijacked',
        ])->assertForbidden();

        $this->assertDatabaseHas('answers', [
            'id' => $answer->id,
            'content' => 'Original',
        ]);
    }

    public function test_owner_cannot_update_published_answer(): void
    {
        $owner = User::factory()->create(['level' => 3]);
        $answer = $this->createPublishedAnswer([
            'user_id' => $owner->id,
            'content' => 'Published body',
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/answers/{$answer->id}", [
            'content' => 'Trying to edit',
        ])->assertForbidden();

        $this->assertDatabaseHas('answers', [
            'id' => $answer->id,
            'content' => 'Published body',
        ]);
    }

    public function test_update_returns_404_for_missing_answer(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/answers/999999', $this->makeAnswerPayload())
            ->assertNotFound();
    }

    #[DataProvider('invalidUpdatePayloadProvider')]
    public function test_update_validation_rejects_invalid_payloads(array $payload, array $errorKeys): void
    {
        $owner = User::factory()->create();
        $answer = $this->createUnpublishedAnswer(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/answers/{$answer->id}", $payload)
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
            'non-string content' => [
                ['content' => 12345],
                ['content'],
            ],
            'content exceeding max length' => [
                ['content' => str_repeat('x', 5_000_001)],
                ['content'],
            ],
        ];
    }

    public function test_update_sanitizes_html_via_validated(): void
    {
        $owner = User::factory()->create();
        $answer = $this->createUnpublishedAnswer(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/answers/{$answer->id}", [
            'content' => '<p>Safe</p><script>alert(1)</script>',
        ])->assertOk();

        $content = $answer->fresh()->content;
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringContainsString('Safe', $content);
    }

    public function test_update_ignores_mass_assignment_of_privileged_fields(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $answer = $this->createUnpublishedAnswer(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/answers/{$answer->id}", [
            'content' => 'Only content should change',
            'published' => true,
            'is_correct' => true,
            'user_id' => $other->id,
            'published_by' => $other->id,
        ])->assertOk();

        $answer->refresh();

        $this->assertSame('Only content should change', $answer->content);
        $this->assertFalse($answer->published);
        $this->assertFalse($answer->is_correct);
        $this->assertEquals($owner->id, $answer->user_id);
        $this->assertNull($answer->published_by);
    }

    public function test_idor_attacker_cannot_update_another_users_draft_by_id(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create(['level' => 5]);
        $answer = $this->createUnpublishedAnswer([
            'user_id' => $victim->id,
            'content' => 'Victim draft',
        ]);

        Sanctum::actingAs($attacker);

        $this->putJson("/api/answers/{$answer->id}", [
            'content' => 'Hijacked',
        ])->assertForbidden();

        $this->assertDatabaseHas('answers', [
            'id' => $answer->id,
            'content' => 'Victim draft',
            'user_id' => $victim->id,
        ]);
    }
}
