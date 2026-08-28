<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOAuth;
use Tests\TestCase;

class AuthRedirectTest extends TestCase
{
    use InteractsWithOAuth;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOAuth();
    }

    public function test_guest_receives_oauth_authorize_redirect_url(): void
    {
        $response = $this->postJson('/api/auth/redirect');

        $response->assertOk()
            ->assertJsonStructure(['redirect_url']);

        $redirectUrl = $response->json('redirect_url');

        $this->assertStringStartsWith($this->oauthServerUrl.'/oauth/authorize?', $redirectUrl);
        $this->assertStringContainsString('client_id=test-client-id', $redirectUrl);
        $this->assertStringContainsString('response_type=code', $redirectUrl);
        $this->assertStringContainsString('redirect_uri=', $redirectUrl);
        $this->assertStringContainsString('state=', $redirectUrl);
        $this->assertNotEmpty(Cache::get('oauth_state'));
    }

    public function test_redirect_stores_oauth_state_in_cache(): void
    {
        $response = $this->postJson('/api/auth/redirect')->assertOk();

        $state = Cache::get('oauth_state');

        $this->assertIsString($state);
        $this->assertSame(40, strlen($state));
        $this->assertStringContainsString('state='.$state, $response->json('redirect_url'));
    }

    public function test_valid_intended_url_is_stored_in_session(): void
    {
        $intended = $this->frontendAppUrl.'/questions/welcome';

        $response = $this->postJson('/api/auth/redirect', [
            'intended_url' => $intended,
        ]);

        $response->assertOk();
        $response->assertSessionHas('oauth_intended_url', $intended);
        $response->assertSessionHas('redirect_depth', 1);
    }

    #[DataProvider('invalidIntendedUrlProvider')]
    public function test_invalid_intended_url_is_rejected_and_not_stored(string $intendedUrl): void
    {
        $response = $this->postJson('/api/auth/redirect', [
            'intended_url' => $intendedUrl,
        ]);

        $response->assertOk();
        $response->assertSessionMissing('oauth_intended_url');
        $this->assertNull(session('oauth_intended_url'));
    }

    public static function invalidIntendedUrlProvider(): array
    {
        return [
            'external domain open redirect' => ['https://evil.example.com/steal'],
            'auth callback loop path' => ['https://faqhub.test/auth/callback'],
            'api auth redirect loop path' => ['https://faqhub.test/api/auth/redirect'],
            'oauth authorize path' => ['https://faqhub.test/oauth/authorize'],
            'login path' => ['https://faqhub.test/login'],
            'logout path' => ['https://faqhub.test/logout'],
            'malformed url' => ['not-a-url'],
            'empty string' => [''],
            'javascript scheme' => ['javascript:alert(1)'],
            'data uri' => ['data:text/html,<script>alert(1)</script>'],
        ];
    }

    public function test_redirect_depth_returns_429_after_three_valid_intended_urls(): void
    {
        $intended = $this->frontendAppUrl.'/dashboard';

        $this->withSession(['redirect_depth' => 3])
            ->postJson('/api/auth/redirect', ['intended_url' => $intended])
            ->assertStatus(429)
            ->assertJson([
                'error' => 'Too many redirect attempts. Please try again later.',
            ]);
    }

    public function test_redirect_depth_below_limit_still_succeeds(): void
    {
        $this->withSession(['redirect_depth' => 2])
            ->postJson('/api/auth/redirect', [
                'intended_url' => $this->frontendAppUrl.'/ok',
            ])
            ->assertOk()
            ->assertJsonStructure(['redirect_url']);
    }

    public function test_authenticated_web_user_is_blocked_by_guest_middleware(): void
    {
        RedirectIfAuthenticated::redirectUsing(fn () => '/');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/auth/redirect');

        $response->assertRedirect('/');
    }

    public function test_sql_injection_payload_with_valid_host_is_still_sanitized_as_same_domain_url(): void
    {
        // Host matches frontend domain so URL is accepted; path is not executed as SQL
        $payload = "https://faqhub.test/' OR 1=1 --";

        $response = $this->postJson('/api/auth/redirect', [
            'intended_url' => $payload,
        ]);

        $response->assertOk()->assertJsonStructure(['redirect_url']);
        $this->assertSame($payload, session('oauth_intended_url'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_xss_payload_in_intended_url_is_not_stored_when_host_mismatches(): void
    {
        $response = $this->postJson('/api/auth/redirect', [
            'intended_url' => 'https://evil.test/<script>alert(1)</script>',
        ]);

        $response->assertOk();
        $this->assertNull(session('oauth_intended_url'));
    }
}
