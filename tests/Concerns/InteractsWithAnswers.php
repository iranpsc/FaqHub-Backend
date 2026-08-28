<?php

namespace Tests\Concerns;

use App\Models\Answer;
use App\Models\Question;
use App\Models\User;

trait InteractsWithAnswers
{
    use InteractsWithQuestions;

    protected function makeAnswerPayload(array $overrides = []): array
    {
        return array_merge([
            'content' => 'This is a detailed answer explaining the solution.',
        ], $overrides);
    }

    protected function createPublishedAnswer(array $attributes = []): Answer
    {
        return Answer::factory()->published()->create($attributes);
    }

    protected function createUnpublishedAnswer(array $attributes = []): Answer
    {
        return Answer::factory()->unpublished()->create($attributes);
    }

    protected function createAnswerForQuestion(
        Question $question,
        array $attributes = [],
        bool $published = true
    ): Answer {
        $factory = Answer::factory()->for($question);

        $factory = $published
            ? $factory->published()
            : $factory->unpublished();

        return $factory->create($attributes);
    }

    protected function createAnswerAuthor(int $level = 1, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'level' => $level,
            'score' => 0,
        ], $attributes));
    }
}
