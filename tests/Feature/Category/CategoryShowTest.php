<?php

namespace Tests\Feature\Category;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCategories;
use Tests\TestCase;

class CategoryShowTest extends TestCase
{
    use InteractsWithCategories;
    use RefreshDatabase;

    public function test_guest_can_show_category_by_slug_with_children(): void
    {
        $parent = $this->createCategory(['name' => 'Backend', 'slug' => 'backend']);
        $child = $this->createChildCategory($parent, [
            'name' => 'Laravel',
            'slug' => 'laravel',
        ]);

        $this->getJson('/api/categories/backend')
            ->assertOk()
            ->assertJsonPath('data.id', $parent->id)
            ->assertJsonPath('data.name', 'Backend')
            ->assertJsonPath('data.slug', 'backend')
            ->assertJsonPath('data.can.view', false)
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.delete', false)
            ->assertJsonPath('data.children.0.id', $child->id)
            ->assertJsonPath('data.children.0.slug', 'laravel')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'description',
                    'children',
                    'can' => ['view', 'update', 'delete'],
                ],
            ]);
    }

    public function test_show_returns_empty_children_array_when_category_has_none(): void
    {
        $this->createCategory(['slug' => 'leaf']);

        $this->getJson('/api/categories/leaf')
            ->assertOk()
            ->assertJsonPath('data.children', []);
    }

    public function test_show_omits_questions_count_when_not_eager_counted(): void
    {
        $this->createCategory(['slug' => 'no-count']);

        $payload = $this->getJson('/api/categories/no-count')->assertOk()->json('data');

        $this->assertArrayNotHasKey('questions_count', $payload);
    }

    public function test_show_returns_404_for_unknown_slug(): void
    {
        $this->getJson('/api/categories/does-not-exist')->assertNotFound();
    }

    public function test_show_returns_404_when_looking_up_by_numeric_id_instead_of_slug(): void
    {
        $category = $this->createCategory(['slug' => 'by-slug-only']);

        $this->getJson("/api/categories/{$category->id}")->assertNotFound();
    }

    public function test_authenticated_admin_sees_can_permissions_on_show(): void
    {
        $this->createCategory(['slug' => 'admin-view']);
        $this->actingAsAdmin();

        $this->getJson('/api/categories/admin-view')
            ->assertOk()
            ->assertJsonPath('data.can.view', true)
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', true);
    }

    public function test_authenticated_non_admin_sees_view_true_and_write_false_on_show(): void
    {
        $this->createCategory(['slug' => 'user-view']);
        $this->actingAsUser();

        $this->getJson('/api/categories/user-view')
            ->assertOk()
            ->assertJsonPath('data.can.view', true)
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.delete', false);
    }

    public function test_show_includes_nested_children_but_not_grandchildren_by_default(): void
    {
        $parent = $this->createCategory(['slug' => 'root']);
        $child = $this->createChildCategory($parent, ['slug' => 'child']);
        $this->createChildCategory($child, ['slug' => 'grandchild']);

        $response = $this->getJson('/api/categories/root')->assertOk();

        $this->assertCount(1, $response->json('data.children'));
        $this->assertSame('child', $response->json('data.children.0.slug'));
        // Nested children relation is not recursively loaded.
        $this->assertArrayNotHasKey('children', $response->json('data.children.0'));
    }

    public function test_show_exposes_created_at(): void
    {
        $this->createCategory(['slug' => 'with-timestamp']);

        $payload = $this->getJson('/api/categories/with-timestamp')->assertOk()->json('data');

        $this->assertArrayHasKey('created_at', $payload);
        $this->assertNotNull($payload['created_at']);
    }
}
