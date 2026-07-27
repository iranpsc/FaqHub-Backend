<?php

namespace Tests\Feature\Comment;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithComments;
use Tests\TestCase;

class CommentVoteTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithComments;

    public function test_authenticated_user_can_upvote_comment(): void
    {
        $owner = User::factory()->create(['score' => 0]);
        $voter = User::factory()->create();
        $comment = $this->createPublishedComment(['user_id' => $owner->id]);

        Sanctum::actingAs($voter);

        $this->postJson("/api/comments/{$comment->id}/vote", ['type' => 'up'])
            ->assertOk()
            ->assertJson([
                'upvotes' => 1,
                'downvotes' => 0,
                'user_vote' => 'up',
            ]);

        $this->assertDatabaseHas('votes', [
            'votable_type' => Comment::class,
            'votable_id' => $comment->id,
            'user_id' => $voter->id,
            'type' => 'up',
        ]);
        // Comment votes do not adjust owner score (unlike answers).
        $this->assertEquals(0, $owner->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'voted',
            'causer_id' => $voter->id,
            'subject_type' => Comment::class,
            'subject_id' => $comment->id,
        ]);
    }

    public function test_authenticated_user_can_downvote_comment(): void
    {
        $owner = User::factory()->create(['score' => 10]);
        $voter = User::factory()->create();
        $comment = $this->createPublishedComment(['user_id' => $owner->id]);

        Sanctum::actingAs($voter);

        $this->postJson("/api/comments/{$comment->id}/vote", ['type' => 'down'])
            ->assertOk()
            ->assertJson([
                'upvotes' => 0,
                'downvotes' => 1,
                'user_vote' => 'down',
            ]);

        $this->assertEquals(10, $owner->fresh()->score);
    }

    public function test_user_cannot_vote_twice_or_change_vote_type(): void
    {
        $owner = User::factory()->create(['score' => 0]);
        $voter = User::factory()->create();
        $comment = $this->createPublishedComment(['user_id' => $owner->id]);
        $comment->votes()->create(['user_id' => $voter->id, 'type' => 'up']);

        Sanctum::actingAs($voter);

        $this->postJson("/api/comments/{$comment->id}/vote", ['type' => 'up'])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'شما قبلا به این مورد رای داده‌اید')
            ->assertJsonPath('user_vote', 'up');

        $this->postJson("/api/comments/{$comment->id}/vote", ['type' => 'down'])
            ->assertStatus(409)
            ->assertJsonPath('user_vote', 'up');

        $this->assertEquals(1, $comment->votes()->count());
    }

    public function test_guest_cannot_vote(): void
    {
        $comment = $this->createPublishedComment();

        $this->postJson("/api/comments/{$comment->id}/vote", ['type' => 'up'])
            ->assertUnauthorized();
    }

    public function test_user_can_vote_on_own_comment(): void
    {
        // Vote endpoint has no ownership policy — only Sanctum auth.
        $owner = User::factory()->create(['score' => 0]);
        $comment = $this->createPublishedComment(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/comments/{$comment->id}/vote", ['type' => 'up'])
            ->assertOk()
            ->assertJsonPath('user_vote', 'up');
    }

    public function test_user_can_vote_on_unpublished_comment(): void
    {
        $voter = User::factory()->create();
        $comment = $this->createUnpublishedComment();

        Sanctum::actingAs($voter);

        $this->postJson("/api/comments/{$comment->id}/vote", ['type' => 'down'])
            ->assertOk()
            ->assertJsonPath('user_vote', 'down');
    }

    #[DataProvider('invalidVoteProvider')]
    public function test_vote_validation(array $payload, array $errors): void
    {
        Sanctum::actingAs(User::factory()->create());
        $comment = $this->createPublishedComment();

        $this->postJson("/api/comments/{$comment->id}/vote", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);
    }

    public static function invalidVoteProvider(): array
    {
        return [
            'missing type' => [[], ['type']],
            'invalid type' => [['type' => 'sideways'], ['type']],
            'null type' => [['type' => null], ['type']],
            'empty type' => [['type' => ''], ['type']],
            'numeric type' => [['type' => 1], ['type']],
            'array type' => [['type' => ['up']], ['type']],
        ];
    }

    public function test_vote_returns_404_for_missing_comment(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/comments/999999/vote', ['type' => 'up'])
            ->assertNotFound();
    }

    public function test_different_users_can_vote_on_same_comment(): void
    {
        $comment = $this->createPublishedComment();
        $voterA = User::factory()->create();
        $voterB = User::factory()->create();

        Sanctum::actingAs($voterA);
        $this->postJson("/api/comments/{$comment->id}/vote", ['type' => 'up'])->assertOk();

        Sanctum::actingAs($voterB);
        $this->postJson("/api/comments/{$comment->id}/vote", ['type' => 'down'])
            ->assertOk()
            ->assertJson([
                'upvotes' => 1,
                'downvotes' => 1,
                'user_vote' => 'down',
            ]);

        $this->assertEquals(2, $comment->votes()->count());
    }
}
