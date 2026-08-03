<?php

namespace Tests\Concerns;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;

trait InteractsWithComments
{
    use InteractsWithAnswers;

    protected function makeCommentPayload(array $overrides = []): array
    {
        return array_merge([
            'content' => 'This is a thoughtful comment on the discussion.',
        ], $overrides);
    }

    protected function createPublishedComment(array $attributes = []): Comment
    {
        return Comment::factory()->published()->create($attributes);
    }

    protected function createUnpublishedComment(array $attributes = []): Comment
    {
        return Comment::factory()->unpublished()->create($attributes);
    }

    protected function createCommentOnQuestion(
        ?Question $question = null,
        array $attributes = [],
        bool $published = true
    ): Comment {
        $question ??= $this->createPublishedQuestion();

        $factory = Comment::factory()
            ->for($question, 'commentable')
            ->state($attributes);

        $factory = $published
            ? $factory->published()
            : $factory->unpublished();

        return $factory->create();
    }

    protected function createCommentOnAnswer(
        ?Answer $answer = null,
        array $attributes = [],
        bool $published = true
    ): Comment {
        $answer ??= Answer::factory()->published()->create();

        $factory = Comment::factory()
            ->for($answer, 'commentable')
            ->state($attributes);

        $factory = $published
            ? $factory->published()
            : $factory->unpublished();

        return $factory->create();
    }

    protected function createCommentAuthor(int $level = 1, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'level' => $level,
            'score' => 0,
        ], $attributes));
    }
}
