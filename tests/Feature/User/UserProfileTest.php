<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_own_profile(): void
    {
        $user = User::factory()->withImage('avatars/profile.jpg')->withLoginNotification()->create([
            'name' => 'Abbas',
            'email' => 'abbas@example.com',
            'mobile' => '09120001122',
            'score' => 250,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/profile');

        $response->assertOk()
            ->assertJson([
                'id' => $user->id,
                'name' => 'Abbas',
                'email' => 'abbas@example.com',
                'mobile' => '09120001122',
                'image' => asset('storage/avatars/profile.jpg'),
                'score' => 250,
                'login_notification_enabled' => true,
            ])
            ->assertJsonStructure([
                'id',
                'name',
                'email',
                'mobile',
                'image',
                'score',
                'online',
                'login_notification_enabled',
                'created_at',
            ]);
    }

    public function test_guest_cannot_view_profile(): void
    {
        $this->getJson('/api/user/profile')->assertUnauthorized();
    }

    public function test_profile_does_not_expose_oauth_tokens_or_role(): void
    {
        $user = User::factory()->create([
            'access_token' => 'secret-access',
            'refresh_token' => 'secret-refresh',
            'role' => 'admin',
        ]);

        Sanctum::actingAs($user);

        $payload = $this->getJson('/api/user/profile')->assertOk()->json();

        $this->assertArrayNotHasKey('access_token', $payload);
        $this->assertArrayNotHasKey('refresh_token', $payload);
        $this->assertArrayNotHasKey('role', $payload);
        $this->assertArrayNotHasKey('password', $payload);
    }

    public function test_profile_always_returns_authenticated_user_not_another_user_idor(): void
    {
        $attacker = User::factory()->create(['name' => 'Attacker']);
        $victim = User::factory()->create(['name' => 'Victim', 'email' => 'victim@example.com']);

        Sanctum::actingAs($attacker);

        // No user id in route — attempting to pass victim id as query must not leak victim data
        $response = $this->getJson('/api/user/profile?id='.$victim->id.'&user_id='.$victim->id);

        $response->assertOk()
            ->assertJson([
                'id' => $attacker->id,
                'name' => 'Attacker',
            ])
            ->assertJsonMissing(['email' => 'victim@example.com']);
    }

    public function test_profile_defaults_score_and_notification_flag_when_nullish(): void
    {
        $user = User::factory()->create([
            'score' => 0,
            'login_notification_enabled' => false,
            'image' => null,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/user/profile')
            ->assertOk()
            ->assertJson([
                'score' => 0,
                'online' => false,
                'login_notification_enabled' => false,
                'image' => null,
            ]);
    }

    public function test_there_is_no_endpoint_to_update_name_or_email_via_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Original',
            'email' => 'original@example.com',
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/user/profile', [
            'name' => 'Hacked',
            'email' => 'hacked@example.com',
        ])->assertMethodNotAllowed();

        $this->patchJson('/api/user/profile', [
            'name' => 'Hacked',
        ])->assertMethodNotAllowed();

        $user->refresh();
        $this->assertSame('Original', $user->name);
        $this->assertSame('original@example.com', $user->email);
    }
}
