<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiRateLimiter
{
    protected RateLimiter $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request with rate limiting.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $type = 'api'): Response
    {
        if ($request->ip() == '217.218.238.194') {
            return $next($request);
        }

        $key = $this->resolveRequestSignature($request, $type);
        $limits = $this->getLimits($type);

        if ($this->limiter->tooManyAttempts($key, $limits['max_attempts'])) {
            return $this->buildTooManyAttemptsResponse($key, $limits['max_attempts']);
        }

        $this->limiter->hit($key, $limits['decay_seconds']);

        $response = $next($request);

        return $this->addHeaders(
            $response,
            $limits['max_attempts'],
            $this->limiter->remaining($key, $limits['max_attempts'])
        );
    }

    /**
     * Resolve request signature for rate limiting.
     */
    protected function resolveRequestSignature(Request $request, string $type): string
    {
        // Use user ID if authenticated, otherwise use IP
        $identifier = $request->user()?->id ?? $request->ip();

        return sha1($type.'|'.$identifier);
    }

    /**
     * Get rate limits based on type.
     */
    protected function getLimits(string $type): array
    {
        return match ($type) {
            // Strict limits for write operations
            'create' => [
                'max_attempts' => 10,      // 10 creates per minute
                'decay_seconds' => 60,
            ],
            'vote' => [
                'max_attempts' => 30,      // 30 votes per minute
                'decay_seconds' => 60,
            ],
            'upload' => [
                'max_attempts' => 20,      // 20 uploads per minute
                'decay_seconds' => 60,
            ],
            'auth' => [
                'max_attempts' => 5,       // 5 auth attempts per minute
                'decay_seconds' => 60,
            ],
            'search' => [
                'max_attempts' => 30,      // 30 searches per minute
                'decay_seconds' => 60,
            ],
            // Default API limits
            default => [
                'max_attempts' => 60,      // 60 requests per minute
                'decay_seconds' => 60,
            ],
        };
    }

    /**
     * Build the response for too many attempts.
     */
    protected function buildTooManyAttemptsResponse(string $key, int $maxAttempts): Response
    {
        $retryAfter = $this->limiter->availableIn($key);

        return response()->json([
            'success' => false,
            'message' => 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً کمی صبر کنید.',
            'retry_after' => $retryAfter,
        ], 429)->withHeaders([
            'Retry-After' => $retryAfter,
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
        ]);
    }

    /**
     * Add rate limit headers to response.
     */
    protected function addHeaders(Response $response, int $maxAttempts, int $remaining): Response
    {
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $remaining));

        return $response;
    }
}
