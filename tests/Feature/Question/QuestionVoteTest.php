<?php

namespace Tests\Feature\Question;

use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithQuestions;
use Tests\TestCase;

class QuestionVoteTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithQuestions;

    public function test_authenticated_user_can_upvote_and_owner_gains_ten_score(): void
    {
        $owner = User::factory()->create(['score' => 0]);
        $voter = User::factory()->create();
        $question = $this->createPublishedQuestion(['user_id' => $owner->id]);

        Sanctum::actingAs($voter);

        $this->postJson("/api/questions/{$question->id}/vote", ['type' => 'up'])
            ->assertOk()
            ->assertJson([
                'upvotes' => 1,
                'downvotes' => 0,
                'user_vote' => 'up',
            ]);

        $this->assertDatabaseHas('votes', [
            'votable_type' => Question::class,
            'votable_id' => $question->id,
            'user_id' => $voter->id,
            'type' => 'up',
        ]);
        $this->assertEquals(10, $owner->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'voted',
            'causer_id' => $voter->id,
            'subject_id' => $question->id,
        ]);
    }

    public function test_authenticated_user_can_downvote_and_owner_loses_two_score(): void
    {
        $owner = User::factory()->create(['score' => 10]);
        $voter = User::factory()->create();
        $question = $this->createPublishedQuestion(['user_id' => $owner->id]);

        Sanctum::actingAs($voter);

        $this->postJson("/api/questions/{$question->id}/vote", ['type' => 'down'])
            ->assertOk()
            ->assertJson([
                'upvotes' => 0,
                'downvotes' => 1,
                'user_vote' => 'down',
            ]);

        $this->assertEquals(8, $owner->fresh()->score);
    }

    public function test_user_cannot_vote_twice_or_change_vote_type(): void
    {
        $owner = User::factory()->create(['score' => 0]);
        $voter = User::factory()->create();
        $question = $this->createPublishedQuestion(['user_id' => $owner->id]);
        $question->votes()->create(['user_id' => $voter->id, 'type' => 'up']);

        Sanctum::actingAs($voter);

        $this->postJson("/api/questions/{$question->id}/vote", ['type' => 'up'])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('user_vote', 'up');

        $this->postJson("/api/questions/{$question->id}/vote", ['type' => 'down'])
            ->assertStatus(409)
            ->assertJsonPath('user_vote', 'up');

        $this->assertEquals(1, $question->votes()->count());
        $this->assertEquals(0, $owner->fresh()->score);
    }

    public function test_guest_cannot_vote(): void
    {
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/vote", ['type' => 'up'])
            ->assertUnauthorized();
    }

    #[DataProvider('invalidVoteProvider')]
    public function test_vote_validation(array $payload, array $errors): void
    {
        Sanctum::actingAs(User::factory()->create());
        $question = $this->createPublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/vote", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);
    }

    public static function invalidVoteProvider(): array
    {
        return [
            'missing type' => [[], ['type']],
            'invalid type' => [['type' => 'sideways'], ['type']],
            'null type' => [['type' => null], ['type']],
        ];
    }

    public function test_vote_on_question_with_null_owner_does_not_error(): void
    {
        $question = $this->createPublishedQuestion();
        $question->forceFill(['user_id' => null])->saveQuietly();
        $voter = User::factory()->create();

        Sanctum::actingAs($voter);

        $this->postJson("/api/questions/{$question->id}/vote", ['type' => 'up'])
            ->assertOk()
            ->assertJsonPath('user_vote', 'up');
    }
}
