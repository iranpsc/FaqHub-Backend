<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    private SecurityHeaders $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new SecurityHeaders;
    }

    private function getResponse(string $env = 'testing'): \Symfony\Component\HttpFoundation\Response
    {
        $this->app['env'] = $env;

        $request = Request::create('/api/test', 'GET');
        $next = fn ($r) => response()->json(['ok' => true]);

        return $this->middleware->handle($request, $next);
    }

    public function test_always_sets_x_content_type_options(): void
    {
        $response = $this->getResponse();

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_always_sets_x_frame_options(): void
    {
        $response = $this->getResponse();

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_always_sets_x_xss_protection(): void
    {
        $response = $this->getResponse();

        $this->assertSame('1; mode=block', $response->headers->get('X-XSS-Protection'));
    }

    public function test_always_sets_referrer_policy(): void
    {
        $response = $this->getResponse();

        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    public function test_always_sets_permissions_policy(): void
    {
        $response = $this->getResponse();

        $permissionsPolicy = $response->headers->get('Permissions-Policy');
        $this->assertStringContainsString('camera=()', $permissionsPolicy);
        $this->assertStringContainsString('microphone=()', $permissionsPolicy);
        $this->assertStringContainsString('geolocation=()', $permissionsPolicy);
    }

    public function test_non_production_does_not_set_hsts(): void
    {
        $response = $this->getResponse('testing');

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_non_production_does_not_set_csp(): void
    {
        $response = $this->getResponse('testing');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_production_sets_hsts_header(): void
    {
        $response = $this->getResponse('production');

        $hsts = $response->headers->get('Strict-Transport-Security');
        $this->assertNotNull($hsts);
        $this->assertStringContainsString('max-age=31536000', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
        $this->assertStringContainsString('preload', $hsts);
    }

    public function test_production_sets_csp_header(): void
    {
        $response = $this->getResponse('production');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_x_powered_by_is_removed(): void
    {
        $response = $this->getResponse();

        $this->assertFalse($response->headers->has('X-Powered-By'));
    }

    public function test_server_header_is_removed(): void
    {
        $response = $this->getResponse();

        $this->assertFalse($response->headers->has('Server'));
    }

    public function test_middleware_passes_request_to_next_handler(): void
    {
        $request = Request::create('/api/test', 'GET');

        $nextCalled = false;
        $next = function ($r) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['data' => 'test']);
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($nextCalled);
        $data = json_decode($response->getContent(), true);
        $this->assertSame('test', $data['data']);
    }
}
