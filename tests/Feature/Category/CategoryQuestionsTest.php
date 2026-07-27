<?php

namespace Tests\Feature\Category;

use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCategories;
use Tests\TestCase;

class CategoryQuestionsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithCategories;

    public function test_guest_can_list_questions_for_category_by_slug(): void
    {
        $category = $this->createCategory(['name' => 'API', 'slug' => 'api']);
        $published = Question::factory()->published()->create([
            'category_id' => $category->id,
            'title' => 'Published for category',
        ]);
        $unpublished = Question::factory()->unpublished()->create([
            'category_id' => $category->id,
            'title' => 'Draft for category',
        ]);

        $otherCategory = $this->createCategory(['slug' => 'other']);
        Question::factory()->published()->create([
            'category_id' => $otherCategory->id,
            'title' => 'Other category question',
        ]);

        $response = $this->getJson('/api/categories/api/questions');

        // Unlike TagController::questions, this endpoint does not apply published() scope.
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'slug',
                        'user',
                        'category',
                        'votes_count',
                        'answers_count',
                    ],
                ],
                'links',
                'meta' => ['current_page', 'per_page', 'total'],
            ]);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($published->id, $ids);
        $this->assertContains($unpublished->id, $ids);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_category_questions_paginates_fifteen_per_page(): void
    {
        $category = $this->createCategory(['slug' => 'paginated']);
        $this->createQuestionsForCategory($category, 20);

        $this->getJson('/api/categories/paginated/questions')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.current_page', 1);

        $this->getJson('/api/categories/paginated/questions?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_category_questions_orders_by_created_at_descending(): void
    {
        $category = $this->createCategory(['slug' => 'ordered']);
        $older = Question::factory()->published()->create([
            'category_id' => $category->id,
            'title' => 'Older',
            'created_at' => now()->subDays(2),
        ]);
        $newer = Question::factory()->published()->create([
            'category_id' => $category->id,
            'title' => 'Newer',
            'created_at' => now()->subDay(),
        ]);

        $this->getJson('/api/categories/ordered/questions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_category_questions_includes_unpublished_questions(): void
    {
        // Documents current behavior: no published()/visible() filter on this endpoint.
        $category = $this->createCategory(['slug' => 'drafts-visible']);
        Question::factory()->unpublished()->create([
            'category_id' => $category->id,
            'title' => 'Draft question',
        ]);

        $this->getJson('/api/categories/drafts-visible/questions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Draft question')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_category_questions_returns_empty_data_when_category_has_no_questions(): void
    {
        $this->createCategory(['slug' => 'lonely']);

        $this->getJson('/api/categories/lonely/questions')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_category_questions_returns_404_for_unknown_category_slug(): void
    {
        $this->getJson('/api/categories/missing/questions')->assertNotFound();
    }

    public function test_category_questions_eager_loads_user_and_category(): void
    {
        $category = $this->createCategory(['slug' => 'relations']);
        Question::factory()->published()->create(['category_id' => $category->id]);

        $payload = $this->getJson('/api/categories/relations/questions')
            ->assertOk()
            ->json('data.0');

        $this->assertIsArray($payload['user']);
        $this->assertIsArray($payload['category']);
        $this->assertSame($category->id, $payload['category']['id']);
    }

    public function test_category_questions_endpoint_is_public_without_authentication(): void
    {
        $category = $this->createCategory(['slug' => 'public']);
        $this->createQuestionsForCategory($category, 1);

        $this->getJson('/api/categories/public/questions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_category_questions_excludes_questions_from_other_categories(): void
    {
        $target = $this->createCategory(['slug' => 'target']);
        $other = $this->createCategory(['slug' => 'other']);
        $this->createQuestionsForCategory($target, 1);
        $this->createQuestionsForCategory($other, 3);

        $this->getJson('/api/categories/target/questions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_category_questions_includes_answers_and_votes_counts(): void
    {
        $category = $this->createCategory(['slug' => 'counts']);
        Question::factory()->published()->create(['category_id' => $category->id]);

        $payload = $this->getJson('/api/categories/counts/questions')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayHasKey('answers_count', $payload);
        $this->assertArrayHasKey('votes_count', $payload);
        $this->assertSame(0, $payload['answers_count']);
        $this->assertSame(0, $payload['votes_count']);
    }
}
