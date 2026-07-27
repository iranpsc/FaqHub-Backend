<?php

namespace Tests\Feature\Question;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithQuestions;
use Tests\TestCase;

class QuestionDestroyTest extends TestCase
{
    use InteractsWithQuestions;
    use RefreshDatabase;

    public function test_owner_can_delete_unpublished_question(): void
    {
        $owner = User::factory()->create();
        $question = $this->createUnpublishedQuestion(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/questions/{$question->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
    }

    public function test_guest_cannot_delete_question(): void
    {
        $question = $this->createUnpublishedQuestion();

        $this->deleteJson("/api/questions/{$question->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('questions', ['id' => $question->id]);
    }

    public function test_non_owner_cannot_delete_unpublished_question_even_as_admin(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $admin = User::factory()->admin()->create(['level' => 5]);
        $question = $this->createUnpublishedQuestion(['user_id' => $owner->id]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/questions/{$question->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('questions', ['id' => $question->id]);
    }

    public function test_owner_cannot_delete_published_question(): void
    {
        $owner = User::factory()->create(['level' => 3]);
        $question = $this->createPublishedQuestion(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/questions/{$question->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('questions', ['id' => $question->id]);
    }

    public function test_delete_returns_404_for_missing_question(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/questions/999999')->assertNotFound();
    }

    public function test_idor_attacker_cannot_delete_victim_draft(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create(['level' => 5]);
        $question = $this->createUnpublishedQuestion(['user_id' => $victim->id]);

        Sanctum::actingAs($attacker);

        $this->deleteJson("/api/questions/{$question->id}")->assertForbidden();
        $this->assertDatabaseHas('questions', ['id' => $question->id]);
    }
}
