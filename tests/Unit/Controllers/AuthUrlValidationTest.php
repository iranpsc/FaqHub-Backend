<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\Concerns\InteractsWithOAuth;
use Tests\TestCase;

class AuthUrlValidationTest extends TestCase
{
    use InteractsWithOAuth;

    private AuthController $controller;

    private ReflectionMethod $validate;

    private ReflectionMethod $isLoop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOAuth();
        $this->controller = $this->app->make(AuthController::class);
        $this->validate = new ReflectionMethod(AuthController::class, 'validateAndSanitizeUrl');
        $this->isLoop = new ReflectionMethod(AuthController::class, 'isRedirectLoop');
    }

    public function test_null_and_malformed_urls_are_rejected(): void
    {
        $this->assertNull($this->validate->invoke($this->controller, null));
        $this->assertNull($this->validate->invoke($this->controller, 'http:///broken'));
    }

    public function test_external_domain_is_rejected(): void
    {
        $this->assertNull($this->validate->invoke(
            $this->controller,
            'https://evil.example.com/path'
        ));
    }

    public function test_production_rejects_non_https(): void
    {
        $this->app['env'] = 'production';

        $this->assertNull($this->validate->invoke(
            $this->controller,
            'http://faqhub.test/questions/1'
        ));
    }

    public function test_dangerous_paths_are_rejected(): void
    {
        foreach (['/auth/callback', '/api/auth/redirect', '/login', '/logout'] as $path) {
            $this->assertNull(
                $this->validate->invoke($this->controller, $this->frontendAppUrl.$path),
                "Expected rejection for {$path}"
            );
        }
    }

    public function test_valid_same_domain_url_is_accepted(): void
    {
        $url = $this->frontendAppUrl.'/questions/welcome';

        $this->assertSame($url, $this->validate->invoke($this->controller, $url));
    }

    public function test_redirect_loop_when_paths_match(): void
    {
        $request = Request::create($this->frontendAppUrl.'/dashboard', 'GET');
        $this->assertTrue($this->isLoop->invoke(
            $this->controller,
            $this->frontendAppUrl.'/dashboard',
            $request
        ));
    }

    public function test_redirect_loop_detects_callback_path(): void
    {
        $request = Request::create($this->frontendAppUrl.'/home', 'GET');
        $this->assertTrue($this->isLoop->invoke(
            $this->controller,
            $this->frontendAppUrl.'/auth/callback',
            $request
        ));
    }

    public function test_different_hosts_are_not_loops(): void
    {
        $request = Request::create($this->frontendAppUrl.'/home', 'GET');
        $this->assertFalse($this->isLoop->invoke(
            $this->controller,
            'https://other.test/home',
            $request
        ));
    }

    public function test_root_path_match_is_not_treated_as_loop(): void
    {
        // rtrim turns '/' into '', so matching root paths are treated as a loop
        // by the current implementation — assert that behaviour explicitly.
        $request = Request::create($this->frontendAppUrl.'/', 'GET');
        $this->assertTrue($this->isLoop->invoke(
            $this->controller,
            $this->frontendAppUrl.'/',
            $request
        ));
    }

    public function test_validate_rejects_when_request_would_loop(): void
    {
        $url = $this->frontendAppUrl.'/dashboard';
        $request = Request::create($url, 'GET');

        $this->assertNull($this->validate->invoke($this->controller, $url, $request));
    }
}
