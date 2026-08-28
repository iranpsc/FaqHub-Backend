<?php

namespace Tests\Feature\Answer;

use App\Models\Answer;
use App\Models\AnswerCorrectnessMark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAnswers;
use Tests\TestCase;

class AnswerToggleCorrectnessTest extends TestCase
{
    use InteractsWithAnswers;
    use RefreshDatabase;

    public function test_level_four_user_can_mark_answer_as_correct_and_awards_scores(): void
    {
        $marker = User::factory()->create(['level' => 4, 'score' => 0]);
        $owner = User::factory()->create(['level' => 2, 'score' => 0]);
        $answer = $this->createPublishedAnswer([
            'user_id' => $owner->id,
            'is_correct' => false,
        ]);

        Sanctum::actingAs($marker);

        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_correct', true)
            ->assertJsonPath('message', 'پاسخ به صحیح تغییر داده شد');

        $this->assertTrue($answer->fresh()->is_correct);
        $this->assertDatabaseHas('answer_correctness_marks', [
            'answer_id' => $answer->id,
            'marker_user_id' => $marker->id,
            'is_correct' => true,
        ]);
        $this->assertEquals(10, $owner->fresh()->score);
        $this->assertEquals(2, $marker->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'marked_correct',
            'subject_type' => Answer::class,
            'subject_id' => $answer->id,
            'causer_id' => $marker->id,
        ]);
    }

    public function test_level_five_user_can_mark_correct_answer_as_normal_when_not_original_marker(): void
    {
        $originalMarker = User::factory()->create(['level' => 4]);
        $unmarker = User::factory()->create(['level' => 5, 'score' => 0]);
        $owner = User::factory()->create(['level' => 1, 'score' => 20]);
        $answer = $this->createPublishedAnswer([
            'user_id' => $owner->id,
            'is_correct' => true,
        ]);

        AnswerCorrectnessMark::factory()->correct()->create([
            'answer_id' => $answer->id,
            'marker_user_id' => $originalMarker->id,
        ]);

        Sanctum::actingAs($unmarker);

        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_correct', false)
            ->assertJsonPath('message', 'پاسخ به عادی تغییر داده شد.');

        $this->assertFalse($answer->fresh()->is_correct);
        $this->assertDatabaseHas('answer_correctness_marks', [
            'answer_id' => $answer->id,
            'marker_user_id' => $unmarker->id,
            'is_correct' => false,
        ]);
        $this->assertEquals(10, $owner->fresh()->score); // 20 - 10
        $this->assertEquals(2, $unmarker->fresh()->score);
    }

    public function test_guest_cannot_toggle_correctness(): void
    {
        $answer = $this->createPublishedAnswer(['is_correct' => false]);

        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")
            ->assertUnauthorized();
    }

    public function test_user_cannot_toggle_correctness_on_own_answer(): void
    {
        $user = User::factory()->create(['level' => 5]);
        $answer = $this->createPublishedAnswer([
            'user_id' => $user->id,
            'is_correct' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")
            ->assertForbidden()
            ->assertJsonPath('message', __('You cannot change the correctness of your own answer.'));

        $this->assertFalse($answer->fresh()->is_correct);
        $this->assertDatabaseCount('answer_correctness_marks', 0);
    }

    public function test_level_below_four_cannot_mark_as_correct(): void
    {
        $marker = User::factory()->create(['level' => 3]);
        $owner = User::factory()->create(['level' => 1]);
        $answer = $this->createPublishedAnswer([
            'user_id' => $owner->id,
            'is_correct' => false,
        ]);

        Sanctum::actingAs($marker);

        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")
            ->assertForbidden()
            ->assertJsonPath('message', __('You must be at least level 4 to mark answers as correct.'));
    }

    public function test_level_four_cannot_mark_as_normal(): void
    {
        $marker = User::factory()->create(['level' => 4]);
        $owner = User::factory()->create(['level' => 1]);
        $answer = $this->createPublishedAnswer([
            'user_id' => $owner->id,
            'is_correct' => true,
        ]);

        Sanctum::actingAs($marker);

        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")
            ->assertForbidden()
            ->assertJsonPath('message', __('You must be at least level 5 to mark answers as normal.'));
    }

    public function test_cannot_act_on_same_or_higher_level_author(): void
    {
        $marker = User::factory()->create(['level' => 4]);
        $peer = User::factory()->create(['level' => 4]);
        $answer = $this->createPublishedAnswer([
            'user_id' => $peer->id,
            'is_correct' => false,
        ]);

        Sanctum::actingAs($marker);

        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                __('You can only change the correctness of lower level users\' answers.')
            );
    }

    public function test_original_correct_marker_cannot_unmark_same_answer(): void
    {
        $marker = User::factory()->create(['level' => 5, 'score' => 0]);
        $owner = User::factory()->create(['level' => 1, 'score' => 0]);
        $answer = $this->createPublishedAnswer([
            'user_id' => $owner->id,
            'is_correct' => false,
        ]);

        Sanctum::actingAs($marker);
        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")->assertOk();

        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                __('You cannot unmark an answer you previously marked as correct.')
            );

        $this->assertTrue($answer->fresh()->is_correct);
        $this->assertEquals(1, AnswerCorrectnessMark::where('answer_id', $answer->id)->count());
    }

    public function test_correct_mark_quota_equals_user_level(): void
    {
        $marker = User::factory()->create(['level' => 4]);
        $owner = User::factory()->create(['level' => 1]);

        // Exhaust quota: level 4 => 4 correct marks allowed
        for ($i = 0; $i < 4; $i++) {
            AnswerCorrectnessMark::factory()->correct()->create([
                'marker_user_id' => $marker->id,
                'answer_id' => $this->createPublishedAnswer(['user_id' => $owner->id])->id,
            ]);
        }

        $target = $this->createPublishedAnswer([
            'user_id' => $owner->id,
            'is_correct' => false,
        ]);

        Sanctum::actingAs($marker);

        $this->postJson("/api/answers/{$target->id}/toggle-correctness")
            ->assertForbidden()
            ->assertJsonPath('message', __('You have reached your marking limit for correct answers.'));
    }

    public function test_normal_mark_quota_equals_user_level(): void
    {
        $marker = User::factory()->create(['level' => 5]);
        $owner = User::factory()->create(['level' => 1]);

        for ($i = 0; $i < 5; $i++) {
            AnswerCorrectnessMark::factory()->incorrect()->create([
                'marker_user_id' => $marker->id,
                'answer_id' => $this->createPublishedAnswer([
                    'user_id' => $owner->id,
                    'is_correct' => true,
                ])->id,
            ]);
        }

        $target = $this->createPublishedAnswer([
            'user_id' => $owner->id,
            'is_correct' => true,
        ]);

        Sanctum::actingAs($marker);

        $this->postJson("/api/answers/{$target->id}/toggle-correctness")
            ->assertForbidden()
            ->assertJsonPath('message', __('You have reached your marking limit for normal answers.'));
    }

    public function test_marking_correct_solves_question(): void
    {
        $marker = User::factory()->create(['level' => 4]);
        $owner = User::factory()->create(['level' => 1]);
        $question = $this->createPublishedQuestion();
        $answer = $this->createAnswerForQuestion($question, [
            'user_id' => $owner->id,
            'is_correct' => false,
        ]);

        $this->assertFalse($question->isSolved());

        Sanctum::actingAs($marker);
        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")->assertOk();

        $this->assertTrue($question->fresh()->isSolved());
    }

    public function test_unmarking_last_correct_answer_unsolves_question(): void
    {
        $originalMarker = User::factory()->create(['level' => 4]);
        $unmarker = User::factory()->create(['level' => 5]);
        $owner = User::factory()->create(['level' => 1]);
        $question = $this->createPublishedQuestion();
        $answer = $this->createAnswerForQuestion($question, [
            'user_id' => $owner->id,
            'is_correct' => true,
        ]);

        AnswerCorrectnessMark::factory()->correct()->create([
            'answer_id' => $answer->id,
            'marker_user_id' => $originalMarker->id,
        ]);

        $this->assertTrue($question->isSolved());

        Sanctum::actingAs($unmarker);
        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")->assertOk();

        $this->assertFalse($question->fresh()->isSolved());
    }

    public function test_toggle_returns_404_for_missing_answer(): void
    {
        Sanctum::actingAs(User::factory()->create(['level' => 5]));

        $this->postJson('/api/answers/999999/toggle-correctness')->assertNotFound();
    }

    public function test_same_user_cannot_create_duplicate_mark_row_via_re_toggle_after_external_unmark(): void
    {
        // Unique (marker_user_id, answer_id) prevents a second mark row for the same marker.
        $marker = User::factory()->create(['level' => 5]);
        $unmarker = User::factory()->create(['level' => 5]);
        $owner = User::factory()->create(['level' => 1]);
        $answer = $this->createPublishedAnswer([
            'user_id' => $owner->id,
            'is_correct' => false,
        ]);

        Sanctum::actingAs($marker);
        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")->assertOk();

        Sanctum::actingAs($unmarker);
        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")->assertOk();
        $this->assertFalse($answer->fresh()->is_correct);

        Sanctum::actingAs($marker);
        // Policy allows markAsCorrect again, but DB unique constraint causes a server error.
        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")
            ->assertServerError();

        $this->assertEquals(
            1,
            AnswerCorrectnessMark::where('marker_user_id', $marker->id)
                ->where('answer_id', $answer->id)
                ->count()
        );
    }

    #[DataProvider('markAsCorrectAllowedLevels')]
    public function test_mark_as_correct_allowed_for_qualifying_levels(int $level): void
    {
        $marker = User::factory()->create(['level' => $level]);
        $owner = User::factory()->create(['level' => 1]);
        $answer = $this->createPublishedAnswer([
            'user_id' => $owner->id,
            'is_correct' => false,
        ]);

        Sanctum::actingAs($marker);

        $this->postJson("/api/answers/{$answer->id}/toggle-correctness")
            ->assertOk()
            ->assertJsonPath('is_correct', true);
    }

    public static function markAsCorrectAllowedLevels(): array
    {
        return [
            'level 4' => [4],
            'level 5' => [5],
            'level 6' => [6],
        ];
    }
}
