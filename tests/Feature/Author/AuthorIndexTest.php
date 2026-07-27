<?php

namespace Tests\Feature\Author;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAuthors;
use Tests\TestCase;

class AuthorIndexTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAuthors;

    public function test_guest_can_list_authors_with_pagination_meta_and_activity_fields(): void
    {
        $author = $this->createAuthor([
            'name' => 'Jane Doe',
            'username' => 'jane-doe',
            'score' => 42,
            'level' => 3,
        ]);
        $this->createPublishedQuestionFor($author, ['title' => 'First published']);

        $response = $this->getJson($this->authorsIndexUrl())->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $author->id)
            ->assertJsonPath('data.0.username', 'jane-doe')
            ->assertJsonPath('data.0.name', 'Jane Doe')
            ->assertJsonPath('data.0.score', 42)
            ->assertJsonPath('data.0.level', 3)
            ->assertJsonPath('data.0.questions_count', 1)
            ->assertJsonPath('data.0.answers_count', 0)
            ->assertJsonPath('data.0.comments_count', 0)
            ->assertJsonPath('data.0.total_activity', 1)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'username',
                        'name',
                        'score',
                        'level',
                        'image_url',
                        'questions_count',
                        'answers_count',
                        'comments_count',
                        'total_activity',
                        'created_at',
                        'recent_questions',
                    ],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
                'links' => ['first', 'last', 'prev', 'next'],
            ]);
    }

    public function test_index_defaults_to_twenty_authors_per_page_sorted_by_score_desc(): void
    {
        User::factory()->count(25)->sequence(
            fn ($sequence) => ['score' => 100 - $sequence->index]
        )->create();

        $response = $this->getJson($this->authorsIndexUrl())->assertOk();

        $response->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('links.prev', null);

        $scores = collect($response->json('data'))->pluck('score')->all();
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);

        $this->getJson($this->authorsIndexUrl(['page' => 2]))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_index_respects_custom_per_page(): void
    {
        User::factory()->count(5)->create();

        $this->getJson($this->authorsIndexUrl(['per_page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_index_returns_empty_data_when_no_authors_exist(): void
    {
        $this->getJson($this->authorsIndexUrl())
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.from', null)
            ->assertJsonPath('meta.to', null);
    }

    public function test_index_includes_activity_counts_and_total_for_all_content_states(): void
    {
        // Index withCount has no published filter — documents current behavior.
        $author = $this->createAuthor(['score' => 1_000_000]);
        $question = $this->createPublishedQuestionFor($author);
        $this->createUnpublishedQuestionFor($author);

        Answer::factory()->published()->create([
            'user_id' => $author->id,
            'question_id' => $question->id,
        ]);
        Answer::factory()->unpublished()->create([
            'user_id' => $author->id,
            'question_id' => $question->id,
        ]);
        Comment::factory()->published()->forQuestion($question)->create(['user_id' => $author->id]);
        Comment::factory()->unpublished()->forQuestion($question)->create(['user_id' => $author->id]);

        $this->getJson($this->authorsIndexUrl(['per_page' => 1]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $author->id)
            ->assertJsonPath('data.0.questions_count', 2)
            ->assertJsonPath('data.0.answers_count', 2)
            ->assertJsonPath('data.0.comments_count', 2)
            ->assertJsonPath('data.0.total_activity', 6);
    }

    public function test_index_recent_questions_only_includes_published_and_limits_to_three(): void
    {
        $author = $this->createAuthor(['score' => 999]);

        $oldest = $this->createPublishedQuestionFor($author, [
            'title' => 'Oldest published',
            'created_at' => now()->subDays(4),
        ]);
        $this->createPublishedQuestionFor($author, [
            'title' => 'Third newest',
            'created_at' => now()->subDays(3),
        ]);
        $this->createPublishedQuestionFor($author, [
            'title' => 'Second newest',
            'created_at' => now()->subDays(2),
        ]);
        $newest = $this->createPublishedQuestionFor($author, [
            'title' => 'Newest published',
            'created_at' => now()->subDay(),
        ]);
        $this->createUnpublishedQuestionFor($author, [
            'title' => 'Draft should be hidden',
            'created_at' => now(),
        ]);

        $recent = $this->getJson($this->authorsIndexUrl(['per_page' => 1]))
            ->assertOk()
            ->json('data.0.recent_questions');

        $this->assertCount(3, $recent);
        $this->assertSame($newest->id, $recent[0]['id']);
        $this->assertSame('Newest published', $recent[0]['title']);
        $this->assertSame($newest->slug, $recent[0]['slug']);
        $this->assertArrayHasKey('created_at', $recent[0]);
        $this->assertNotContains($oldest->id, collect($recent)->pluck('id')->all());
        $this->assertFalse(
            collect($recent)->contains(fn ($q) => $q['title'] === 'Draft should be hidden')
        );
    }

    public function test_index_score_and_level_default_via_model_attributes(): void
    {
        $author = $this->createAuthor(['score' => 0, 'level' => 1]);

        $this->getJson($this->authorsIndexUrl())
            ->assertOk()
            ->assertJsonPath('data.0.id', $author->id)
            ->assertJsonPath('data.0.score', 0)
            ->assertJsonPath('data.0.level', 1);
    }

    public function test_index_returns_image_url_when_author_has_avatar(): void
    {
        $author = User::factory()->withImage('avatars/author.jpg')->create(['score' => 10]);

        $this->getJson($this->authorsIndexUrl())
            ->assertOk()
            ->assertJsonPath('data.0.id', $author->id)
            ->assertJsonPath('data.0.image_url', asset('storage/avatars/author.jpg'));
    }

    public function test_index_returns_null_image_url_when_author_has_no_avatar(): void
    {
        $this->createAuthor(['image' => null, 'score' => 1]);

        $this->getJson($this->authorsIndexUrl())
            ->assertOk()
            ->assertJsonPath('data.0.image_url', null);
    }

    #[DataProvider('sortByProvider')]
    public function test_index_sorts_by_supported_fields(string $sortBy, string $attribute, array $values): void
    {
        $authors = [];
        foreach ($values as $value) {
            $authors[] = $this->createAuthor([$attribute => $value]);
        }

        if ($sortBy === 'questions_count') {
            $this->createPublishedQuestionFor($authors[0]);
            $this->createPublishedQuestionFor($authors[0]);
            $this->createPublishedQuestionFor($authors[1]);
        }

        if ($sortBy === 'answers_count') {
            $question = Question::factory()->published($authors[0])->create([
                'user_id' => $authors[0]->id,
            ]);
            Answer::factory()->count(2)->published($authors[0])->create([
                'user_id' => $authors[0]->id,
                'question_id' => $question->id,
            ]);
            Answer::factory()->published($authors[1])->create([
                'user_id' => $authors[1]->id,
                'question_id' => $question->id,
            ]);
        }

        $targetIds = collect($authors)->pluck('id');

        $descIds = collect(
            $this->getJson($this->authorsIndexUrl([
                'sort_by' => $sortBy,
                'sort_order' => 'desc',
            ]))->assertOk()->json('data')
        )->pluck('id')->intersect($targetIds)->values()->all();

        $ascIds = collect(
            $this->getJson($this->authorsIndexUrl([
                'sort_by' => $sortBy,
                'sort_order' => 'asc',
            ]))->assertOk()->json('data')
        )->pluck('id')->intersect($targetIds)->values()->all();

        $this->assertSame($authors[0]->id, $descIds[0]);
        $this->assertSame($authors[2]->id, $descIds[2]);
        $this->assertSame($authors[2]->id, $ascIds[0]);
        $this->assertSame($authors[0]->id, $ascIds[2]);
    }

    public static function sortByProvider(): array
    {
        return [
            'score' => ['score', 'score', [300, 200, 100]],
            'name' => ['name', 'name', ['Zack', 'Mike', 'Alice']],
            'created_at' => [
                'created_at',
                'created_at',
                [
                    now()->subDay()->toDateTimeString(),
                    now()->subDays(2)->toDateTimeString(),
                    now()->subDays(3)->toDateTimeString(),
                ],
            ],
            'questions_count' => ['questions_count', 'score', [1, 1, 1]],
            'answers_count' => ['answers_count', 'score', [1, 1, 1]],
        ];
    }

    public function test_index_unknown_sort_by_falls_back_to_score(): void
    {
        $low = $this->createAuthor(['score' => 10, 'name' => 'Low']);
        $high = $this->createAuthor(['score' => 100, 'name' => 'High']);

        $ids = collect(
            $this->getJson($this->authorsIndexUrl(['sort_by' => 'unknown_field']))
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$high->id, $low->id], $ids);
    }

    public function test_index_applies_secondary_sort_by_created_at_desc_when_not_sorting_by_created_at(): void
    {
        $older = $this->createAuthor([
            'score' => 50,
            'created_at' => now()->subDays(2),
        ]);
        $newer = $this->createAuthor([
            'score' => 50,
            'created_at' => now()->subDay(),
        ]);

        $ids = collect(
            $this->getJson($this->authorsIndexUrl(['sort_by' => 'score']))
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ids);
    }

    #[DataProvider('searchProvider')]
    public function test_index_searches_by_name_email_and_username(
        string $search,
        string $expectedUsername
    ): void {
        $this->createAuthor([
            'name' => 'Alice Wonder',
            'username' => 'alice-w',
            'email' => 'alice@example.com',
            'score' => 1,
        ]);
        $this->createAuthor([
            'name' => 'Bob Builder',
            'username' => 'bob-b',
            'email' => 'bob@example.com',
            'score' => 2,
        ]);

        $response = $this->getJson($this->authorsIndexUrl(['search' => $search]))->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', $expectedUsername);
    }

    public static function searchProvider(): array
    {
        return [
            'partial name' => ['Alice', 'alice-w'],
            'partial username' => ['bob-b', 'bob-b'],
            'partial email' => ['bob@example', 'bob-b'],
            'case sensitive match for name fragment' => ['Wonder', 'alice-w'],
        ];
    }

    public function test_index_empty_search_returns_all_authors(): void
    {
        // Empty string is falsy, so search branch is skipped.
        User::factory()->count(3)->create();

        $this->getJson($this->authorsIndexUrl(['search' => '']))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_index_search_with_no_matches_returns_empty_collection(): void
    {
        $this->createAuthor(['name' => 'Visible', 'username' => 'visible']);

        $this->getJson($this->authorsIndexUrl(['search' => 'zzzz-nonexistent']))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_authenticated_users_can_access_index(): void
    {
        $this->createAuthor(['score' => 1]);

        $this->actingAsUser();
        $this->getJson($this->authorsIndexUrl())->assertOk();

        $this->actingAsAdmin();
        $this->getJson($this->authorsIndexUrl())->assertOk();
    }

    public function test_index_does_not_expose_email_tokens_or_mobile(): void
    {
        $this->createAuthor([
            'email' => 'secret@example.com',
            'mobile' => '09120001122',
            'access_token' => 'access-secret',
            'refresh_token' => 'refresh-secret',
            'code' => '654321',
            'score' => 5,
        ]);

        $payload = $this->getJson($this->authorsIndexUrl())->assertOk()->json('data.0');
        $encoded = json_encode($this->getJson($this->authorsIndexUrl())->json());

        foreach (['email', 'mobile', 'access_token', 'refresh_token', 'code', 'role'] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }

        $this->assertStringNotContainsString('secret@example.com', $encoded);
        $this->assertStringNotContainsString('access-secret', $encoded);
        $this->assertStringNotContainsString('refresh-secret', $encoded);
        $this->assertStringNotContainsString('09120001122', $encoded);
        $this->assertStringNotContainsString('654321', $encoded);
    }
}
