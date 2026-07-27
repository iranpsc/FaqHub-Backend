<?php

namespace Tests\Unit\Policies;

use App\Models\Answer;
use App\Models\AnswerCorrectnessMark;
use App\Models\User;
use App\Policies\AnswerPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnswerPolicyTest extends TestCase
{
    use RefreshDatabase;

    private AnswerPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AnswerPolicy;
    }

    public function test_update_and_delete_only_for_owner_of_unpublished_answer(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(['level' => 5]);
        $draft = Answer::factory()->unpublished()->create(['user_id' => $owner->id]);
        $published = Answer::factory()->published()->create(['user_id' => $owner->id]);

        $this->assertTrue($this->policy->update($owner, $draft));
        $this->assertTrue($this->policy->delete($owner, $draft));
        $this->assertFalse($this->policy->update($other, $draft));
        $this->assertFalse($this->policy->delete($other, $draft));
        $this->assertFalse($this->policy->update($owner, $published));
        $this->assertFalse($this->policy->delete($owner, $published));
    }

    #[DataProvider('publishScenarios')]
    public function test_publish_rules(
        int $actorLevel,
        int $ownerLevel,
        bool $sameUser,
        bool $alreadyPublished,
        bool $expected
    ): void {
        $owner = User::factory()->create(['level' => $ownerLevel]);
        $actor = $sameUser ? $owner : User::factory()->create(['level' => $actorLevel]);
        $answer = $alreadyPublished
            ? Answer::factory()->published()->create(['user_id' => $owner->id])
            : Answer::factory()->unpublished()->create(['user_id' => $owner->id]);

        $this->assertSame($expected, (bool) $this->policy->publish($actor, $answer));
    }

    public static function publishScenarios(): array
    {
        return [
            'owner cannot publish own' => [5, 5, true, false, false],
            'higher level can publish lower' => [3, 1, false, false, true],
            'level 2 cannot publish' => [2, 1, false, false, false],
            'same level non-owner cannot' => [3, 3, false, false, false],
            'lower level cannot publish higher' => [3, 4, false, false, false],
            'already published blocked' => [5, 1, false, true, false],
        ];
    }

    public function test_toggle_correctness_denies_own_answer(): void
    {
        $user = User::factory()->create(['level' => 5]);
        $answer = Answer::factory()->published()->create([
            'user_id' => $user->id,
            'is_correct' => false,
        ]);

        $result = $this->policy->toggleCorrectness($user, $answer, 'markAsCorrect');

        $this->assertInstanceOf(Response::class, $result);
        $this->assertTrue($result->denied());
        $this->assertSame(
            __('You cannot change the correctness of your own answer.'),
            $result->message()
        );
    }

    public function test_toggle_correctness_denies_when_not_strictly_higher_level(): void
    {
        $actor = User::factory()->create(['level' => 4]);
        $author = User::factory()->create(['level' => 4]);
        $answer = Answer::factory()->published()->create([
            'user_id' => $author->id,
            'is_correct' => false,
        ]);

        $result = $this->policy->toggleCorrectness($actor, $answer, 'markAsCorrect');

        $this->assertTrue($result->denied());
    }

    public function test_mark_as_correct_requires_level_four_and_quota(): void
    {
        $low = User::factory()->create(['level' => 3]);
        $author = User::factory()->create(['level' => 1]);
        $answer = Answer::factory()->published()->create([
            'user_id' => $author->id,
            'is_correct' => false,
        ]);

        $denied = $this->policy->toggleCorrectness($low, $answer, 'markAsCorrect');
        $this->assertTrue($denied->denied());
        $this->assertSame(
            __('You must be at least level 4 to mark answers as correct.'),
            $denied->message()
        );

        $marker = User::factory()->create(['level' => 4]);
        $this->assertTrue($this->policy->toggleCorrectness($marker, $answer, 'markAsCorrect'));

        // Exhaust quota
        for ($i = 0; $i < 4; $i++) {
            AnswerCorrectnessMark::factory()->correct()->create([
                'marker_user_id' => $marker->id,
                'answer_id' => Answer::factory()->published()->create(['user_id' => $author->id])->id,
            ]);
        }

        $quotaDenied = $this->policy->toggleCorrectness($marker, $answer, 'markAsCorrect');
        $this->assertTrue($quotaDenied->denied());
        $this->assertSame(
            __('You have reached your marking limit for correct answers.'),
            $quotaDenied->message()
        );
    }

    public function test_mark_as_correct_denies_when_already_correct(): void
    {
        $marker = User::factory()->create(['level' => 4]);
        $author = User::factory()->create(['level' => 1]);
        $answer = Answer::factory()->published()->correct()->create(['user_id' => $author->id]);

        $result = $this->policy->toggleCorrectness($marker, $answer, 'markAsCorrect');

        $this->assertTrue($result->denied());
        $this->assertSame(
            __('This answer is already marked as correct.'),
            $result->message()
        );
    }

    public function test_mark_as_normal_requires_level_five_and_blocks_self_previous_marker(): void
    {
        $levelFour = User::factory()->create(['level' => 4]);
        $author = User::factory()->create(['level' => 1]);
        $answer = Answer::factory()->published()->correct()->create(['user_id' => $author->id]);

        $tooLow = $this->policy->toggleCorrectness($levelFour, $answer, 'markAsNormal');
        $this->assertTrue($tooLow->denied());
        $this->assertSame(
            __('You must be at least level 5 to mark answers as normal.'),
            $tooLow->message()
        );

        $marker = User::factory()->create(['level' => 5]);
        AnswerCorrectnessMark::factory()->correct()->create([
            'answer_id' => $answer->id,
            'marker_user_id' => $marker->id,
        ]);

        $selfDeny = $this->policy->toggleCorrectness($marker, $answer, 'markAsNormal');
        $this->assertTrue($selfDeny->denied());
        $this->assertSame(
            __('You cannot unmark an answer you previously marked as correct.'),
            $selfDeny->message()
        );

        $other = User::factory()->create(['level' => 5]);
        $this->assertTrue($this->policy->toggleCorrectness($other, $answer, 'markAsNormal'));
    }

    public function test_mark_as_normal_denies_when_already_normal(): void
    {
        $marker = User::factory()->create(['level' => 5]);
        $author = User::factory()->create(['level' => 1]);
        $answer = Answer::factory()->published()->incorrect()->create(['user_id' => $author->id]);

        $result = $this->policy->toggleCorrectness($marker, $answer, 'markAsNormal');

        $this->assertTrue($result->denied());
        $this->assertSame(
            __('This answer is already marked as normal.'),
            $result->message()
        );
    }

    public function test_mark_as_normal_quota_equals_user_level(): void
    {
        $marker = User::factory()->create(['level' => 5]);
        $author = User::factory()->create(['level' => 1]);
        $answer = Answer::factory()->published()->correct()->create(['user_id' => $author->id]);

        for ($i = 0; $i < 5; $i++) {
            AnswerCorrectnessMark::factory()->incorrect()->create([
                'marker_user_id' => $marker->id,
                'answer_id' => Answer::factory()->published()->create(['user_id' => $author->id])->id,
            ]);
        }

        $result = $this->policy->toggleCorrectness($marker, $answer, 'markAsNormal');

        $this->assertTrue($result->denied());
        $this->assertSame(
            __('You have reached your marking limit for normal answers.'),
            $result->message()
        );
    }
}
