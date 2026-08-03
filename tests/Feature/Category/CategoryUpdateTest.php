<?php

namespace Tests\Feature\Category;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCategories;
use Tests\TestCase;

class CategoryUpdateTest extends TestCase
{
    use InteractsWithCategories;
    use RefreshDatabase;

    public function test_guest_cannot_update_category(): void
    {
        $category = $this->createCategory(['name' => 'Original', 'slug' => 'original']);

        $this->putJson('/api/categories/original', ['name' => 'Changed'])
            ->assertUnauthorized();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Original',
            'slug' => 'original',
        ]);
    }

    public function test_authenticated_non_admin_cannot_update_category(): void
    {
        $category = $this->createCategory(['name' => 'Original', 'slug' => 'original']);
        $this->actingAsUser();

        $this->putJson('/api/categories/original', ['name' => 'Changed'])
            ->assertForbidden();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Original',
        ]);
    }

    public function test_admin_can_update_category_name_and_regenerates_slug(): void
    {
        $category = $this->createCategory(['name' => 'Old Name', 'slug' => 'old-name']);
        $this->actingAsAdmin();

        $this->putJson('/api/categories/old-name', [
            'name' => 'New Name',
        ])->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.slug', 'new-name');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'New Name',
            'slug' => 'new-name',
        ]);
        $this->assertDatabaseMissing('categories', ['slug' => 'old-name']);
    }

    public function test_admin_can_assign_parent_on_update(): void
    {
        $parent = $this->createCategory(['slug' => 'parent']);
        $category = $this->createCategory(['name' => 'Child Candidate', 'slug' => 'child-candidate']);
        $this->actingAsAdmin();

        $this->putJson('/api/categories/child-candidate', [
            'name' => 'Child Candidate',
            'parent_id' => $parent->id,
        ])->assertOk();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'parent_id' => $parent->id,
        ]);
    }

    public function test_admin_can_clear_parent_id_with_null(): void
    {
        $parent = $this->createCategory(['slug' => 'parent']);
        $child = $this->createChildCategory($parent, [
            'name' => 'Was Child',
            'slug' => 'was-child',
        ]);
        $this->actingAsAdmin();

        $this->putJson('/api/categories/was-child', [
            'name' => 'Was Child',
            'parent_id' => null,
        ])->assertOk();

        $this->assertDatabaseHas('categories', [
            'id' => $child->id,
            'parent_id' => null,
        ]);
    }

    public function test_update_allows_keeping_same_name(): void
    {
        $this->createCategory(['name' => 'Keep Me', 'slug' => 'keep-me']);
        $this->actingAsAdmin();

        $this->putJson('/api/categories/keep-me', [
            'name' => 'Keep Me',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Keep Me')
            ->assertJsonPath('data.slug', 'keep-me');
    }

    public function test_client_supplied_slug_is_ignored_on_update(): void
    {
        $category = $this->createCategory(['name' => 'Before', 'slug' => 'before']);
        $this->actingAsAdmin();

        $this->putJson('/api/categories/before', [
            'name' => 'After Update',
            'slug' => 'custom-ignored',
        ])->assertOk()
            ->assertJsonPath('data.slug', 'after-update');

        $this->assertSame('after-update', $category->fresh()->slug);
        $this->assertDatabaseMissing('categories', ['slug' => 'custom-ignored']);
    }

    public function test_update_returns_404_for_unknown_slug(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/categories/missing', ['name' => 'Nope'])
            ->assertNotFound();
    }

    public function test_update_allows_self_as_parent_due_to_missing_business_rule(): void
    {
        // Documents current gap: validation only checks exists:categories,id — no self-parent guard.
        $category = $this->createCategory(['name' => 'Self Parent', 'slug' => 'self-parent']);
        $this->actingAsAdmin();

        $this->putJson('/api/categories/self-parent', [
            'name' => 'Self Parent',
            'parent_id' => $category->id,
        ])->assertOk();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'parent_id' => $category->id,
        ]);
    }

    #[DataProvider('invalidUpdatePayloadProvider')]
    public function test_update_validation_rejects_invalid_payloads(array $payload, array $errorKeys): void
    {
        $this->createCategory(['name' => 'Taken Name', 'slug' => 'taken-slug']);
        $target = $this->createCategory(['name' => 'Target', 'slug' => 'target']);
        $this->actingAsAdmin();

        $this->putJson('/api/categories/target', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKeys);

        $this->assertDatabaseHas('categories', [
            'id' => $target->id,
            'name' => 'Target',
            'slug' => 'target',
        ]);
    }

    public static function invalidUpdatePayloadProvider(): array
    {
        return [
            'missing name' => [
                ['parent_id' => null],
                ['name'],
            ],
            'null name' => [
                ['name' => null],
                ['name'],
            ],
            'empty name' => [
                ['name' => ''],
                ['name'],
            ],
            'name not a string' => [
                ['name' => 12345],
                ['name'],
            ],
            'name exceeding max' => [
                ['name' => str_repeat('z', 256)],
                ['name'],
            ],
            'duplicate name' => [
                ['name' => 'Taken Name'],
                ['name'],
            ],
            'parent_id not existing' => [
                ['name' => 'Target', 'parent_id' => 99999],
                ['parent_id'],
            ],
        ];
    }

    public function test_update_accepts_name_at_max_length_boundary(): void
    {
        $this->createCategory(['slug' => 'boundary', 'name' => 'Boundary']);
        $this->actingAsAdmin();
        $name = str_repeat('n', 255);

        $this->putJson('/api/categories/boundary', ['name' => $name])
            ->assertOk();

        $this->assertDatabaseHas('categories', [
            'slug' => Str::slug($name),
            'name' => $name,
        ]);
    }

    public function test_after_name_change_old_slug_route_returns_404(): void
    {
        $this->createCategory(['name' => 'Old Route', 'slug' => 'old-route']);
        $this->actingAsAdmin();

        $this->putJson('/api/categories/old-route', ['name' => 'New Route'])
            ->assertOk();

        $this->getJson('/api/categories/old-route')->assertNotFound();
        $this->getJson('/api/categories/new-route')->assertOk();
    }

    public function test_patch_update_behaves_like_put_and_requires_name(): void
    {
        $this->createCategory(['name' => 'Patch Me', 'slug' => 'patch-me']);
        $this->actingAsAdmin();

        $this->patchJson('/api/categories/patch-me', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }
}
