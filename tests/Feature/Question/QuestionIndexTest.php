<?php

namespace Tests\Feature\Question;

use App\Models\Answer;
use App\Models\Category;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithQuestions;
use Tests\TestCase;

class QuestionIndexTest extends TestCase
{
    use InteractsWithQuestions;
    use RefreshDatabase;

    public function test_guest_can_list_published_questions_with_pagination_meta(): void
    {
        $this->createPublishedQuestion(['title' => 'Visible Question']);
        $this->createUnpublishedQuestion(['title' => 'Hidden Question']);

        $response = $this->getJson('/api/questions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Visible Question')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'slug',
                        'content',
                        'published',
                        'user',
                        'category',
                        'tags',
                        'can' => ['view', 'publish', 'feature', 'unfeature', 'update', 'delete'],
                    ],
                ],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_index_paginates_ten_questions_per_page(): void
    {
        Question::factory()->published()->count(15)->create();

        $response = $this->getJson('/api/questions');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.current_page', 1);

        $this->getJson('/api/questions?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_authenticated_user_sees_own_unpublished_questions(): void
    {
        $user = User::factory()->create(['level' => 2]);
        $other = User::factory()->create(['level' => 2]);

        $own = $this->createUnpublishedQuestion(['user_id' => $user->id, 'title' => 'Mine']);
        $this->createUnpublishedQuestion(['user_id' => $other->id, 'title' => 'Theirs']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/questions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    public function test_higher_level_user_sees_lower_level_unpublished_questions(): void
    {
        $viewer = User::factory()->create(['level' => 4]);
        $author = User::factory()->create(['level' => 1]);
        $question = $this->createUnpublishedQuestion(['user_id' => $author->id]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/questions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $question->id);
    }

    public function test_guest_can_filter_questions_by_category_id(): void
    {
        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();

        $match = $this->createPublishedQuestion(['category_id' => $categoryA->id]);
        $this->createPublishedQuestion(['category_id' => $categoryB->id]);

        $this->getJson("/api/questions?category_id={$categoryA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_guest_can_filter_questions_by_tags_or_logic(): void
    {
        $tagA = Tag::factory()->create();
        $tagB = Tag::factory()->create();
        $tagC = Tag::factory()->create();

        $withA = $this->createPublishedQuestion();
        $withA->tags()->attach($tagA);

        $withB = $this->createPublishedQuestion();
        $withB->tags()->attach($tagB);

        $withC = $this->createPublishedQuestion();
        $withC->tags()->attach($tagC);

        $ids = collect($this->getJson("/api/questions?tags={$tagA->id},{$tagB->id}")
            ->assertOk()
            ->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($withA->id));
        $this->assertTrue($ids->contains($withB->id));
        $this->assertFalse($ids->contains($withC->id));
    }

    public function test_filter_unanswered_returns_questions_without_answers(): void
    {
        $unanswered = $this->createPublishedQuestion(['title' => 'No answers']);
        $answered = $this->createPublishedQuestion(['title' => 'Has answers']);
        Answer::factory()->create(['question_id' => $answered->id, 'published' => true]);

        $this->getJson('/api/questions?filter=unanswered')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unanswered->id);
    }

    public function test_filter_solved_and_unsolved(): void
    {
        $solved = $this->createPublishedQuestion();
        $unsolved = $this->createPublishedQuestion();
        Answer::factory()->create([
            'question_id' => $solved->id,
            'is_correct' => true,
            'published' => true,
        ]);
        Answer::factory()->create([
            'question_id' => $unsolved->id,
            'is_correct' => false,
            'published' => true,
        ]);

        $this->getJson('/api/questions?filter=solved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $solved->id)
            ->assertJsonPath('data.0.is_solved', true);

        $unsolvedIds = collect($this->getJson('/api/questions?filter=unsolved')->json('data'))->pluck('id');
        $this->assertTrue($unsolvedIds->contains($unsolved->id));
        $this->assertFalse($unsolvedIds->contains($solved->id));
    }

    public function test_filter_unpublished_lists_visible_unpublished_for_authorized_viewer(): void
    {
        $viewer = User::factory()->create(['level' => 5]);
        $author = User::factory()->create(['level' => 1]);
        $unpublished = $this->createUnpublishedQuestion(['user_id' => $author->id]);
        $this->createPublishedQuestion(['user_id' => $author->id]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/questions?filter=unpublished')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unpublished->id);
    }

    public function test_sort_by_created_at_votes_answers_and_views(): void
    {
        $user = User::factory()->create();
        $older = $this->createPublishedQuestion([
            'created_at' => now()->subDays(3),
            'views' => 5,
        ]);
        $newer = $this->createPublishedQuestion([
            'created_at' => now()->subDay(),
            'views' => 50,
        ]);

        $older->votes()->create(['user_id' => $user->id, 'type' => 'up']);
        $older->votes()->create(['user_id' => User::factory()->create()->id, 'type' => 'up']);
        $newer->votes()->create(['user_id' => User::factory()->create()->id, 'type' => 'up']);
        Answer::factory()->count(2)->create(['question_id' => $older->id, 'published' => true]);

        $this->assertEquals(
            [$newer->id, $older->id],
            collect($this->getJson('/api/questions?sort=created_at&order=desc')->json('data'))->pluck('id')->all()
        );

        $this->assertEquals(
            [$older->id, $newer->id],
            collect($this->getJson('/api/questions?sort=created_at&order=asc')->json('data'))->pluck('id')->all()
        );

        $this->assertEquals(
            $older->id,
            $this->getJson('/api/questions?sort=votes&order=desc')->json('data.0.id')
        );

        $this->assertEquals(
            $older->id,
            $this->getJson('/api/questions?sort=answers_count&order=desc')->json('data.0.id')
        );

        $this->assertEquals(
            $newer->id,
            $this->getJson('/api/questions?sort=views_count&order=desc')->json('data.0.id')
        );
    }

    public function test_index_includes_authenticated_user_vote_and_pin_status(): void
    {
        $user = User::factory()->create();
        $question = $this->createPublishedQuestion();
        $question->votes()->create(['user_id' => $user->id, 'type' => 'up']);
        $user->pinnedQuestions()->attach($question->id, ['pinned_at' => now()]);

        Sanctum::actingAs($user);

        $this->getJson('/api/questions')
            ->assertOk()
            ->assertJsonPath('data.0.votes.user_vote', 'up')
            ->assertJsonPath('data.0.is_pinned_by_user', true);
    }

    public function test_index_does_not_expose_oauth_tokens_on_nested_user(): void
    {
        $author = User::factory()->create([
            'access_token' => 'secret-access',
            'refresh_token' => 'secret-refresh',
        ]);
        $this->createPublishedQuestion(['user_id' => $author->id]);

        $payload = $this->getJson('/api/questions')->assertOk()->json('data.0.user');

        $this->assertArrayNotHasKey('access_token', $payload);
        $this->assertArrayNotHasKey('refresh_token', $payload);
        $this->assertArrayNotHasKey('role', $payload);
    }
}
