<?php

use App\Jobs\GenerateSitemaps;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Sentry\Laravel\Integration;

use function Sentry\captureException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sitemap:generate', function () {
    GenerateSitemaps::dispatch();
    $this->info('Sitemap generation job dispatched.');
})->everyThreeHours();
