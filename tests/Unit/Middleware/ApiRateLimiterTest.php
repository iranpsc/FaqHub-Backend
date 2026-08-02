<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\ApiRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ApiRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    private ApiRateLimiter $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = app(ApiRateLimiter::class);
        Cache::flush();
    }

    private function makeRequest(string $ip = '127.0.0.1', ?User $user = null): Request
    {
        $request = Request::create('/api/test', 'GET');
        $request->server->set('REMOTE_ADDR', $ip);

        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    }

    private function nextHandler(): \Closure
    {
        return fn (Request $request) => response()->json(['ok' => true]);
    }

    public function test_whitelisted_ip_bypasses_rate_limiting(): void
    {
        $request = $this->makeRequest('217.218.238.194');

        $response = $this->middleware->handle($request, $this->nextHandler(), 'create');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_create_type_returns_429_after_ten_attempts(): void
    {
        $request = $this->makeRequest('10.0.0.1');

        // Make 10 successful requests
        for ($i = 0; $i < 10; $i++) {
            $response = $this->middleware->handle($request, $this->nextHandler(), 'create');
            $this->assertSame(200, $response->getStatusCode());
        }

        // 11th request should be rate limited
        $response = $this->middleware->handle($request, $this->nextHandler(), 'create');
        $this->assertSame(429, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('retry_after', $body);
    }

    public function test_create_limit_has_429_headers(): void
    {
        $request = $this->makeRequest('10.0.0.2');

        for ($i = 0; $i < 10; $i++) {
            $this->middleware->handle($request, $this->nextHandler(), 'create');
        }

        $response = $this->middleware->handle($request, $this->nextHandler(), 'create');

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_vote_type_allows_thirty_attempts(): void
    {
        $request = $this->makeRequest('10.0.0.3');

        for ($i = 0; $i < 30; $i++) {
            $response = $this->middleware->handle($request, $this->nextHandler(), 'vote');
            $this->assertSame(200, $response->getStatusCode());
        }

        $response = $this->middleware->handle($request, $this->nextHandler(), 'vote');
        $this->assertSame(429, $response->getStatusCode());
    }

    public function test_upload_type_allows_twenty_attempts(): void
    {
        $request = $this->makeRequest('10.0.0.4');

        for ($i = 0; $i < 20; $i++) {
            $response = $this->middleware->handle($request, $this->nextHandler(), 'upload');
            $this->assertSame(200, $response->getStatusCode());
        }

        $response = $this->middleware->handle($request, $this->nextHandler(), 'upload');
        $this->assertSame(429, $response->getStatusCode());
    }

    public function test_auth_type_allows_five_attempts(): void
    {
        $request = $this->makeRequest('10.0.0.5');

        for ($i = 0; $i < 5; $i++) {
            $response = $this->middleware->handle($request, $this->nextHandler(), 'auth');
            $this->assertSame(200, $response->getStatusCode());
        }

        $response = $this->middleware->handle($request, $this->nextHandler(), 'auth');
        $this->assertSame(429, $response->getStatusCode());
    }

    public function test_search_type_allows_thirty_attempts(): void
    {
        $request = $this->makeRequest('10.0.0.6');

        for ($i = 0; $i < 30; $i++) {
            $response = $this->middleware->handle($request, $this->nextHandler(), 'search');
            $this->assertSame(200, $response->getStatusCode());
        }

        $response = $this->middleware->handle($request, $this->nextHandler(), 'search');
        $this->assertSame(429, $response->getStatusCode());
    }

    public function test_default_type_allows_sixty_attempts(): void
    {
        $request = $this->makeRequest('10.0.0.7');

        for ($i = 0; $i < 60; $i++) {
            $response = $this->middleware->handle($request, $this->nextHandler(), 'api');
            $this->assertSame(200, $response->getStatusCode());
        }

        $response = $this->middleware->handle($request, $this->nextHandler(), 'api');
        $this->assertSame(429, $response->getStatusCode());
    }

    public function test_authenticated_user_uses_user_id_as_signature(): void
    {
        $user = User::factory()->create();
        $requestWithUser = $this->makeRequest('10.0.0.8', $user);
        $requestWithoutUser = $this->makeRequest('10.0.0.8');

        // Exhaust limit for authenticated user
        for ($i = 0; $i < 10; $i++) {
            $this->middleware->handle($requestWithUser, $this->nextHandler(), 'create');
        }

        // The same IP without auth should still have its own limit
        $responseUnauthenticated = $this->middleware->handle($requestWithoutUser, $this->nextHandler(), 'create');
        $this->assertSame(200, $responseUnauthenticated->getStatusCode());

        // The authenticated request should be rate limited
        $responseAuthenticated = $this->middleware->handle($requestWithUser, $this->nextHandler(), 'create');
        $this->assertSame(429, $responseAuthenticated->getStatusCode());
    }

    public function test_successful_request_adds_rate_limit_headers(): void
    {
        $request = $this->makeRequest('10.0.0.9');

        $response = $this->middleware->handle($request, $this->nextHandler(), 'create');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertSame('10', $response->headers->get('X-RateLimit-Limit'));
    }

    public function test_different_types_have_separate_rate_limit_keys(): void
    {
        $request = $this->makeRequest('10.0.0.10');

        // Exhaust 'auth' limit
        for ($i = 0; $i < 5; $i++) {
            $this->middleware->handle($request, $this->nextHandler(), 'auth');
        }
        $authResponse = $this->middleware->handle($request, $this->nextHandler(), 'auth');
        $this->assertSame(429, $authResponse->getStatusCode());

        // 'create' type should still be available (separate key)
        $createResponse = $this->middleware->handle($request, $this->nextHandler(), 'create');
        $this->assertSame(200, $createResponse->getStatusCode());
    }
}
