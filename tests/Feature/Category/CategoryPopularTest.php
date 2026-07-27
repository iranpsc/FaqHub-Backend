<?php

namespace Tests\Feature\Category;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCategories;
use Tests\TestCase;

class CategoryPopularTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithCategories;

    public function test_guest_can_list_popular_categories_ordered_by_published_questions_count(): void
    {
        $popular = $this->createCategory(['name' => 'Popular', 'slug' => 'popular']);
        $mid = $this->createCategory(['name' => 'Mid', 'slug' => 'mid']);
        $empty = $this->createCategory(['name' => 'Empty', 'slug' => 'empty']);

        $this->createQuestionsForCategory($popular, 5, true);
        $this->createQuestionsForCategory($mid, 2, true);
        $this->createQuestionsForCategory($empty, 3, false); // unpublished only

        $response = $this->getJson('/api/categories/popular');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', 'popular')
            ->assertJsonPath('data.0.questions_count', 5)
            ->assertJsonPath('data.1.slug', 'mid')
            ->assertJsonPath('data.1.questions_count', 2)
            ->assertJsonPath('data.2.slug', 'empty')
            ->assertJsonPath('data.2.questions_count', 0)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'questions_count',
                        'can' => ['view', 'update', 'delete'],
                    ],
                ],
            ]);

        $this->assertArrayNotHasKey('meta', $response->json());
    }

    public function test_popular_defaults_to_limit_of_fifteen(): void
    {
        Category::factory()->count(20)->create();

        $this->getJson('/api/categories/popular')
            ->assertOk()
            ->assertJsonCount(15, 'data');
    }

    public function test_popular_respects_custom_limit(): void
    {
        Category::factory()->count(10)->create();

        $this->getJson('/api/categories/popular?limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_popular_counts_only_published_questions(): void
    {
        $category = $this->createCategory(['slug' => 'mixed']);
        $this->createQuestionsForCategory($category, 2, true);
        $this->createQuestionsForCategory($category, 4, false);

        $this->getJson('/api/categories/popular')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'mixed')
            ->assertJsonPath('data.0.questions_count', 2);
    }

    public function test_popular_includes_categories_with_zero_published_questions(): void
    {
        $this->createCategory(['slug' => 'zero']);

        $this->getJson('/api/categories/popular')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.questions_count', 0);
    }

    public function test_popular_endpoint_is_public_without_authentication(): void
    {
        $this->createCategory(['slug' => 'public']);

        $this->getJson('/api/categories/popular')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_popular_route_is_not_captured_by_show_slug_binding(): void
    {
        // Ensures /categories/popular is registered before {category:slug}.
        $this->createCategory(['slug' => 'something-else']);

        $this->getJson('/api/categories/popular')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_authenticated_admin_sees_can_permissions_on_popular(): void
    {
        $this->createCategory(['slug' => 'perms']);
        $this->actingAsAdmin();

        $this->getJson('/api/categories/popular')
            ->assertOk()
            ->assertJsonPath('data.0.can.update', true)
            ->assertJsonPath('data.0.can.delete', true);
    }

    #[DataProvider('limitEdgeCasesProvider')]
    public function test_popular_handles_limit_edge_cases(mixed $limit, int $expectedCount): void
    {
        Category::factory()->count(5)->create();

        $this->getJson('/api/categories/popular?limit='.urlencode((string) $limit))
            ->assertOk()
            ->assertJsonCount($expectedCount, 'data');
    }

    public static function limitEdgeCasesProvider(): array
    {
        return [
            'limit one' => [1, 1],
            'limit larger than total' => [100, 5],
            'limit zero returns empty' => [0, 0],
        ];
    }
}
