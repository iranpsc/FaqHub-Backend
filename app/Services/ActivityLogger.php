<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;

class ActivityLogger
{
    /**
     * Log when a question is created
     */
    public function logQuestionCreated(Question $question, ?User $user = null): void
    {
        if (! $user) {
            $user = $question->user;
        }

        if (! $user) {
            return;
        }

        activity()
            ->causedBy($user)
            ->performedOn($question)
            ->withProperties([
                'title' => $question->title,
                'slug' => $question->slug,
                'category_id' => $question->category_id,
            ])
            ->log('created_question');
    }

    /**
     * Log when an answer is created
     */
    public function logAnswerCreated(Answer $answer, ?User $user = null): void
    {
        if (! $user) {
            $user = $answer->user;
        }

        if (! $user) {
            return;
        }

        activity()
            ->causedBy($user)
            ->performedOn($answer)
            ->withProperties([
                'question_id' => $answer->question_id,
                'question_title' => $answer->question->title ?? null,
                'question_slug' => $answer->question->slug ?? null,
            ])
            ->log('created_answer');
    }

    /**
     * Log when a comment is created
     */
    public function logCommentCreated(Comment $comment, ?User $user = null): void
    {
        if (! $user) {
            $user = $comment->user;
        }

        if (! $user) {
            return;
        }

        $question = $this->getQuestionFromCommentable($comment);

        activity()
            ->causedBy($user)
            ->performedOn($comment)
            ->withProperties([
                'commentable_type' => $comment->commentable_type,
                'commentable_id' => $comment->commentable_id,
                'question_id' => $question?->id,
                'question_title' => $question?->title,
                'question_slug' => $question?->slug,
            ])
            ->log('created_comment');
    }

    /**
     * Log voting activity (final state only)
     *
     * @param  mixed  $votable  Question, Answer, or Comment
     * @param  string  $voteType  'up' or 'down'
     */
    public function logVote($votable, User $user, string $voteType): void
    {
        $question = $this->getQuestionFromVotable($votable);

        activity()
            ->causedBy($user)
            ->performedOn($votable)
            ->withProperties([
                'vote_type' => $voteType,
                'votable_type' => get_class($votable),
                'votable_id' => $votable->id,
                'question_id' => $question?->id,
                'question_title' => $question?->title,
                'question_slug' => $question?->slug,
            ])
            ->log('voted');
    }

    /**
     * Log when content is published
     *
     * @param  Question|Answer|Comment  $subject
     */
    public function logPublishing($subject, User $publisher): void
    {
        $description = match (get_class($subject)) {
            Question::class => 'published_question',
            Answer::class => 'published_answer',
            Comment::class => 'published_comment',
            default => 'published',
        };

        $question = $this->getQuestionFromSubject($subject);

        activity()
            ->causedBy($publisher)
            ->performedOn($subject)
            ->withProperties([
                'question_id' => $question?->id,
                'question_title' => $question?->title,
                'question_slug' => $question?->slug,
            ])
            ->log($description);
    }

    /**
     * Log when a question is featured
     */
    public function logFeaturing(Question $question, User $user): void
    {
        activity()
            ->causedBy($user)
            ->performedOn($question)
            ->withProperties([
                'title' => $question->title,
                'slug' => $question->slug,
                'is_featured' => true,
            ])
            ->log('featured_question');
    }

    /**
     * Log when a question is unfeatured
     */
    public function logUnfeaturing(Question $question, User $user): void
    {
        activity()
            ->causedBy($user)
            ->performedOn($question)
            ->withProperties([
                'title' => $question->title,
                'slug' => $question->slug,
                'is_featured' => false,
            ])
            ->log('unfeatured_question');
    }

    /**
     * Log when an answer is marked as correct
     */
    public function logAnswerCorrectness(Answer $answer, User $user, bool $isCorrect): void
    {
        activity()
            ->causedBy($user)
            ->performedOn($answer)
            ->withProperties([
                'question_id' => $answer->question_id,
                'question_title' => $answer->question->title ?? null,
                'question_slug' => $answer->question->slug ?? null,
                'is_correct' => $isCorrect,
            ])
            ->log('marked_correct');
    }

    /**
     * Get question from commentable (Comment can be on Question or Answer)
     */
    private function getQuestionFromCommentable(Comment $comment): ?Question
    {
        if ($comment->commentable_type === Question::class) {
            return $comment->commentable;
        }

        if ($comment->commentable_type === Answer::class) {
            return $comment->commentable->question ?? null;
        }

        return null;
    }

    /**
     * Get question from votable (Question, Answer, or Comment)
     *
     * @param  mixed  $votable
     */
    private function getQuestionFromVotable($votable): ?Question
    {
        if ($votable instanceof Question) {
            return $votable;
        }

        if ($votable instanceof Answer) {
            return $votable->question ?? null;
        }

        if ($votable instanceof Comment) {
            return $this->getQuestionFromCommentable($votable);
        }

        return null;
    }

    /**
     * Get question from subject (Question, Answer, or Comment)
     *
     * @param  mixed  $subject
     */
    private function getQuestionFromSubject($subject): ?Question
    {
        if ($subject instanceof Question) {
            return $subject;
        }

        if ($subject instanceof Answer) {
            return $subject->question ?? null;
        }

        if ($subject instanceof Comment) {
            return $this->getQuestionFromCommentable($subject);
        }

        return null;
    }
}
