<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait InteractsWithOAuth
{
    protected string $oauthServerUrl = 'https://oauth.test';

    protected string $frontendAppUrl = 'https://faqhub.test';

    protected function configureOAuth(): void
    {
        Config::set('services.oauth', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost/auth/callback',
            'url' => $this->oauthServerUrl,
            'app_url' => $this->frontendAppUrl,
        ]);
    }

    protected function seedOAuthState(?string $state = null): string
    {
        $state ??= Str::random(40);
        Cache::put('oauth_state', $state);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $oauthUser
     * @param  array<string, mixed>  $tokenPayload
     */
    protected function fakeSuccessfulOAuthHttp(
        array $oauthUser = [],
        array $tokenPayload = []
    ): void {
        $oauthUser = array_merge([
            'email' => 'oauth-user@example.com',
            'name' => 'OAuth User',
            'mobile' => '09121234567',
            'code' => '123456',
            'email_verified_at' => now()->toISOString(),
        ], $oauthUser);

        $tokenPayload = array_merge([
            'access_token' => 'oauth-access-token',
            'refresh_token' => 'oauth-refresh-token',
            'expires_in' => now()->addHour()->timestamp,
            'token_type' => 'Bearer',
        ], $tokenPayload);

        Http::fake([
            $this->oauthServerUrl.'/oauth/token' => Http::response($tokenPayload, 200),
            $this->oauthServerUrl.'/api/user' => Http::response($oauthUser, 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function hitOAuthCallback(string $state, string $code = 'auth-code', array $overrides = [])
    {
        $query = http_build_query(array_merge([
            'state' => $state,
            'code' => $code,
        ], $overrides));

        return $this->get('/auth/callback?'.$query);
    }

    protected function findUserByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }
}
