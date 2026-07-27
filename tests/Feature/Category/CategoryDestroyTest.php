<?php

namespace Tests\Feature\Category;

use App\Models\Category;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCategories;
use Tests\TestCase;

class CategoryDestroyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithCategories;

    public function test_guest_cannot_delete_category(): void
    {
        $category = $this->createCategory(['slug' => 'protected']);

        $this->deleteJson('/api/categories/protected')
            ->assertUnauthorized();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_authenticated_non_admin_cannot_delete_category(): void
    {
        $category = $this->createCategory(['slug' => 'protected']);
        $this->actingAsUser();

        $this->deleteJson('/api/categories/protected')
            ->assertForbidden();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_admin_can_delete_category_and_receives_no_content(): void
    {
        $category = $this->createCategory(['name' => 'Remove Me', 'slug' => 'remove-me']);
        $this->actingAsAdmin();

        $this->deleteJson('/api/categories/remove-me')
            ->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_deleting_parent_cascades_to_child_categories(): void
    {
        $parent = $this->createCategory(['slug' => 'parent']);
        $child = $this->createChildCategory($parent, ['slug' => 'child']);
        $this->actingAsAdmin();

        $this->deleteJson('/api/categories/parent')->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $parent->id]);
        $this->assertDatabaseMissing('categories', ['id' => $child->id]);
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_deleting_category_cascades_to_related_questions(): void
    {
        // questions.category_id FK uses onDelete('cascade').
        $category = $this->createCategory(['slug' => 'with-questions']);
        $this->createQuestionsForCategory($category, 2);
        $this->actingAsAdmin();

        $this->assertDatabaseCount('questions', 2);

        $this->deleteJson('/api/categories/with-questions')->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseCount('questions', 0);
    }

    public function test_deleting_child_does_not_delete_parent(): void
    {
        $parent = $this->createCategory(['slug' => 'keep-parent']);
        $this->createChildCategory($parent, ['slug' => 'drop-child']);
        $this->actingAsAdmin();

        $this->deleteJson('/api/categories/drop-child')->assertNoContent();

        $this->assertDatabaseHas('categories', ['id' => $parent->id, 'slug' => 'keep-parent']);
        $this->assertDatabaseMissing('categories', ['slug' => 'drop-child']);
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_destroy_returns_404_for_unknown_slug(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson('/api/categories/missing')->assertNotFound();
    }

    public function test_deleted_category_cannot_be_shown_or_listed(): void
    {
        $category = $this->createCategory(['name' => 'Gone', 'slug' => 'gone']);
        $this->actingAsAdmin();

        $this->deleteJson('/api/categories/gone')->assertNoContent();

        $this->getJson('/api/categories/gone')->assertNotFound();
        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertNull(Category::find($category->id));
    }

    public function test_categories_are_hard_deleted_not_soft_deleted(): void
    {
        // Category model does not use SoftDeletes — document hard-delete behavior.
        $category = $this->createCategory(['slug' => 'hard-delete']);
        $this->actingAsAdmin();

        $this->deleteJson('/api/categories/hard-delete')->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertFalse(method_exists($category, 'trashed'));
    }

    public function test_admin_can_delete_one_category_without_affecting_siblings(): void
    {
        $keep = $this->createCategory(['slug' => 'keep']);
        $this->createCategory(['slug' => 'drop']);
        $this->actingAsAdmin();

        $this->deleteJson('/api/categories/drop')->assertNoContent();

        $this->assertDatabaseHas('categories', ['id' => $keep->id, 'slug' => 'keep']);
        $this->assertDatabaseMissing('categories', ['slug' => 'drop']);
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_deleting_category_does_not_orphan_questions_due_to_cascade(): void
    {
        $category = $this->createCategory(['slug' => 'cascade-q']);
        $question = Question::factory()->published()->create(['category_id' => $category->id]);
        $this->actingAsAdmin();

        $this->deleteJson('/api/categories/cascade-q')->assertNoContent();

        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
    }
}
