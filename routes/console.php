<?php

use App\Jobs\GenerateSitemaps;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Sentry\Laravel\Integration;

use function Sentry\captureException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sitemap:generate', function () {
    GenerateSitemaps::dispatch();
    $this->info('Sitemap generation job dispatched.');
})->purpose('Generate sitemap files for questions, categories, tags, and authors');

Artisan::command('sentry:send-test-error', function () {
    if (empty(config('sentry.dsn'))) {
        $this->error('Sentry DSN is not configured. Set SENTRY_LARAVEL_DSN in your .env file.');

        return 1;
    }

    $this->info('Sending test error to Sentry...');

    $eventId = captureException(new RuntimeException('Test error sent from artisan sentry:send-test-error command.'));

    Integration::flushEvents();

    if ($eventId === null) {
        $this->error('Failed to send test error to Sentry.');

        return 1;
    }

    $this->info("Test error sent to Sentry with event ID: {$eventId}");

    return 0;
})->purpose('Send a test error to the Sentry server');
