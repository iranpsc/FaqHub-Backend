<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Console\Scheduling\Schedule;
use App\Jobs\GenerateSitemaps;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->job(new GenerateSitemaps)->everyThreeHours()->sentryMonitor();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(StartSession::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\ApiRequestLogger::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\ApiRateLimiter::class);
        $middleware->alias([
            'auth.optional' => \App\Http\Middleware\OptionalAuthSanctum::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
