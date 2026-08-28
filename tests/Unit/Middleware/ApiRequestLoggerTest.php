<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\ApiRequestLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ApiRequestLoggerTest extends TestCase
{
    public function test_logs_request_to_daily_channel(): void
    {
        $middleware = new ApiRequestLogger;

        $request = Request::create('/api/questions', 'GET');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('API Request', \Mockery::on(function (array $context) {
                return isset($context['ip_address'])
                    && isset($context['method'])
                    && isset($context['path'])
                    && isset($context['full_url'])
                    && isset($context['date_time'])
                    && isset($context['user_agent']);
            }));

        $next = fn (Request $r) => response()->json(['success' => true]);
        $response = $middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_passes_request_to_next_handler(): void
    {
        $middleware = new ApiRequestLogger;
        $request = Request::create('/api/test', 'POST');

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');

        $nextCalled = false;
        $next = function (Request $r) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['ok' => true]);
        };

        $middleware->handle($request, $next);

        $this->assertTrue($nextCalled);
    }

    public function test_logs_correct_method_and_path(): void
    {
        $middleware = new ApiRequestLogger;
        $request = Request::create('/api/categories', 'POST');

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('API Request', \Mockery::on(function (array $context) {
                return $context['method'] === 'POST'
                    && $context['path'] === 'api/categories';
            }));

        $middleware->handle($request, fn ($r) => response()->json([]));
    }
}
