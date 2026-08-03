<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class CommentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any comments.
     *
     * @return Response|bool
     */
    public function viewAny(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the comment.
     *
     * @return Response|bool
     */
    public function view(User $user, Comment $comment)
    {
        return true;
    }

    /**
     * Determine whether the user can create comments.
     *
     * @return Response|bool
     */
    public function create(User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return Response|bool
     */
    public function update(User $user, Comment $comment)
    {
        return $comment->user->is($user) && ! $comment->published;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, Comment $comment)
    {
        return $comment->user->is($user) && ! $comment->published;
    }

    /**
     * Determine whether the user can publish the model.
     *
     * @return Response|bool
     */
    public function publish(User $user, Comment $comment)
    {
        // Cannot publish if already published
        if ($comment->published) {
            return false;
        }

        // User level < 2 cannot publish comments
        if ($user->level < 2) {
            return false;
        }

        // User level > 2 comments will auto published
        if ($user->level >= 2) {
            return true;
        }

        // user can not publish their own comments
        if ($comment->user->is($user)) {
            return false;
        }

        // User level < comment user level cannot publish comments
        if ($user->level < $comment->user->level) {
            return false;
        }

        return true;
    }
}
