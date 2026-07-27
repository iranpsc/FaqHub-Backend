<?php

namespace Tests\Feature\Category;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCategories;
use Tests\TestCase;

class CategoryIndexTest extends TestCase
{
    use InteractsWithCategories;
    use RefreshDatabase;

    public function test_guest_can_list_categories_with_pagination_meta_and_questions_count(): void
    {
        $category = $this->createCategory(['name' => 'Eloquent', 'slug' => 'eloquent']);
        $this->createQuestionsForCategory($category, 2);

        $response = $this->getJson('/api/categories');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $category->id)
            ->assertJsonPath('data.0.name', 'Eloquent')
            ->assertJsonPath('data.0.slug', 'eloquent')
            ->assertJsonPath('data.0.questions_count', 2)
            ->assertJsonPath('data.0.can.view', false)
            ->assertJsonPath('data.0.can.update', false)
            ->assertJsonPath('data.0.can.delete', false)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'description',
                        'questions_count',
                        'can' => ['view', 'update', 'delete'],
                    ],
                ],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_index_defaults_to_fifteen_categories_per_page(): void
    {
        Category::factory()->count(20)->create();

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.current_page', 1);

        $this->getJson('/api/categories?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_index_without_query_includes_questions_count_for_all_categories(): void
    {
        $withQuestions = $this->createCategory(['slug' => 'with-q']);
        $this->createCategory(['slug' => 'empty']);
        $this->createQuestionsForCategory($withQuestions, 3);

        $response = $this->getJson('/api/categories')->assertOk();

        $bySlug = collect($response->json('data'))->keyBy('slug');

        $this->assertSame(3, $bySlug['with-q']['questions_count']);
        $this->assertSame(0, $bySlug['empty']['questions_count']);
    }

    public function test_index_filters_categories_by_partial_name_query_and_returns_unpaginated_collection(): void
    {
        // When query is present, controller uses get() instead of paginate().
        $this->createCategory(['name' => 'Laravel', 'slug' => 'laravel']);
        $this->createCategory(['name' => 'PHPUnit', 'slug' => 'phpunit']);
        $this->createCategory(['name' => 'Livewire', 'slug' => 'livewire']);

        $response = $this->getJson('/api/categories?query=lar');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Laravel');

        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertArrayNotHasKey('links', $response->json());
    }

    public function test_index_query_search_omits_questions_count(): void
    {
        // withCount is only applied on the paginated (no-query) branch.
        $category = $this->createCategory(['name' => 'Searchable', 'slug' => 'searchable']);
        $this->createQuestionsForCategory($category, 2);

        $payload = $this->getJson('/api/categories?query=Search')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('questions_count', $payload);
    }

    public function test_index_empty_query_returns_paginated_all_categories(): void
    {
        // Empty string is falsy, so controller takes the paginate branch.
        Category::factory()->count(3)->create();

        $this->getJson('/api/categories?query=')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['meta', 'links']);
    }

    public function test_index_returns_empty_data_when_no_categories_exist(): void
    {
        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_index_query_with_no_matches_returns_empty_collection(): void
    {
        $this->createCategory(['name' => 'Vue', 'slug' => 'vue']);

        $this->getJson('/api/categories?query=nonexistent-category-xyz')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_authenticated_admin_sees_can_permissions_as_true(): void
    {
        $this->createCategory();
        $this->actingAsAdmin();

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('data.0.can.view', true)
            ->assertJsonPath('data.0.can.update', true)
            ->assertJsonPath('data.0.can.delete', true);
    }

    public function test_authenticated_non_admin_sees_can_permissions_as_false_except_view(): void
    {
        $this->createCategory();
        $this->actingAsUser();

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('data.0.can.view', true)
            ->assertJsonPath('data.0.can.update', false)
            ->assertJsonPath('data.0.can.delete', false);
    }

    #[DataProvider('indexQueryEdgeCasesProvider')]
    public function test_index_handles_query_edge_cases(string $query): void
    {
        $this->createCategory(['name' => 'Safe Category', 'slug' => 'safe-category']);

        $this->getJson('/api/categories?query='.urlencode($query))
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public static function indexQueryEdgeCasesProvider(): array
    {
        return [
            'sql injection attempt' => ["' OR 1=1 --"],
            'wildcard percent' => ['%'],
            'underscore wildcard' => ['_'],
            'unicode' => ['تست'],
            'very long query' => [str_repeat('a', 500)],
        ];
    }

    public function test_index_questions_count_includes_unpublished_questions(): void
    {
        // withCount('questions') has no published filter — documents current behavior.
        $category = $this->createCategory();
        $this->createQuestionsForCategory($category, 1, true);
        $this->createQuestionsForCategory($category, 2, false);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('data.0.questions_count', 3);
    }

    public function test_index_description_is_null_because_column_does_not_exist(): void
    {
        // CategoryResource exposes description, but categories table has no description column.
        $this->createCategory();

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('data.0.description', null);
    }

    public function test_index_does_not_expose_parent_id_or_last_activity(): void
    {
        $parent = $this->createCategory(['slug' => 'parent']);
        $this->createChildCategory($parent, ['slug' => 'child']);

        $payload = $this->getJson('/api/categories')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('parent_id', $payload);
        $this->assertArrayNotHasKey('last_activity', $payload);
        $this->assertArrayNotHasKey('updated_at', $payload);
    }
}
