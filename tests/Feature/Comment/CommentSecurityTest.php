<?php

namespace Tests\Feature\Comment;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithComments;
use Tests\TestCase;

class CommentSecurityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithComments;

    public function test_mass_assignment_cannot_override_user_id_or_published_on_store(): void
    {
        $attacker = $this->actingAsLevel(1);
        $victim = User::factory()->create();
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/comments", $this->makeCommentPayload([
            'user_id' => $victim->id,
            'published' => true,
            'published_by' => $attacker->id,
        ]))->assertCreated();

        $comment = Comment::first();

        $this->assertEquals($attacker->id, $comment->user_id);
        $this->assertFalse($comment->published);
        $this->assertNull($comment->published_by);
    }

    public function test_nested_user_resource_hides_email_and_mobile_from_other_users(): void
    {
        $author = User::factory()->create([
            'email' => 'author@example.com',
            'mobile' => '09120000000',
        ]);
        $viewer = User::factory()->create();
        $question = $this->createPublishedQuestion();
        $this->createCommentOnQuestion($question, ['user_id' => $author->id]);

        Sanctum::actingAs($viewer);

        $userPayload = $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->json('data.0.user');

        $this->assertArrayNotHasKey('email', $userPayload);
        $this->assertArrayNotHasKey('mobile', $userPayload);
        $this->assertArrayNotHasKey('access_token', $userPayload);
        $this->assertArrayNotHasKey('refresh_token', $userPayload);
    }

    public function test_author_seeing_own_comment_receives_email_in_user_resource(): void
    {
        $author = User::factory()->create(['email' => 'me@example.com']);
        $question = $this->createPublishedQuestion();
        $this->createCommentOnQuestion($question, ['user_id' => $author->id]);

        Sanctum::actingAs($author);

        $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.user.email', 'me@example.com');
    }

    public function test_sql_injection_in_parent_id_does_not_break_index(): void
    {
        $question = $this->createPublishedQuestion();
        $this->createCommentOnQuestion($question);

        $this->getJson("/api/questions/1%20OR%201=1/comments")
            ->assertNotFound();

        $this->assertDatabaseCount('comments', 1);
    }

    public function test_xss_payload_is_stripped_on_store_and_escaped_on_update(): void
    {
        $user = $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/comments", [
            'content' => '<img src=x onerror=alert(1)>Hello world',
        ])->assertCreated();

        $stored = Comment::first();
        $this->assertStringNotContainsString('<img', $stored->content);
        $this->assertStringNotContainsString('onerror', $stored->content);
        $this->assertStringContainsString('Hello world', $stored->content);

        Sanctum::actingAs($user);
        $this->putJson("/api/comments/{$stored->id}", [
            'content' => 'A & B <script>',
        ])->assertOk();

        $updated = $stored->fresh()->content;
        $this->assertStringNotContainsString('<script>', $updated);
        $this->assertStringContainsString('&amp;', $updated);
    }

    public function test_can_permissions_in_resource_reflect_policy_for_authenticated_user(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $publisher = User::factory()->create(['level' => 2]);
        $question = $this->createPublishedQuestion();
        $draft = $this->createCommentOnQuestion($question, [
            'user_id' => $owner->id,
        ], published: false);

        Sanctum::actingAs($owner);
        $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.can.update', true)
            ->assertJsonPath('data.0.can.delete', true)
            ->assertJsonPath('data.0.can.publish', false);

        Sanctum::actingAs($publisher);
        $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.id', $draft->id)
            ->assertJsonPath('data.0.can.update', false)
            ->assertJsonPath('data.0.can.delete', false)
            ->assertJsonPath('data.0.can.publish', true);
    }

    public function test_idor_cannot_update_or_delete_victims_comment_by_guessing_id(): void
    {
        $victim = User::factory()->create(['level' => 1]);
        $attacker = User::factory()->create(['level' => 5]);
        $comment = $this->createUnpublishedComment([
            'user_id' => $victim->id,
            'content' => 'Secret draft comment',
        ]);

        Sanctum::actingAs($attacker);

        $this->putJson("/api/comments/{$comment->id}", [
            'content' => 'Overwrite attempt here',
        ])->assertForbidden();

        $this->deleteJson("/api/comments/{$comment->id}")->assertForbidden();

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'content' => 'Secret draft comment',
            'user_id' => $victim->id,
        ]);
    }

    public function test_idor_level_one_cannot_publish_any_comment(): void
    {
        $victim = User::factory()->create(['level' => 1]);
        $attacker = User::factory()->create(['level' => 1]);
        $comment = $this->createUnpublishedComment(['user_id' => $victim->id]);

        Sanctum::actingAs($attacker);

        $this->postJson("/api/comments/{$comment->id}/publish")->assertForbidden();
        $this->assertFalse($comment->fresh()->published);
    }

    public function test_admin_flag_does_not_bypass_update_or_delete_policy(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $admin = User::factory()->admin()->create(['level' => 5]);
        $comment = $this->createUnpublishedComment([
            'user_id' => $owner->id,
            'content' => 'Protected draft text',
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/comments/{$comment->id}", ['content' => 'Admin edit attempt'])
            ->assertForbidden();
        $this->deleteJson("/api/comments/{$comment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'content' => 'Protected draft text',
        ]);
    }

    public function test_sensitive_tokens_are_not_exposed_in_comment_json(): void
    {
        $author = User::factory()->create([
            'access_token' => 'secret-access',
            'refresh_token' => 'secret-refresh',
        ]);
        $question = $this->createPublishedQuestion();
        $this->createCommentOnQuestion($question, ['user_id' => $author->id]);

        $payload = $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->json();

        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('secret-access', $encoded);
        $this->assertStringNotContainsString('secret-refresh', $encoded);
    }
}
