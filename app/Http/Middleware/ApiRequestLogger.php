<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiRequestLogger
{
    /**
     * Handle an incoming request and log it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ipAddress = $request->ip();
        $method = $request->method();
        $path = $request->path();
        $fullPath = $request->fullUrl();
        $dateTime = now()->toDateTimeString();

        // Log the request details
        Log::channel('daily')->info('API Request', [
            'ip_address' => $ipAddress,
            'method' => $method,
            'path' => $path,
            'full_url' => $fullPath,
            'date_time' => $dateTime,
            'user_agent' => $request->userAgent(),
        ]);

        return $next($request);
    }
}
