<?php

namespace Tests\Feature\Category;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCategories;
use Tests\TestCase;

class CategorySecurityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithCategories;

    public function test_mass_assignment_cannot_override_id_or_timestamps_on_store(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/categories', [
            'name' => 'Safe Category',
            'id' => 99999,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
            'last_activity' => '1999-01-01 00:00:00',
        ])->assertCreated();

        $category = Category::first();

        $this->assertNotEquals(99999, $category->id);
        $this->assertNotEquals('2000-01-01 00:00:00', $category->created_at?->format('Y-m-d H:i:s'));
        // last_activity is fillable but controller only passes name/slug/parent_id.
        $this->assertNotEquals('1999-01-01 00:00:00', $category->last_activity?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('categories', [
            'name' => 'Safe Category',
            'slug' => 'safe-category',
        ]);
    }

    public function test_mass_assignment_cannot_override_id_on_update(): void
    {
        $category = $this->createCategory(['slug' => 'mass-update', 'name' => 'Mass Update']);
        $originalId = $category->id;
        $this->actingAsAdmin();

        $this->putJson('/api/categories/mass-update', [
            'name' => 'Updated',
            'id' => 88888,
        ])->assertOk();

        $this->assertDatabaseHas('categories', [
            'id' => $originalId,
            'name' => 'Updated',
            'slug' => 'updated',
        ]);
        $this->assertDatabaseMissing('categories', ['id' => 88888]);
    }

    public function test_non_admin_cannot_bypass_authorization_via_direct_http_verbs(): void
    {
        $category = $this->createCategory(['name' => 'Locked', 'slug' => 'locked']);
        $this->actingAsUser(['level' => 13, 'role' => 'user']);

        $this->postJson('/api/categories', $this->makeCategoryPayload())->assertForbidden();
        $this->putJson('/api/categories/locked', ['name' => 'Hacked'])->assertForbidden();
        $this->patchJson('/api/categories/locked', ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson('/api/categories/locked')->assertForbidden();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Locked',
            'slug' => 'locked',
        ]);
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_idor_guessing_category_id_does_not_resolve_when_routes_are_slug_scoped(): void
    {
        $category = $this->createCategory(['slug' => 'secret-category']);
        $this->actingAsAdmin();

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Via Id'])->assertNotFound();
        $this->deleteJson("/api/categories/{$category->id}")->assertNotFound();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => 'secret-category',
        ]);
    }

    public function test_sql_injection_in_slug_path_does_not_mutate_or_leak_categories(): void
    {
        $this->createCategory(['name' => 'Intact', 'slug' => 'intact']);

        $this->getJson("/api/categories/1'%20OR%20'1'='1")
            ->assertNotFound();

        $this->getJson("/api/categories/intact'%20OR%20'1'='1/questions")
            ->assertNotFound();

        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseHas('categories', ['slug' => 'intact', 'name' => 'Intact']);
    }

    public function test_sql_injection_in_index_query_is_parameterized_and_does_not_return_all_rows(): void
    {
        $this->createCategory(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->createCategory(['name' => 'Beta', 'slug' => 'beta']);

        $this->getJson('/api/categories?query='.urlencode("' OR 1=1 --"))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_xss_payload_in_name_is_stored_as_plain_text_and_json_encoded(): void
    {
        $this->actingAsAdmin();
        $payload = '<script>alert("xss")</script>';

        $response = $this->postJson('/api/categories', [
            'name' => $payload,
        ])->assertCreated();

        $this->assertDatabaseHas('categories', [
            'name' => $payload,
        ]);

        // Stored and returned as plain text; JSON content-type is not an HTML execution context.
        $this->assertSame($payload, $response->json('data.name'));
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function test_category_resource_does_not_expose_sensitive_user_fields(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'access_token' => 'secret-access',
            'refresh_token' => 'secret-refresh',
        ]);
        $this->createCategory(['slug' => 'no-secrets']);
        Sanctum::actingAs($admin);

        $encoded = json_encode(
            $this->getJson('/api/categories/no-secrets')->assertOk()->json()
        );

        $this->assertStringNotContainsString('secret-access', $encoded);
        $this->assertStringNotContainsString('secret-refresh', $encoded);
        $this->assertStringNotContainsString('admin@example.com', $encoded);
    }

    #[DataProvider('mutatingEndpointsProvider')]
    public function test_unauthenticated_requests_to_mutating_endpoints_return_401(string $method, string $uri): void
    {
        $this->createCategory(['slug' => 'auth-wall', 'name' => 'Auth Wall']);

        $this->json($method, $uri, $this->makeCategoryPayload())
            ->assertUnauthorized();
    }

    public static function mutatingEndpointsProvider(): array
    {
        return [
            'store' => ['POST', '/api/categories'],
            'update put' => ['PUT', '/api/categories/auth-wall'],
            'update patch' => ['PATCH', '/api/categories/auth-wall'],
            'destroy' => ['DELETE', '/api/categories/auth-wall'],
        ];
    }

    public function test_can_permissions_reflect_policy_for_guest_user_and_admin(): void
    {
        $this->createCategory(['slug' => 'perms']);

        $this->getJson('/api/categories/perms')
            ->assertOk()
            ->assertJsonPath('data.can.view', false)
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.delete', false);

        $this->actingAsUser();
        $this->getJson('/api/categories/perms')
            ->assertOk()
            ->assertJsonPath('data.can.view', true)
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.delete', false);

        $this->actingAsAdmin();
        $this->getJson('/api/categories/perms')
            ->assertOk()
            ->assertJsonPath('data.can.view', true)
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', true);
    }

    public function test_role_user_string_admin_is_required_not_level(): void
    {
        // High level alone must not grant category admin capabilities.
        $this->createCategory(['slug' => 'level-check']);
        $this->actingAsUser(['level' => 13, 'role' => 'user']);

        $this->deleteJson('/api/categories/level-check')->assertForbidden();
        $this->assertDatabaseHas('categories', ['slug' => 'level-check']);
    }

    public function test_parent_id_cannot_reference_soft_deleted_parent_because_categories_are_hard_deleted(): void
    {
        // Soft-deleted records are not applicable; deleted parents are hard-removed via cascade.
        $parent = $this->createCategory(['slug' => 'gone-parent']);
        $this->actingAsAdmin();
        $this->deleteJson('/api/categories/gone-parent')->assertNoContent();

        $this->postJson('/api/categories', [
            'name' => 'Orphan Attempt',
            'parent_id' => $parent->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_circular_parent_assignment_to_descendant_is_currently_allowed(): void
    {
        // Documents gap: no cycle detection — child can become parent of its ancestor.
        $root = $this->createCategory(['name' => 'Root', 'slug' => 'root']);
        $child = $this->createChildCategory($root, ['name' => 'Child', 'slug' => 'child']);
        $this->actingAsAdmin();

        $this->putJson('/api/categories/root', [
            'name' => 'Root',
            'parent_id' => $child->id,
        ])->assertOk();

        $this->assertDatabaseHas('categories', [
            'id' => $root->id,
            'parent_id' => $child->id,
        ]);
    }
}
