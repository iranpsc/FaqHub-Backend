<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_logout_and_tokens_are_revoked(): void
    {
        $user = User::factory()->create();
        $user->createToken('auth-token');
        $user->createToken('second-token');

        $this->assertDatabaseCount('personal_access_tokens', 2);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/logout');

        $response->assertOk()
            ->assertJson(['message' => 'Logged out successfully']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertGuest('web');
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');
        Sanctum::actingAs($user);

        $this->withSession(['foo' => 'bar'])
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertGuest('web');
    }

    public function test_guest_cannot_logout(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    public function test_revoked_token_cannot_access_protected_routes_after_logout(): void
    {
        $user = User::factory()->create();
        $plainTextToken = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->withToken($plainTextToken)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $this->withToken($plainTextToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_logout_only_affects_current_users_tokens_not_other_users(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $user->createToken('auth-token');
        $other->createToken('other-token');

        Sanctum::actingAs($user);
        $this->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $other->id,
        ]);
    }
}
