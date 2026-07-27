<?php

namespace Tests\Feature\Category;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCategories;
use Tests\TestCase;

class CategoryStoreTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithCategories;

    public function test_guest_cannot_create_category(): void
    {
        $this->postJson('/api/categories', $this->makeCategoryPayload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_authenticated_non_admin_cannot_create_category(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/categories', $this->makeCategoryPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_admin_can_create_root_category(): void
    {
        $this->actingAsAdmin();
        $payload = $this->makeCategoryPayload([
            'name' => 'Queue Workers',
        ]);

        $response = $this->postJson('/api/categories', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Queue Workers')
            ->assertJsonPath('data.slug', 'queue-workers')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'description',
                    'can' => ['view', 'update', 'delete'],
                ],
            ]);

        $this->assertDatabaseHas('categories', [
            'name' => 'Queue Workers',
            'slug' => 'queue-workers',
            'parent_id' => null,
        ]);
    }

    public function test_admin_can_create_child_category_with_valid_parent_id(): void
    {
        $parent = $this->createCategory(['name' => 'Parent', 'slug' => 'parent']);
        $this->actingAsAdmin();

        $this->postJson('/api/categories', [
            'name' => 'Child Category',
            'parent_id' => $parent->id,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Child Category')
            ->assertJsonPath('data.slug', 'child-category');

        $this->assertDatabaseHas('categories', [
            'name' => 'Child Category',
            'slug' => 'child-category',
            'parent_id' => $parent->id,
        ]);
    }

    public function test_admin_creating_category_always_generates_slug_from_name(): void
    {
        // Unlike tags, categories ignore any client-supplied slug and always use Str::slug(name).
        $this->actingAsAdmin();

        $this->postJson('/api/categories', [
            'name' => 'Hello World',
            'slug' => 'ignored-client-slug',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'hello-world');

        $this->assertDatabaseHas('categories', [
            'name' => 'Hello World',
            'slug' => 'hello-world',
        ]);
        $this->assertDatabaseMissing('categories', ['slug' => 'ignored-client-slug']);
    }

    public function test_store_accepts_name_at_max_length_boundary(): void
    {
        $this->actingAsAdmin();
        $name = str_repeat('a', 255);

        $this->postJson('/api/categories', ['name' => $name])
            ->assertCreated();

        $this->assertDatabaseHas('categories', ['name' => $name]);
    }

    public function test_store_accepts_null_parent_id(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/categories', [
            'name' => 'Root Null Parent',
            'parent_id' => null,
        ])->assertCreated();

        $this->assertDatabaseHas('categories', [
            'name' => 'Root Null Parent',
            'parent_id' => null,
        ]);
    }

    public function test_store_response_includes_admin_can_permissions(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/categories', $this->makeCategoryPayload())
            ->assertCreated()
            ->assertJsonPath('data.can.view', true)
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', true);
    }

    #[DataProvider('invalidStorePayloadProvider')]
    public function test_store_validation_rejects_invalid_payloads(array $payload, array $errorKeys): void
    {
        $this->actingAsAdmin();
        $this->createCategory(['name' => 'Existing', 'slug' => 'existing']);

        $this->postJson('/api/categories', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKeys);

        $this->assertDatabaseCount('categories', 1);
    }

    public static function invalidStorePayloadProvider(): array
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
                ['name' => ['array']],
                ['name'],
            ],
            'name exceeding max' => [
                ['name' => str_repeat('x', 256)],
                ['name'],
            ],
            'duplicate name' => [
                ['name' => 'Existing'],
                ['name'],
            ],
            'parent_id not existing' => [
                ['name' => 'Valid Name', 'parent_id' => 99999],
                ['parent_id'],
            ],
            'parent_id not an integer-like id' => [
                ['name' => 'Valid Name', 'parent_id' => 'not-an-id'],
                ['parent_id'],
            ],
        ];
    }

    public function test_store_uses_str_slug_rules_for_special_characters_in_name(): void
    {
        $this->actingAsAdmin();
        $name = 'C++ & Node.js!';

        $this->postJson('/api/categories', ['name' => $name])
            ->assertCreated()
            ->assertJsonPath('data.slug', Str::slug($name));
    }

    public function test_auto_generated_slug_can_collide_with_existing_slug_due_to_missing_unique_rule(): void
    {
        // Documents current gap: only name is unique-validated; slug is not unique at DB or validation layer.
        $this->createCategory(['name' => 'Other', 'slug' => 'hello-world']);
        $this->actingAsAdmin();

        $this->postJson('/api/categories', ['name' => 'Hello World'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'hello-world');

        $this->assertEquals(2, Category::where('slug', 'hello-world')->count());
    }

    public function test_store_does_not_persist_non_fillable_description(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/categories', [
            'name' => 'No Description Column',
            'description' => 'should be ignored',
        ])->assertCreated()
            ->assertJsonPath('data.description', null);

        $this->assertDatabaseHas('categories', [
            'name' => 'No Description Column',
        ]);
    }
}
