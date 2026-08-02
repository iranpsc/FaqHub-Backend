<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\OptionalAuthSanctum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class OptionalAuthSanctumTest extends TestCase
{
    use RefreshDatabase;

    private OptionalAuthSanctum $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new OptionalAuthSanctum;
    }

    public function test_request_without_bearer_token_passes_through_unauthenticated(): void
    {
        $request = Request::create('/api/questions', 'GET');

        $nextCalled = false;
        $next = function (Request $r) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['ok' => true]);
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($nextCalled);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(Auth::user());
    }

    public function test_request_with_invalid_token_passes_through_unauthenticated(): void
    {
        $request = Request::create('/api/questions', 'GET');
        $request->headers->set('Authorization', 'Bearer invalid-token-12345');

        $nextCalled = false;
        $next = function (Request $r) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['ok' => true]);
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($nextCalled);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(Auth::user());
    }

    public function test_request_with_valid_sanctum_token_sets_authenticated_user(): void
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('test-token');
        $plainTextToken = $tokenResult->plainTextToken;

        $request = Request::create('/api/questions', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$plainTextToken);

        $authenticatedUser = null;
        $next = function (Request $r) use (&$authenticatedUser) {
            $authenticatedUser = $r->user();

            return response()->json(['ok' => true]);
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($authenticatedUser);
        $this->assertSame($user->id, $authenticatedUser->id);
    }

    public function test_valid_token_sets_user_on_auth_facade(): void
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('auth-test');
        $plainTextToken = $tokenResult->plainTextToken;

        $request = Request::create('/api/me', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$plainTextToken);

        $this->middleware->handle($request, fn ($r) => response()->json([]));

        $this->assertNotNull(Auth::user());
        $this->assertSame($user->id, Auth::user()->id);
    }

    public function test_valid_token_updates_last_used_at(): void
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('ts-test');
        $plainTextToken = $tokenResult->plainTextToken;

        // Ensure last_used_at is initially null
        $token = $tokenResult->accessToken;
        $token->forceFill(['last_used_at' => null])->save();

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$plainTextToken);

        $this->middleware->handle($request, fn ($r) => response()->json([]));

        $token->refresh();
        $this->assertNotNull($token->last_used_at);
    }

    public function test_user_resolver_with_guard_argument_uses_auth_guard(): void
    {
        $user = User::factory()->create();
        $plainTextToken = $user->createToken('guard-test')->plainTextToken;

        $request = Request::create('/api/questions', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$plainTextToken);

        $resolved = null;
        $this->middleware->handle($request, function (Request $r) use (&$resolved) {
            $resolved = $r->user('web');

            return response()->json(['ok' => true]);
        });

        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->id);
    }

    public function test_token_without_tokenable_passes_through(): void
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('orphan');
        $plainTextToken = $tokenResult->plainTextToken;
        $tokenResult->accessToken->forceFill(['tokenable_id' => 999999])->save();

        $request = Request::create('/api/questions', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$plainTextToken);

        $authUser = 'unset';
        $this->middleware->handle($request, function (Request $r) use (&$authUser) {
            $authUser = $r->user();

            return response()->json(['ok' => true]);
        });

        $this->assertNull($authUser);
    }
}
