<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // Only allow specific HTTP methods
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Allowed origins - strict in production, permissive in development
    'allowed_origins' => env('APP_ENV', 'production') === 'production'
        ? ['https://faqhub.ir']
        : [],

    'allowed_origins_patterns' => [],

    // Only allow specific headers
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
    ],

    // Expose rate limit headers to the client
    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
    ],

    // Cache preflight requests for 1 hour
    'max_age' => 3600,

    // Enable credentials for cookie-based auth
    'supports_credentials' => true,

];
