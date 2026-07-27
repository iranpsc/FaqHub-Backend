<?php

namespace Tests\Feature\Answer;

use App\Models\Answer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAnswers;
use Tests\TestCase;

class AnswerVoteTest extends TestCase
{
    use InteractsWithAnswers;
    use RefreshDatabase;

    public function test_authenticated_user_can_upvote_and_owner_gains_ten_score(): void
    {
        $owner = User::factory()->create(['score' => 0]);
        $voter = User::factory()->create();
        $answer = $this->createPublishedAnswer(['user_id' => $owner->id]);

        Sanctum::actingAs($voter);

        $this->postJson("/api/answers/{$answer->id}/vote", ['type' => 'up'])
            ->assertOk()
            ->assertJson([
                'upvotes' => 1,
                'downvotes' => 0,
                'user_vote' => 'up',
            ]);

        $this->assertDatabaseHas('votes', [
            'votable_type' => Answer::class,
            'votable_id' => $answer->id,
            'user_id' => $voter->id,
            'type' => 'up',
        ]);
        $this->assertEquals(10, $owner->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'voted',
            'causer_id' => $voter->id,
            'subject_type' => Answer::class,
            'subject_id' => $answer->id,
        ]);
    }

    public function test_authenticated_user_can_downvote_and_owner_loses_two_score(): void
    {
        $owner = User::factory()->create(['score' => 10]);
        $voter = User::factory()->create();
        $answer = $this->createPublishedAnswer(['user_id' => $owner->id]);

        Sanctum::actingAs($voter);

        $this->postJson("/api/answers/{$answer->id}/vote", ['type' => 'down'])
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
        $answer = $this->createPublishedAnswer(['user_id' => $owner->id]);
        $answer->votes()->create(['user_id' => $voter->id, 'type' => 'up']);

        Sanctum::actingAs($voter);

        $this->postJson("/api/answers/{$answer->id}/vote", ['type' => 'up'])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'شما قبلا به این مورد رای داده‌اید')
            ->assertJsonPath('user_vote', 'up');

        $this->postJson("/api/answers/{$answer->id}/vote", ['type' => 'down'])
            ->assertStatus(409)
            ->assertJsonPath('user_vote', 'up');

        $this->assertEquals(1, $answer->votes()->count());
        $this->assertEquals(0, $owner->fresh()->score);
    }

    public function test_guest_cannot_vote(): void
    {
        $answer = $this->createPublishedAnswer();

        $this->postJson("/api/answers/{$answer->id}/vote", ['type' => 'up'])
            ->assertUnauthorized();
    }

    public function test_user_can_vote_on_own_answer(): void
    {
        // Vote endpoint has no ownership policy — only Sanctum auth.
        $owner = User::factory()->create(['score' => 0]);
        $answer = $this->createPublishedAnswer(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/answers/{$answer->id}/vote", ['type' => 'up'])
            ->assertOk()
            ->assertJsonPath('user_vote', 'up');

        $this->assertEquals(10, $owner->fresh()->score);
    }

    #[DataProvider('invalidVoteProvider')]
    public function test_vote_validation(array $payload, array $errors): void
    {
        Sanctum::actingAs(User::factory()->create());
        $answer = $this->createPublishedAnswer();

        $this->postJson("/api/answers/{$answer->id}/vote", $payload)
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
        ];
    }

    public function test_vote_returns_404_for_missing_answer(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/answers/999999/vote', ['type' => 'up'])
            ->assertNotFound();
    }

    public function test_different_users_can_vote_on_same_answer(): void
    {
        $owner = User::factory()->create(['score' => 0]);
        $answer = $this->createPublishedAnswer(['user_id' => $owner->id]);
        $voterA = User::factory()->create();
        $voterB = User::factory()->create();

        Sanctum::actingAs($voterA);
        $this->postJson("/api/answers/{$answer->id}/vote", ['type' => 'up'])->assertOk();

        Sanctum::actingAs($voterB);
        $this->postJson("/api/answers/{$answer->id}/vote", ['type' => 'down'])
            ->assertOk()
            ->assertJson([
                'upvotes' => 1,
                'downvotes' => 1,
                'user_vote' => 'down',
            ]);

        $this->assertEquals(8, $owner->fresh()->score); // +10 then -2
    }
}
