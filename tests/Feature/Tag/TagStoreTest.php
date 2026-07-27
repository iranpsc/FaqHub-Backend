<?php

namespace Tests\Feature\Tag;

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTags;
use Tests\TestCase;

class TagStoreTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTags;

    public function test_guest_cannot_create_tag(): void
    {
        $this->postJson('/api/tags', $this->makeTagPayload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('tags', 0);
    }

    public function test_authenticated_non_admin_cannot_create_tag(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/tags', $this->makeTagPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('tags', 0);
    }

    public function test_admin_can_create_tag_with_name_and_slug(): void
    {
        $this->actingAsAdmin();
        $payload = $this->makeTagPayload([
            'name' => 'Queue Workers',
            'slug' => 'queue-workers',
        ]);

        $response = $this->postJson('/api/tags', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Queue Workers')
            ->assertJsonPath('data.slug', 'queue-workers')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'can' => ['update', 'delete'],
                ],
            ]);

        $this->assertDatabaseHas('tags', [
            'name' => 'Queue Workers',
            'slug' => 'queue-workers',
        ]);
    }

    public function test_admin_creating_tag_without_slug_auto_generates_slug_from_name(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/tags', ['name' => 'Hello World'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'hello-world');

        $this->assertDatabaseHas('tags', [
            'name' => 'Hello World',
            'slug' => 'hello-world',
        ]);
    }

    public function test_admin_creating_tag_with_null_slug_auto_generates_slug(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/tags', [
            'name' => 'Null Slug Tag',
            'slug' => null,
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'null-slug-tag');

        $this->assertSame('null-slug-tag', Tag::first()->slug);
    }

    public function test_admin_can_create_tag_with_slug_different_from_name(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/tags', [
            'name' => 'Display Name',
            'slug' => 'custom-slug',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'custom-slug');

        $this->assertDatabaseHas('tags', [
            'name' => 'Display Name',
            'slug' => 'custom-slug',
        ]);
    }

    public function test_store_accepts_name_at_max_length_boundary(): void
    {
        $this->actingAsAdmin();
        $name = str_repeat('a', 255);

        $this->postJson('/api/tags', ['name' => $name, 'slug' => 'max-name'])
            ->assertCreated();

        $this->assertDatabaseHas('tags', ['name' => $name]);
    }

    public function test_store_accepts_slug_at_max_length_boundary(): void
    {
        $this->actingAsAdmin();
        $slug = str_repeat('b', 255);

        $this->postJson('/api/tags', ['name' => 'Max Slug', 'slug' => $slug])
            ->assertCreated();

        $this->assertDatabaseHas('tags', ['slug' => $slug]);
    }

    public function test_store_response_includes_admin_can_permissions(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/tags', $this->makeTagPayload())
            ->assertCreated()
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', true);
    }

    #[DataProvider('invalidStorePayloadProvider')]
    public function test_store_validation_rejects_invalid_payloads(array $payload, array $errorKeys): void
    {
        $this->actingAsAdmin();
        $this->createTag(['name' => 'Existing', 'slug' => 'existing']);

        $this->postJson('/api/tags', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKeys);

        $this->assertDatabaseCount('tags', 1);
    }

    public static function invalidStorePayloadProvider(): array
    {
        return [
            'missing name' => [
                ['slug' => 'only-slug'],
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
                ['name' => 'Existing', 'slug' => 'other-slug'],
                ['name'],
            ],
            'slug not a string' => [
                ['name' => 'Valid Name', 'slug' => ['bad']],
                ['slug'],
            ],
            'slug exceeding max' => [
                ['name' => 'Valid Name', 'slug' => str_repeat('y', 256)],
                ['slug'],
            ],
            'duplicate slug' => [
                ['name' => 'Another Name', 'slug' => 'existing'],
                ['slug'],
            ],
        ];
    }

    public function test_store_empty_string_slug_is_treated_as_empty_and_auto_generated(): void
    {
        // empty('') slug passes nullable validation, then model boot regenerates from name.
        $this->actingAsAdmin();

        $this->postJson('/api/tags', [
            'name' => 'Empty Slug',
            'slug' => '',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'empty-slug');

        $this->assertSame('empty-slug', Tag::first()->slug);
    }

    public function test_auto_generated_slug_can_collide_with_existing_slug_due_to_missing_db_unique(): void
    {
        // Documents current gap: validation unique:slug only runs on request slug;
        // boot-generated slug is not re-validated, and migration has no unique index.
        $this->createTag(['name' => 'Other', 'slug' => 'hello-world']);
        $this->actingAsAdmin();

        $this->postJson('/api/tags', ['name' => 'Hello World'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'hello-world');

        $this->assertEquals(2, Tag::where('slug', 'hello-world')->count());
    }

    public function test_store_uses_str_slug_rules_for_special_characters_in_name(): void
    {
        $this->actingAsAdmin();
        $name = 'C++ & Node.js!';

        $this->postJson('/api/tags', ['name' => $name])
            ->assertCreated()
            ->assertJsonPath('data.slug', Str::slug($name));
    }
}
