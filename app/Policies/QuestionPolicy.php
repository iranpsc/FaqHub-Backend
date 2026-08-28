<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

class QuestionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(?User $user, Question $question): bool
    {
        if ($user && ! is_null($question->user)) {
            if ($question->user->is($user) || ($user->level > $question->user->level)) {
                return true;
            }
        }

        return $question->published;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Question $question): bool
    {
        if (is_null($question->user)) {
            return false;
        }

        return ! $question->published && $question->user->is($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Question $question): bool
    {
        if (is_null($question->user)) {
            return false;
        }

        return ! $question->published && $question->user->is($user);
    }

    /**
     * Determine whether the user can publish the model.
     */
    public function publish(User $user, Question $question): bool
    {
        // Cannot publish if already published
        if ($question->published || is_null($question->user)) {
            return false;
        }

        // User must be at least level 2 to publish questions
        if ($user->level < 2) {
            return false;
        }

        // If question owner is user and user level is more than 2, their question
        // will be auto-published
        if ($question->user->is($user) && $user->level >= 2) {
            return true;
        }

        // User level must be greater than question owner level to publish
        // questions from other users
        if (($user->level >= 2) && ($user->level > $question->user->level)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can feature the model.
     */
    public function feature(User $user, Question $question): bool
    {
        if (is_null($question->user)) {
            return false;
        }

        // If question is not published, user cannot feature it
        if (! $question->published) {
            return false;
        }

        // User can not feature their own questions
        if ($question->user->is($user)) {
            return false;
        }

        // If question is already featured, user cannot feature it again
        if ($user->featuredQuestions()->where('question_id', $question->id)->exists()) {
            return false;
        }

        // Check if user has reached the 2-feature limit
        if ($user->featuredQuestions()->count() >= 2) {
            return false;
        }

        // If user level is less than 4, they cannot feature other users' questions
        if ($user->level < 4 && $question->user->isNot($user)) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can unfeature the model.
     */
    public function unfeature(User $user, Question $question): bool
    {
        if (is_null($question->user)) {
            return false;
        }

        // If question is not featured, user cannot unfeature it
        if (! $question->featured) {
            return false;
        }

        // If question is not published, user cannot unfeature it
        if (! $question->published) {
            return false;
        }

        // User can not unfeature their own questions
        if ($question->user->is($user)) {
            return false;
        }

        // If question is already unfeatured by the user, they cannot unfeature it again
        if ($user->unfeaturedQuestions()->where('question_id', $question->id)->exists()) {
            return false;
        }

        // If user unfeature 2 questions, they cannot unfeature more
        if ($user->unfeaturedQuestions()->count() >= 2) {
            return false;
        }

        // Only higher level users can unfeature questions
        if ($user->level < 4 && $question->user->isNot($user) && ! is_null($question->user)) {
            return false;
        }

        return true;
    }
}
