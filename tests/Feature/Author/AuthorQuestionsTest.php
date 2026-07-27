<?php

namespace Tests\Feature\Author;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAuthors;
use Tests\TestCase;

class AuthorQuestionsTest extends TestCase
{
    use InteractsWithAuthors;
    use RefreshDatabase;

    public function test_guest_can_list_published_questions_authored_by_user(): void
    {
        $author = $this->createAuthor(['username' => 'q-author']);
        $published = $this->createPublishedQuestionFor($author, ['title' => 'My published']);
        $this->createUnpublishedQuestionFor($author, ['title' => 'My draft']);

        $other = $this->createAuthor(['username' => 'other-author']);
        $this->createPublishedQuestionFor($other, ['title' => 'Someone else']);

        $response = $this->getJson($this->authorQuestionsUrl($author))->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonPath('data.0.title', 'My published')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'slug',
                        'user',
                        'category',
                        'tags',
                        'votes_count',
                        'answers_count',
                    ],
                ],
                'links',
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_questions_defaults_to_type_questions_and_ten_per_page(): void
    {
        $author = $this->createAuthor(['username' => 'paginated-author']);

        Question::factory()->published()->count(15)->create(['user_id' => $author->id]);

        $this->getJson($this->authorQuestionsUrl($author))
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.current_page', 1);

        $this->getJson($this->authorQuestionsUrl($author, ['page' => 2]))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_questions_respects_custom_per_page(): void
    {
        $author = $this->createAuthor(['username' => 'custom-page']);
        Question::factory()->published()->count(5)->create(['user_id' => $author->id]);

        $this->getJson($this->authorQuestionsUrl($author, ['per_page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_questions_type_questions_orders_by_created_at_descending(): void
    {
        $author = $this->createAuthor(['username' => 'ordered-q']);
        $older = $this->createPublishedQuestionFor($author, [
            'title' => 'Older',
            'created_at' => now()->subDays(2),
        ]);
        $newer = $this->createPublishedQuestionFor($author, [
            'title' => 'Newer',
            'created_at' => now()->subDay(),
        ]);

        $this->getJson($this->authorQuestionsUrl($author, ['type' => 'questions']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_type_answers_returns_published_questions_author_answered(): void
    {
        $author = $this->createAuthor(['username' => 'answerer']);
        $answered = Question::factory()->published()->create([
            'title' => 'Answered question',
            'last_activity' => now()->subDay(),
        ]);
        $moreRecent = Question::factory()->published()->create([
            'title' => 'More recent answer activity',
            'last_activity' => now(),
        ]);
        $unanswered = Question::factory()->published()->create(['title' => 'No answer from author']);

        $this->createPublishedAnswerFor($author, $answered);
        $this->createPublishedAnswerFor($author, $moreRecent);
        $this->createUnpublishedAnswerFor($author, $unanswered);

        $response = $this->getJson($this->authorQuestionsUrl($author, ['type' => 'answers']))
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$moreRecent->id, $answered->id], $ids);
        $this->assertNotContains($unanswered->id, $ids);
    }

    public function test_type_answers_excludes_questions_with_only_unpublished_answers(): void
    {
        $author = $this->createAuthor(['username' => 'draft-answerer']);
        $question = Question::factory()->published()->create();
        $this->createUnpublishedAnswerFor($author, $question);

        $this->getJson($this->authorQuestionsUrl($author, ['type' => 'answers']))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_type_comments_returns_questions_with_author_comments_on_question_or_answer(): void
    {
        $author = $this->createAuthor(['username' => 'commenter']);

        $questionCommented = Question::factory()->published()->create([
            'title' => 'Question comment',
            'last_activity' => now()->subDays(2),
        ]);
        $answerCommentedQuestion = Question::factory()->published()->create([
            'title' => 'Answer comment parent',
            'last_activity' => now(),
        ]);
        $unrelated = Question::factory()->published()->create(['title' => 'No comments']);

        $this->createPublishedCommentOnQuestion($author, $questionCommented);

        $answer = Answer::factory()->published()->create([
            'question_id' => $answerCommentedQuestion->id,
        ]);
        $this->createPublishedCommentOnAnswer($author, $answer);

        Comment::factory()->unpublished()->forQuestion($unrelated)->create([
            'user_id' => $author->id,
        ]);

        $response = $this->getJson($this->authorQuestionsUrl($author, ['type' => 'comments']))
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$answerCommentedQuestion->id, $questionCommented->id], $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_type_comments_excludes_unpublished_questions_even_if_commented(): void
    {
        $author = $this->createAuthor(['username' => 'comment-draft-q']);
        $draft = Question::factory()->unpublished()->create();
        $this->createPublishedCommentOnQuestion($author, $draft);

        $this->getJson($this->authorQuestionsUrl($author, ['type' => 'comments']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[DataProvider('invalidTypeProvider')]
    public function test_invalid_type_falls_back_to_questions(string $type): void
    {
        $author = $this->createAuthor(['username' => 'fallback-type']);
        $owned = $this->createPublishedQuestionFor($author, ['title' => 'Owned']);
        $answered = Question::factory()->published()->create(['title' => 'Answered by author']);
        $this->createPublishedAnswerFor($author, $answered);

        $response = $this->getJson($this->authorQuestionsUrl($author, ['type' => $type]))
            ->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $owned->id);
    }

    public static function invalidTypeProvider(): array
    {
        return [
            'votes' => ['votes'],
            'empty string' => [''],
            'sql-ish' => ["questions'; DROP TABLE users;--"],
            'uppercase' => ['QUESTIONS'],
        ];
    }

    public function test_questions_returns_empty_data_when_author_has_no_matching_content(): void
    {
        $author = $this->createAuthor(['username' => 'lonely-author']);

        $this->getJson($this->authorQuestionsUrl($author))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_questions_returns_404_for_unknown_username(): void
    {
        $this->getJson($this->authorQuestionsUrl('missing-author'))->assertNotFound();
    }

    public function test_questions_excludes_unpublished_even_for_authenticated_owner(): void
    {
        // AuthorController::questions always applies published() — no visible() for owner.
        $author = $this->createAuthor(['username' => 'owner-drafts', 'level' => 5]);
        $this->createPublishedQuestionFor($author, ['title' => 'Public']);
        $this->createUnpublishedQuestionFor($author, ['title' => 'Private draft']);

        Sanctum::actingAs($author);

        $response = $this->getJson($this->authorQuestionsUrl($author))->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Public');
    }

    public function test_authenticated_users_can_access_author_questions(): void
    {
        $author = $this->createAuthor(['username' => 'auth-access']);
        $this->createPublishedQuestionFor($author);

        $this->actingAsUser();
        $this->getJson($this->authorQuestionsUrl($author))->assertOk();

        $this->actingAsAdmin();
        $this->getJson($this->authorQuestionsUrl($author))->assertOk();
    }

    public function test_questions_eager_loads_user_category_and_tags(): void
    {
        $author = $this->createAuthor(['username' => 'relations']);
        $question = $this->createPublishedQuestionFor($author);
        $question->tags()->attach(Tag::factory()->create());

        $payload = $this->getJson($this->authorQuestionsUrl($author))
            ->assertOk()
            ->json('data.0');

        $this->assertIsArray($payload['user']);
        $this->assertIsArray($payload['category']);
        $this->assertIsArray($payload['tags']);
        $this->assertSame($author->id, $payload['user']['id']);
    }

    public function test_guest_sees_can_permissions_as_false_on_question_resources(): void
    {
        $author = $this->createAuthor(['username' => 'perms']);
        $this->createPublishedQuestionFor($author);

        $this->getJson($this->authorQuestionsUrl($author))
            ->assertOk()
            ->assertJsonPath('data.0.can.view', false)
            ->assertJsonPath('data.0.can.update', false)
            ->assertJsonPath('data.0.can.delete', false)
            ->assertJsonPath('data.0.can.publish', false);
    }
}
