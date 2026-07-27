<?php

namespace Tests\Concerns;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait InteractsWithAuthors
{
    protected function actingAsAdmin(array $attributes = []): User
    {
        $user = User::factory()->admin()->create($attributes);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function actingAsUser(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['role' => 'user'], $attributes));

        Sanctum::actingAs($user);

        return $user;
    }

    protected function createAuthor(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    protected function createPublishedQuestionFor(User $author, array $attributes = []): Question
    {
        return Question::factory()->published($author)->create(array_merge([
            'user_id' => $author->id,
        ], $attributes));
    }

    protected function createUnpublishedQuestionFor(User $author, array $attributes = []): Question
    {
        return Question::factory()->unpublished()->create(array_merge([
            'user_id' => $author->id,
        ], $attributes));
    }

    protected function createPublishedAnswerFor(User $author, ?Question $question = null, array $attributes = []): Answer
    {
        $question ??= Question::factory()->published($author)->create();

        return Answer::factory()->published($author)->create(array_merge([
            'user_id' => $author->id,
            'question_id' => $question->id,
        ], $attributes));
    }

    protected function createUnpublishedAnswerFor(User $author, ?Question $question = null, array $attributes = []): Answer
    {
        $question ??= Question::factory()->published($author)->create();

        return Answer::factory()->unpublished()->create(array_merge([
            'user_id' => $author->id,
            'question_id' => $question->id,
        ], $attributes));
    }

    protected function createPublishedCommentOnQuestion(
        User $author,
        ?Question $question = null,
        array $attributes = []
    ): Comment {
        $question ??= Question::factory()->published($author)->create();

        return Comment::factory()->published($author)->forQuestion($question)->create(array_merge([
            'user_id' => $author->id,
        ], $attributes));
    }

    protected function createPublishedCommentOnAnswer(
        User $author,
        ?Answer $answer = null,
        array $attributes = []
    ): Comment {
        $answer ??= Answer::factory()->published($author)->create();

        return Comment::factory()->published($author)->forAnswer($answer)->create(array_merge([
            'user_id' => $author->id,
        ], $attributes));
    }

    protected function authorsIndexUrl(array $query = []): string
    {
        $base = '/api/authors';

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    protected function authorShowUrl(User|string $author): string
    {
        $username = $author instanceof User ? $author->username : $author;

        return '/api/authors/'.rawurlencode((string) $username);
    }

    protected function authorQuestionsUrl(User|string $author, array $query = []): string
    {
        $username = $author instanceof User ? $author->username : $author;
        $base = '/api/authors/'.rawurlencode((string) $username).'/questions';

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    /**
     * AuthorController wraps exceptions and returns HTTP 500 with error details.
     * Assert that contract so regressions toward proper validation remain visible.
     */
    protected function assertAuthorHandledFailure($response, ?string $expectedErrorFragment = null): void
    {
        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'error']);

        if ($expectedErrorFragment !== null) {
            $this->assertStringContainsString(
                $expectedErrorFragment,
                (string) $response->json('error')
            );
        }
    }
}
