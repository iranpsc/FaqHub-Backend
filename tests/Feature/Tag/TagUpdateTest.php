<?php

namespace Tests\Feature\Tag;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTags;
use Tests\TestCase;

class TagUpdateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTags;

    public function test_guest_cannot_update_tag(): void
    {
        $tag = $this->createTag(['name' => 'Original', 'slug' => 'original']);

        $this->putJson('/api/tags/original', ['name' => 'Changed'])
            ->assertUnauthorized();

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'Original',
            'slug' => 'original',
        ]);
    }

    public function test_authenticated_non_admin_cannot_update_tag(): void
    {
        $tag = $this->createTag(['name' => 'Original', 'slug' => 'original']);
        $this->actingAsUser();

        $this->putJson('/api/tags/original', ['name' => 'Changed'])
            ->assertForbidden();

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'Original',
        ]);
    }

    public function test_admin_can_update_tag_name_and_slug(): void
    {
        $tag = $this->createTag(['name' => 'Old Name', 'slug' => 'old-name']);
        $this->actingAsAdmin();

        $this->putJson('/api/tags/old-name', [
            'name' => 'New Name',
            'slug' => 'new-name',
        ])->assertOk()
            ->assertJsonPath('data.id', $tag->id)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.slug', 'new-name');

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'New Name',
            'slug' => 'new-name',
        ]);
        $this->assertDatabaseMissing('tags', ['slug' => 'old-name']);
    }

    public function test_admin_can_partially_update_name_without_changing_slug(): void
    {
        // Model only regenerates slug when name is dirty AND slug is empty.
        $tag = $this->createTag(['name' => 'Old Name', 'slug' => 'kept-slug']);
        $this->actingAsAdmin();

        $this->putJson('/api/tags/kept-slug', ['name' => 'Renamed Only'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Only')
            ->assertJsonPath('data.slug', 'kept-slug');

        $this->assertSame('kept-slug', $tag->fresh()->slug);
    }

    public function test_admin_can_update_slug_only(): void
    {
        $tag = $this->createTag(['name' => 'Same Name', 'slug' => 'old-slug']);
        $this->actingAsAdmin();

        $this->putJson('/api/tags/old-slug', ['slug' => 'brand-new-slug'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Same Name')
            ->assertJsonPath('data.slug', 'brand-new-slug');

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'slug' => 'brand-new-slug',
        ]);
    }

    public function test_update_with_empty_payload_succeeds_due_to_sometimes_rules(): void
    {
        $tag = $this->createTag(['name' => 'Unchanged', 'slug' => 'unchanged']);
        $this->actingAsAdmin();

        $this->putJson('/api/tags/unchanged', [])
            ->assertOk()
            ->assertJsonPath('data.name', 'Unchanged')
            ->assertJsonPath('data.slug', 'unchanged');

        $this->assertEquals($tag->updated_at->toISOString(), $tag->fresh()->updated_at->toISOString());
    }

    public function test_update_allows_keeping_same_name_and_slug(): void
    {
        $this->createTag(['name' => 'Keep Me', 'slug' => 'keep-me']);
        $this->actingAsAdmin();

        $this->putJson('/api/tags/keep-me', [
            'name' => 'Keep Me',
            'slug' => 'keep-me',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Keep Me')
            ->assertJsonPath('data.slug', 'keep-me');
    }

    public function test_updating_name_with_empty_slug_regenerates_slug(): void
    {
        $tag = $this->createTag(['name' => 'Before', 'slug' => 'before']);
        $this->actingAsAdmin();

        $this->putJson('/api/tags/before', [
            'name' => 'After Update',
            'slug' => '',
        ])->assertOk();

        $this->assertSame('after-update', $tag->fresh()->slug);
    }

    public function test_update_returns_404_for_unknown_slug(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/tags/missing', ['name' => 'Nope'])
            ->assertNotFound();
    }

    #[DataProvider('invalidUpdatePayloadProvider')]
    public function test_update_validation_rejects_invalid_payloads(array $payload, array $errorKeys): void
    {
        $this->createTag(['name' => 'Taken Name', 'slug' => 'taken-slug']);
        $target = $this->createTag(['name' => 'Target', 'slug' => 'target']);
        $this->actingAsAdmin();

        $this->putJson('/api/tags/target', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKeys);

        $this->assertDatabaseHas('tags', [
            'id' => $target->id,
            'name' => 'Target',
            'slug' => 'target',
        ]);
    }

    public static function invalidUpdatePayloadProvider(): array
    {
        return [
            'null name when present' => [
                ['name' => null],
                ['name'],
            ],
            'empty name when present' => [
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
            'slug not a string' => [
                ['slug' => ['nope']],
                ['slug'],
            ],
            'slug exceeding max' => [
                ['slug' => str_repeat('s', 256)],
                ['slug'],
            ],
            'duplicate slug' => [
                ['slug' => 'taken-slug'],
                ['slug'],
            ],
        ];
    }

    public function test_update_accepts_name_at_max_length_boundary(): void
    {
        $this->createTag(['slug' => 'boundary']);
        $this->actingAsAdmin();
        $name = str_repeat('n', 255);

        $this->putJson('/api/tags/boundary', ['name' => $name])
            ->assertOk();

        $this->assertDatabaseHas('tags', ['slug' => 'boundary', 'name' => $name]);
    }

    public function test_after_slug_change_old_slug_route_returns_404(): void
    {
        $this->createTag(['slug' => 'old-route']);
        $this->actingAsAdmin();

        $this->putJson('/api/tags/old-route', ['slug' => 'new-route'])
            ->assertOk();

        $this->getJson('/api/tags/old-route')->assertNotFound();
        $this->getJson('/api/tags/new-route')->assertOk();
    }
}
