<?php

namespace Tests\Feature\Tag;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTags;
use Tests\TestCase;

class TagSecurityTest extends TestCase
{
    use InteractsWithTags;
    use RefreshDatabase;

    public function test_mass_assignment_cannot_override_id_or_timestamps_on_store(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/tags', [
            'name' => 'Safe Tag',
            'slug' => 'safe-tag',
            'id' => 99999,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ])->assertCreated();

        $tag = Tag::first();

        $this->assertNotEquals(99999, $tag->id);
        $this->assertNotEquals('2000-01-01 00:00:00', $tag->created_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('tags', [
            'name' => 'Safe Tag',
            'slug' => 'safe-tag',
        ]);
    }

    public function test_mass_assignment_cannot_override_id_on_update(): void
    {
        $tag = $this->createTag(['slug' => 'mass-update']);
        $originalId = $tag->id;
        $this->actingAsAdmin();

        $this->putJson('/api/tags/mass-update', [
            'name' => 'Updated',
            'id' => 88888,
        ])->assertOk();

        $this->assertDatabaseHas('tags', [
            'id' => $originalId,
            'name' => 'Updated',
        ]);
        $this->assertDatabaseMissing('tags', ['id' => 88888]);
    }

    public function test_non_admin_cannot_bypass_authorization_via_direct_http_verbs(): void
    {
        $tag = $this->createTag(['name' => 'Locked', 'slug' => 'locked']);
        $this->actingAsUser(['level' => 13, 'role' => 'user']);

        $this->postJson('/api/tags', $this->makeTagPayload())->assertForbidden();
        $this->putJson('/api/tags/locked', ['name' => 'Hacked'])->assertForbidden();
        $this->patchJson('/api/tags/locked', ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson('/api/tags/locked')->assertForbidden();

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'Locked',
            'slug' => 'locked',
        ]);
        $this->assertDatabaseCount('tags', 1);
    }

    public function test_idor_guessing_tag_id_does_not_resolve_when_routes_are_slug_scoped(): void
    {
        $tag = $this->createTag(['slug' => 'secret-tag']);
        $this->actingAsAdmin();

        $this->putJson("/api/tags/{$tag->id}", ['name' => 'Via Id'])->assertNotFound();
        $this->deleteJson("/api/tags/{$tag->id}")->assertNotFound();

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => 'secret-tag',
        ]);
    }

    public function test_sql_injection_in_slug_path_does_not_mutate_or_leak_tags(): void
    {
        $this->createTag(['name' => 'Intact', 'slug' => 'intact']);

        $this->getJson("/api/tags/1'%20OR%20'1'='1")
            ->assertNotFound();

        $this->getJson("/api/tags/intact'%20OR%20'1'='1/questions")
            ->assertNotFound();

        $this->assertDatabaseCount('tags', 1);
        $this->assertDatabaseHas('tags', ['slug' => 'intact', 'name' => 'Intact']);
    }

    public function test_sql_injection_in_index_query_is_parameterized_and_does_not_return_all_rows(): void
    {
        $this->createTag(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->createTag(['name' => 'Beta', 'slug' => 'beta']);

        $this->getJson('/api/tags?query='.urlencode("' OR 1=1 --"))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_xss_payload_in_name_is_stored_as_plain_text_and_json_encoded(): void
    {
        $this->actingAsAdmin();
        $payload = '<script>alert("xss")</script>';

        $response = $this->postJson('/api/tags', [
            'name' => $payload,
            'slug' => 'xss-tag',
        ])->assertCreated();

        $this->assertDatabaseHas('tags', [
            'name' => $payload,
            'slug' => 'xss-tag',
        ]);

        // Stored and returned as plain text; JSON content-type is not an HTML execution context.
        $this->assertSame($payload, $response->json('data.name'));
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function test_tag_resource_does_not_expose_sensitive_user_fields(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'access_token' => 'secret-access',
            'refresh_token' => 'secret-refresh',
        ]);
        $this->createTag(['slug' => 'no-secrets']);
        Sanctum::actingAs($admin);

        $encoded = json_encode(
            $this->getJson('/api/tags/no-secrets')->assertOk()->json()
        );

        $this->assertStringNotContainsString('secret-access', $encoded);
        $this->assertStringNotContainsString('secret-refresh', $encoded);
        $this->assertStringNotContainsString('admin@example.com', $encoded);
    }

    #[DataProvider('mutatingEndpointsProvider')]
    public function test_unauthenticated_requests_to_mutating_endpoints_return_401(string $method, string $uri): void
    {
        $this->createTag(['slug' => 'auth-wall']);

        $this->json($method, $uri, $this->makeTagPayload())
            ->assertUnauthorized();
    }

    public static function mutatingEndpointsProvider(): array
    {
        return [
            'store' => ['POST', '/api/tags'],
            'update put' => ['PUT', '/api/tags/auth-wall'],
            'update patch' => ['PATCH', '/api/tags/auth-wall'],
            'destroy' => ['DELETE', '/api/tags/auth-wall'],
        ];
    }

    public function test_can_permissions_reflect_policy_for_guest_user_and_admin(): void
    {
        $this->createTag(['slug' => 'perms']);

        $this->getJson('/api/tags/perms')
            ->assertOk()
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.delete', false);

        $this->actingAsUser();
        $this->getJson('/api/tags/perms')
            ->assertOk()
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.delete', false);

        $this->actingAsAdmin();
        $this->getJson('/api/tags/perms')
            ->assertOk()
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', true);
    }

    public function test_role_user_string_admin_is_required_not_level(): void
    {
        // High level alone must not grant tag admin capabilities.
        $this->createTag(['slug' => 'level-check']);
        $this->actingAsUser(['level' => 13, 'role' => 'user']);

        $this->deleteJson('/api/tags/level-check')->assertForbidden();
        $this->assertDatabaseHas('tags', ['slug' => 'level-check']);
    }
}
