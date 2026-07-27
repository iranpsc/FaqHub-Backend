<?php

namespace Tests\Feature\Question;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithQuestions;
use Tests\TestCase;

class QuestionPinTest extends TestCase
{
    use InteractsWithQuestions;
    use RefreshDatabase;

    public function test_authenticated_user_can_pin_question(): void
    {
        $user = User::factory()->create();
        $question = $this->createPublishedQuestion();

        Sanctum::actingAs($user);

        $this->postJson("/api/questions/{$question->id}/pin")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_pinned_by_user', true)
            ->assertJsonStructure(['message', 'pinned_at']);

        $this->assertDatabaseHas('user_pinned_questions', [
            'user_id' => $user->id,
            'question_id' => $question->id,
        ]);
    }

    public function test_pinning_same_question_twice_returns_conflict(): void
    {
        $user = User::factory()->create();
        $question = $this->createPublishedQuestion();
        $user->pinnedQuestions()->attach($question->id, ['pinned_at' => now()]);

        Sanctum::actingAs($user);

        $this->postJson("/api/questions/{$question->id}/pin")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertEquals(1, $user->pinnedQuestions()->count());
    }

    public function test_authenticated_user_can_unpin_question(): void
    {
        $user = User::factory()->create();
        $question = $this->createPublishedQuestion();
        $user->pinnedQuestions()->attach($question->id, ['pinned_at' => now()]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/questions/{$question->id}/pin")
            ->assertOk()
            ->assertJson([
                'success' => true,
                'is_pinned_by_user' => false,
                'pinned_at' => null,
            ]);

        $this->assertDatabaseMissing('user_pinned_questions', [
            'user_id' => $user->id,
            'question_id' => $question->id,
        ]);
    }

    public function test_unpin_is_idempotent_when_not_pinned(): void
    {
        $user = User::factory()->create();
        $question = $this->createPublishedQuestion();

        Sanctum::actingAs($user);

        $this->deleteJson("/api/questions/{$question->id}/pin")
            ->assertOk()
            ->assertJsonPath('is_pinned_by_user', false);
    }

    public function test_guest_cannot_pin_or_unpin(): void
    {
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/pin")->assertUnauthorized();
        $this->deleteJson("/api/questions/{$question->id}/pin")->assertUnauthorized();
    }

    public function test_pinned_questions_appear_first_on_unfiltered_index(): void
    {
        $user = User::factory()->create();
        $older = $this->createPublishedQuestion(['created_at' => now()->subDay()]);
        $newer = $this->createPublishedQuestion(['created_at' => now()]);
        $user->pinnedQuestions()->attach($older->id, ['pinned_at' => now()]);

        Sanctum::actingAs($user);

        $this->getJson('/api/questions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.1.id', $newer->id);
    }
}
