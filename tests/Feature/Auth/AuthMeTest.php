<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_receives_expected_me_payload(): void
    {
        $user = User::factory()->withImage('avatars/me.jpg')->withLoginNotification()->create([
            'name' => 'Profile User',
            'username' => 'profile-user',
            'code' => '998877',
            'level' => 5,
            'score' => 120,
            'role' => 'user',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'name' => 'Profile User',
                'username' => 'profile-user',
                'code' => '998877',
                'level' => 5,
                'score' => 120,
                'image_url' => asset('storage/avatars/me.jpg'),
                'role' => 'user',
                'login_notification_enabled' => true,
            ]);
    }

    public function test_me_does_not_expose_sensitive_oauth_tokens(): void
    {
        $user = User::factory()->create([
            'access_token' => 'super-secret-access',
            'refresh_token' => 'super-secret-refresh',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk();
        $response->assertJsonMissing(['access_token' => 'super-secret-access']);
        $response->assertJsonMissing(['refresh_token' => 'super-secret-refresh']);
        $this->assertArrayNotHasKey('access_token', $response->json());
        $this->assertArrayNotHasKey('refresh_token', $response->json());
        $this->assertArrayNotHasKey('email', $response->json());
        $this->assertArrayNotHasKey('mobile', $response->json());
    }

    public function test_guest_cannot_access_me(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_reflects_null_image_url_when_user_has_no_avatar(): void
    {
        $user = User::factory()->create(['image' => null]);

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJson(['image_url' => null]);
    }

    public function test_expired_token_cannot_access_me(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth-token', ['*'], now()->subMinute())->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }
}
