<?php

namespace Tests\Feature\Auth;

use App\Jobs\FetchUserLevel;
use App\Models\User;
use App\Notifications\LoginNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOAuth;
use Tests\TestCase;

class AuthCallbackTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithOAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOAuth();
        Bus::fake([FetchUserLevel::class]);
        Notification::fake();
    }

    public function test_callback_creates_new_user_issues_token_and_redirects_with_fragment(): void
    {
        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp([
            'email' => 'new.user@example.com',
            'name' => 'New User',
            'mobile' => '09120000000',
            'code' => '654321',
            'email_verified_at' => now()->toISOString(),
        ]);

        $response = $this->hitOAuthCallback($state);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith($this->frontendAppUrl.'#token=', $location);

        $user = $this->findUserByEmail('new.user@example.com');
        $this->assertNotNull($user);
        $this->assertSame('New User', $user->name);
        $this->assertSame('09120000000', $user->mobile);
        $this->assertSame('654321', $user->code);
        $this->assertNotEmpty($user->username);
        $this->assertSame('oauth-access-token', $user->access_token);
        $this->assertSame('oauth-refresh-token', $user->refresh_token);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertAuthenticatedAs($user, 'web');

        Bus::assertDispatched(FetchUserLevel::class, function (FetchUserLevel $job) use ($user) {
            return true;
        });
        Notification::assertNothingSent();
    }

    public function test_callback_updates_existing_user_by_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'existing@example.com',
            'name' => 'Old Name',
            'mobile' => '09000000000',
            'code' => '111111',
            'score' => 50,
        ]);

        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp([
            'email' => 'existing@example.com',
            'name' => 'Updated Name',
            'mobile' => '09111111111',
            'code' => '222222',
            'email_verified_at' => $existing->email_verified_at?->toISOString() ?? now()->toISOString(),
        ]);

        $this->hitOAuthCallback($state)->assertRedirect();

        $existing->refresh();
        $this->assertSame('Updated Name', $existing->name);
        $this->assertSame('09111111111', $existing->mobile);
        $this->assertSame('222222', $existing->code);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_callback_generates_username_when_missing(): void
    {
        $user = User::factory()->withoutUsername()->create([
            'email' => 'nousername@example.com',
            'name' => 'Alice Wonder',
        ]);

        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp([
            'email' => 'nousername@example.com',
            'name' => 'Alice Wonder',
            'email_verified_at' => $user->email_verified_at?->toISOString(),
        ]);

        $this->hitOAuthCallback($state)->assertRedirect();

        $user->refresh();
        $this->assertNotEmpty($user->username);
        $this->assertStringContainsString('alice', strtolower($user->username));
    }

    public function test_callback_preserves_existing_username(): void
    {
        $user = User::factory()->create([
            'email' => 'keep@example.com',
            'username' => 'custom-username',
            'name' => 'Keep Name',
        ]);

        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp([
            'email' => 'keep@example.com',
            'name' => 'Keep Name',
            'email_verified_at' => $user->email_verified_at?->toISOString(),
        ]);

        $this->hitOAuthCallback($state)->assertRedirect();

        $this->assertSame('custom-username', $user->fresh()->username);
    }

    public function test_callback_increments_score_when_email_verified_at_changes(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'verify@example.com',
            'score' => 20,
        ]);

        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp([
            'email' => 'verify@example.com',
            'name' => $user->name,
            'email_verified_at' => now()->toISOString(),
        ]);

        $this->hitOAuthCallback($state)->assertRedirect();

        $this->assertSame(30, $user->fresh()->score);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_callback_does_not_increment_score_when_email_verified_at_unchanged(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'stable@example.com',
            'score' => 40,
        ]);

        $state = $this->seedOAuthState();
        $verifiedAt = '2024-06-15T10:00:00.000000Z';

        $this->fakeSuccessfulOAuthHttp([
            'email' => 'stable@example.com',
            'name' => $user->name,
            'email_verified_at' => $verifiedAt,
        ]);

        // First login verifies email and awards +10
        $this->hitOAuthCallback($state)->assertRedirect();
        $this->assertSame(50, $user->fresh()->score);

        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp([
            'email' => 'stable@example.com',
            'name' => $user->name,
            'email_verified_at' => $user->fresh()->email_verified_at->toISOString(),
        ]);

        $this->hitOAuthCallback($state)->assertRedirect();

        $this->assertSame(50, $user->fresh()->score);
    }

    public function test_login_notification_is_sent_when_enabled(): void
    {
        $user = User::factory()->withLoginNotification()->create([
            'email' => 'notify@example.com',
        ]);

        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp([
            'email' => 'notify@example.com',
            'name' => $user->name,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
        ]);

        $this->hitOAuthCallback($state)->assertRedirect();

        Notification::assertSentTo($user, LoginNotification::class, function (LoginNotification $notification) {
            return isset($notification->loginData['ip_address'])
                && isset($notification->loginData['user_agent']);
        });
    }

    public function test_login_notification_is_not_sent_when_disabled(): void
    {
        $user = User::factory()->create([
            'email' => 'silent@example.com',
            'login_notification_enabled' => false,
        ]);

        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp([
            'email' => 'silent@example.com',
            'name' => $user->name,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
        ]);

        $this->hitOAuthCallback($state)->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_fetch_user_level_job_is_dispatched(): void
    {
        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp(['email' => 'level@example.com']);

        $this->hitOAuthCallback($state)->assertRedirect();

        Bus::assertDispatched(FetchUserLevel::class);
    }

    public function test_callback_uses_intended_url_from_session_when_safe(): void
    {
        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp(['email' => 'intended@example.com']);

        $intended = $this->frontendAppUrl.'/questions/42';

        $response = $this->withSession([
            'oauth_intended_url' => $intended,
            'redirect_depth' => 1,
        ])->get('/auth/callback?'.http_build_query([
            'state' => $state,
            'code' => 'auth-code',
        ]));

        $response->assertRedirect();
        $this->assertStringStartsWith($intended.'#token=', $response->headers->get('Location'));
        $response->assertSessionMissing('oauth_intended_url');
        $response->assertSessionMissing('redirect_depth');
    }

    public function test_callback_falls_back_to_app_url_for_dangerous_intended_url(): void
    {
        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp(['email' => 'danger@example.com']);

        $response = $this->withSession([
            'oauth_intended_url' => 'https://evil.example.com/phish',
        ])->get('/auth/callback?'.http_build_query([
            'state' => $state,
            'code' => 'auth-code',
        ]));

        $response->assertRedirect();
        $this->assertStringStartsWith($this->frontendAppUrl.'#token=', $response->headers->get('Location'));
    }

    public function test_callback_rejects_mismatched_oauth_state(): void
    {
        $this->seedOAuthState('expected-state-value-abcdefghijklmnop');
        $this->fakeSuccessfulOAuthHttp();

        $this->withoutExceptionHandling();

        $this->expectException(\InvalidArgumentException::class);

        $this->get('/auth/callback?'.http_build_query([
            'state' => 'tampered-state-value',
            'code' => 'auth-code',
        ]));
    }

    public function test_callback_rejects_missing_oauth_state(): void
    {
        $this->fakeSuccessfulOAuthHttp();
        $this->withoutExceptionHandling();

        $this->expectException(\InvalidArgumentException::class);

        $this->get('/auth/callback?'.http_build_query([
            'state' => 'any-state',
            'code' => 'auth-code',
        ]));
    }

    public function test_oauth_state_is_single_use_and_pulled_from_cache(): void
    {
        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp(['email' => 'once@example.com']);

        $this->hitOAuthCallback($state)->assertRedirect();
        $this->assertNull(Cache::get('oauth_state'));
    }

    public function test_sanctum_token_is_created_with_one_hour_expiry(): void
    {
        $this->travelTo(now());

        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp(['email' => 'token@example.com']);

        $this->hitOAuthCallback($state)->assertRedirect();

        $token = PersonalAccessToken::query()->first();
        $this->assertNotNull($token);
        $this->assertSame('auth-token', $token->name);
        $this->assertNotNull($token->expires_at);
        $this->assertEqualsWithDelta(
            now()->addHour()->timestamp,
            $token->expires_at->timestamp,
            2
        );
    }

    public function test_token_appears_only_in_url_fragment_not_query_string(): void
    {
        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp(['email' => 'fragment@example.com']);

        $location = $this->hitOAuthCallback($state)->headers->get('Location');

        $this->assertStringContainsString('#token=', $location);
        $this->assertStringNotContainsString('?token=', $location);
        $parsed = parse_url($location);
        $this->assertArrayNotHasKey('query', $parsed);
        $this->assertArrayHasKey('fragment', $parsed);
        $this->assertStringStartsWith('token=', $parsed['fragment']);
    }

    public function test_mass_assignment_does_not_set_role_or_score_from_oauth_payload(): void
    {
        $state = $this->seedOAuthState();
        $this->fakeSuccessfulOAuthHttp([
            'email' => 'mass@example.com',
            'name' => 'Mass User',
            'role' => 'admin',
            'score' => 9999,
            'level' => 13,
        ]);

        $this->hitOAuthCallback($state)->assertRedirect();

        $user = $this->findUserByEmail('mass@example.com');
        $this->assertSame('user', $user->role);
        $this->assertNotSame(9999, $user->score);
        $this->assertSame(1, $user->level);
    }

    #[DataProvider('httpFailureProvider')]
    public function test_callback_handles_http_client_failures(string $scenario): void
    {
        $state = $this->seedOAuthState();

        if ($scenario === 'token') {
            Http::fake([
                $this->oauthServerUrl.'/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
                $this->oauthServerUrl.'/api/user' => Http::response(['error' => 'unauthorized'], 401),
            ]);
        } else {
            Http::fake([
                $this->oauthServerUrl.'/oauth/token' => Http::response([
                    'access_token' => 'tok',
                    'refresh_token' => 'ref',
                    'expires_in' => now()->addHour()->timestamp,
                    'token_type' => 'Bearer',
                ], 200),
                $this->oauthServerUrl.'/api/user' => Http::response(['error' => 'unauthorized'], 401),
            ]);
        }

        $this->withoutExceptionHandling();
        $this->expectException(\Throwable::class);

        $this->hitOAuthCallback($state);
    }

    public static function httpFailureProvider(): array
    {
        return [
            'token endpoint failure' => ['token'],
            'user endpoint failure' => ['user'],
        ];
    }
}
