<?php

namespace Tests\Feature\Comment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithComments;
use Tests\TestCase;

class CommentDestroyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithComments;

    public function test_owner_can_delete_unpublished_comment(): void
    {
        $owner = User::factory()->create();
        $comment = $this->createUnpublishedComment(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/comments/{$comment->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }

    public function test_guest_cannot_delete_comment(): void
    {
        $comment = $this->createUnpublishedComment();

        $this->deleteJson("/api/comments/{$comment->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
    }

    public function test_non_owner_cannot_delete_unpublished_comment_even_as_admin(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $admin = User::factory()->admin()->create(['level' => 5]);
        $comment = $this->createUnpublishedComment(['user_id' => $owner->id]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/comments/{$comment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
    }

    public function test_owner_cannot_delete_published_comment(): void
    {
        $owner = User::factory()->create(['level' => 3]);
        $comment = $this->createPublishedComment(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/comments/{$comment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
    }

    public function test_delete_returns_404_for_missing_comment(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/comments/999999')->assertNotFound();
    }

    public function test_idor_attacker_cannot_delete_victim_draft(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create(['level' => 5]);
        $comment = $this->createUnpublishedComment(['user_id' => $victim->id]);

        Sanctum::actingAs($attacker);

        $this->deleteJson("/api/comments/{$comment->id}")->assertForbidden();
        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
    }

    public function test_deleting_comment_hard_deletes_row(): void
    {
        // Comment model does not use SoftDeletes — hard delete only.
        $owner = User::factory()->create();
        $comment = $this->createUnpublishedComment(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/comments/{$comment->id}")->assertNoContent();

        $this->assertDatabaseCount('comments', 0);
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }
}
