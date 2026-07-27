<?php

namespace Tests\Feature\Answer;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAnswers;
use Tests\TestCase;

class AnswerDestroyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAnswers;

    public function test_owner_can_delete_unpublished_answer(): void
    {
        $owner = User::factory()->create();
        $answer = $this->createUnpublishedAnswer(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/answers/{$answer->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('answers', ['id' => $answer->id]);
    }

    public function test_guest_cannot_delete_answer(): void
    {
        $answer = $this->createUnpublishedAnswer();

        $this->deleteJson("/api/answers/{$answer->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('answers', ['id' => $answer->id]);
    }

    public function test_non_owner_cannot_delete_unpublished_answer_even_as_admin(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $admin = User::factory()->admin()->create(['level' => 5]);
        $answer = $this->createUnpublishedAnswer(['user_id' => $owner->id]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/answers/{$answer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('answers', ['id' => $answer->id]);
    }

    public function test_owner_cannot_delete_published_answer(): void
    {
        $owner = User::factory()->create(['level' => 3]);
        $answer = $this->createPublishedAnswer(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/answers/{$answer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('answers', ['id' => $answer->id]);
    }

    public function test_delete_returns_404_for_missing_answer(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/answers/999999')->assertNotFound();
    }

    public function test_idor_attacker_cannot_delete_victim_draft(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create(['level' => 5]);
        $answer = $this->createUnpublishedAnswer(['user_id' => $victim->id]);

        Sanctum::actingAs($attacker);

        $this->deleteJson("/api/answers/{$answer->id}")->assertForbidden();
        $this->assertDatabaseHas('answers', ['id' => $answer->id]);
    }
}
