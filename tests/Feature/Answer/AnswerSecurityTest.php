<?php

namespace Tests\Feature\Answer;

use App\Models\Answer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAnswers;
use Tests\TestCase;

class AnswerSecurityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAnswers;

    public function test_mass_assignment_cannot_override_user_id_or_published_on_store(): void
    {
        $attacker = $this->actingAsLevel(1);
        $victim = User::factory()->create();
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/answers", $this->makeAnswerPayload([
            'user_id' => $victim->id,
            'published' => true,
            'is_correct' => true,
            'published_by' => $attacker->id,
        ]))->assertCreated();

        $answer = Answer::first();

        $this->assertEquals($attacker->id, $answer->user_id);
        $this->assertFalse($answer->published);
        $this->assertFalse($answer->is_correct);
        $this->assertNull($answer->published_by);
    }

    public function test_nested_user_resource_hides_email_and_mobile_from_other_users(): void
    {
        $author = User::factory()->create([
            'email' => 'author@example.com',
            'mobile' => '09120000000',
        ]);
        $viewer = User::factory()->create();
        $question = $this->createPublishedQuestion();
        $this->createAnswerForQuestion($question, ['user_id' => $author->id]);

        Sanctum::actingAs($viewer);

        $userPayload = $this->getJson("/api/questions/{$question->id}/answers")
            ->assertOk()
            ->json('data.0.user');

        $this->assertArrayNotHasKey('email', $userPayload);
        $this->assertArrayNotHasKey('mobile', $userPayload);
        $this->assertArrayNotHasKey('access_token', $userPayload);
        $this->assertArrayNotHasKey('refresh_token', $userPayload);
    }

    public function test_author_seeing_own_answer_receives_email_in_user_resource(): void
    {
        $author = User::factory()->create(['email' => 'me@example.com']);
        $question = $this->createPublishedQuestion();
        $this->createAnswerForQuestion($question, ['user_id' => $author->id]);

        Sanctum::actingAs($author);

        $this->getJson("/api/questions/{$question->id}/answers")
            ->assertOk()
            ->assertJsonPath('data.0.user.email', 'me@example.com');
    }

    public function test_sql_injection_in_sort_param_does_not_break_index(): void
    {
        $question = $this->createPublishedQuestion();
        $this->createAnswerForQuestion($question);

        $this->getJson("/api/questions/{$question->id}/answers?sort=votes%3BDROP+TABLE+answers")
            ->assertOk();

        $this->assertDatabaseCount('answers', 1);
    }

    public function test_xss_payload_is_stripped_on_update_but_not_on_store(): void
    {
        $user = $this->actingAsLevel(1);
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/answers", [
            'content' => '<img src=x onerror=alert(1)>Hello',
        ])->assertCreated();

        $stored = Answer::first();
        $this->assertStringContainsString('onerror', $stored->content);

        Sanctum::actingAs($user);
        $this->putJson("/api/answers/{$stored->id}", [
            'content' => '<img src=x onerror=alert(1)>Hello',
        ])->assertOk();

        $updated = $stored->fresh()->content;
        $this->assertStringNotContainsString('onerror', $updated);
        $this->assertStringContainsString('Hello', $updated);
    }

    public function test_can_permissions_in_resource_reflect_policy_for_authenticated_user(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $publisher = User::factory()->create(['level' => 4]);
        $question = $this->createPublishedQuestion();
        $draft = $this->createAnswerForQuestion($question, [
            'user_id' => $owner->id,
        ], published: false);

        Sanctum::actingAs($owner);
        $this->getJson("/api/questions/{$question->id}/answers")
            ->assertOk()
            ->assertJsonPath('data.0.can.update', true)
            ->assertJsonPath('data.0.can.delete', true)
            ->assertJsonPath('data.0.can.publish', false)
            ->assertJsonPath('data.0.can.toggle_correctness', false);

        Sanctum::actingAs($publisher);
        $this->getJson("/api/questions/{$question->id}/answers")
            ->assertOk()
            ->assertJsonPath('data.0.id', $draft->id)
            ->assertJsonPath('data.0.can.update', false)
            ->assertJsonPath('data.0.can.delete', false)
            ->assertJsonPath('data.0.can.publish', true);
    }

    public function test_idor_cannot_publish_victims_answer_without_level_privilege(): void
    {
        $victim = User::factory()->create(['level' => 3]);
        $attacker = User::factory()->create(['level' => 3]);
        $answer = $this->createUnpublishedAnswer(['user_id' => $victim->id]);

        Sanctum::actingAs($attacker);

        $this->postJson("/api/answers/{$answer->id}/publish")->assertForbidden();
        $this->assertFalse($answer->fresh()->published);
    }

    public function test_idor_cannot_toggle_correctness_on_higher_level_users_answer(): void
    {
        $victim = User::factory()->create(['level' => 5]);
        $attacker = User::factory()->create(['level' => 4]);
        $answer = $this->createPublishedAnswer([
            'user_id' => $victim->id,
            'is_correct' => false,
        ]);

        Sanctum::actingAs($attacker);

        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")->assertForbidden();
        $this->assertFalse($answer->fresh()->is_correct);
    }

    public function test_admin_flag_does_not_bypass_update_or_delete_policy(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $admin = User::factory()->admin()->create(['level' => 5]);
        $answer = $this->createUnpublishedAnswer([
            'user_id' => $owner->id,
            'content' => 'Protected draft',
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/answers/{$answer->id}", ['content' => 'Admin edit'])
            ->assertForbidden();
        $this->deleteJson("/api/answers/{$answer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('answers', [
            'id' => $answer->id,
            'content' => 'Protected draft',
        ]);
    }
}
