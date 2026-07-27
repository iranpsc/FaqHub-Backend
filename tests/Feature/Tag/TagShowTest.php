<?php

namespace Tests\Feature\Tag;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTags;
use Tests\TestCase;

class TagShowTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTags;

    public function test_guest_can_show_tag_by_slug(): void
    {
        $tag = $this->createTag(['name' => 'Sanctum', 'slug' => 'sanctum']);

        $this->getJson('/api/tags/sanctum')
            ->assertOk()
            ->assertJsonPath('data.id', $tag->id)
            ->assertJsonPath('data.name', 'Sanctum')
            ->assertJsonPath('data.slug', 'sanctum')
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.delete', false)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'can' => ['update', 'delete'],
                ],
            ]);
    }

    public function test_show_omits_questions_count_when_not_eager_counted(): void
    {
        $this->createTag(['slug' => 'no-count']);

        $payload = $this->getJson('/api/tags/no-count')->assertOk()->json('data');

        $this->assertArrayNotHasKey('questions_count', $payload);
    }

    public function test_show_returns_404_for_unknown_slug(): void
    {
        $this->getJson('/api/tags/does-not-exist')->assertNotFound();
    }

    public function test_show_returns_404_when_looking_up_by_numeric_id_instead_of_slug(): void
    {
        $tag = $this->createTag(['slug' => 'by-slug-only']);

        $this->getJson("/api/tags/{$tag->id}")->assertNotFound();
    }

    public function test_authenticated_admin_sees_can_permissions_on_show(): void
    {
        $this->createTag(['slug' => 'admin-view']);
        $this->actingAsAdmin();

        $this->getJson('/api/tags/admin-view')
            ->assertOk()
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', true);
    }

    public function test_authenticated_non_admin_sees_false_can_permissions_on_show(): void
    {
        $this->createTag(['slug' => 'user-view']);
        $this->actingAsUser();

        $this->getJson('/api/tags/user-view')
            ->assertOk()
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.delete', false);
    }

    public function test_show_does_not_expose_timestamps(): void
    {
        $this->createTag(['slug' => 'clean-resource']);

        $payload = $this->getJson('/api/tags/clean-resource')->assertOk()->json('data');

        $this->assertArrayNotHasKey('created_at', $payload);
        $this->assertArrayNotHasKey('updated_at', $payload);
    }
}
