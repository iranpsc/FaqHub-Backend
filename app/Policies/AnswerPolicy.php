<?php

namespace App\Policies;

use App\Models\Answer;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class AnswerPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, Answer $answer)
    {
        return $answer->user->is($user) && ! $answer->published;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, Answer $answer)
    {
        return $answer->user->is($user) && ! $answer->published;
    }

    /**
     * Determine whether the user can publish the model.
     *
     * @return Response|bool
     */
    public function publish(User $user, Answer $answer)
    {
        // Cannot publish if already published
        if ($answer->published) {
            return false;
        }

        // User can not publish their own answers
        if ($answer->user->is($user)) {
            return false;
        }

        // Higher level users can publish answers from lower level users
        return ($user->level > 2) && ($user->level > $answer->user->level);
    }

    /**
     * Check if user can toggle correctness of answer.
     *
     * @return bool
     */
    public function toggleCorrectness(User $user, Answer $answer, string $action): bool|Response
    {
        // General: can't act on own answer
        if ($answer->user->is($user)) {
            return Response::deny(__('You cannot change the correctness of your own answer.'));
        }

        // General: can only act on lower level user's answer
        if ($user->level <= $answer->user->level) { // must be strictly higher
            return Response::deny(__('You can only change the correctness of lower level users\' answers.'));
        }

        // Action specific rules
        if ($action === 'markAsCorrect') {
            // Only level >=4 can mark correct
            if ($user->level < 4) {
                return Response::deny(__('You must be at least level 4 to mark answers as correct.'));
            }

            // Cannot mark again if already marked correct by someone (front end passes toggle intent)
            if ($answer->is_correct) {
                return Response::deny(__('This answer is already marked as correct.'));
            }

            // Quota: user can mark as many correct answers as their level number (lifetime, not per day)
            $totalCorrectMarks = $user->markedAsCorrectAnswers()->count();

            if ($totalCorrectMarks >= $user->level) {
                return Response::deny(__('You have reached your marking limit for correct answers.'));
            }
        }

        if ($action === 'markAsNormal') {
            // Only level >=5 can mark normal (remove correctness)
            if ($user->level < 5) {
                return Response::deny(__('You must be at least level 5 to mark answers as normal.'));
            }

            // Can't unmark if answer is already normal
            if (! $answer->is_correct) {
                return Response::deny(__('This answer is already marked as normal.'));
            }

            // User can't unmark if they were the one who previously marked?
            // (Rule: When a user marks as correct they cannot mark as normal again)
            $previousMark = $user->markedAsCorrectAnswers()
                ->where('answer_id', $answer->id)
                ->exists();

            if ($previousMark) {
                return Response::deny(__('You cannot unmark an answer you previously marked as correct.'));
            }

            // Quota for marking normal (unmarking)
            $dailyNormalMarks = $user->markedAsNormalAnswers()->count();
            if ($dailyNormalMarks >= $user->level) {
                return Response::deny(__('You have reached your marking limit for normal answers.'));
            }
        }

        return true; // if all checks pass, allow action
    }
}
