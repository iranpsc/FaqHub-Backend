<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

class OptionalAuthSanctum
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Try to authenticate but don't reject if it fails
        if (! $bearerToken = $request->bearerToken()) {
            return $next($request);
        }

        /** @var class-string<PersonalAccessToken> $model */
        $model = Sanctum::$personalAccessTokenModel;
        $accessToken = $model::findToken($bearerToken);

        if (! $accessToken || ! $accessToken->tokenable) {
            return $next($request);
        }

        $tokenable = method_exists($accessToken->tokenable, 'withAccessToken')
            ? $accessToken->tokenable->withAccessToken($accessToken)
            : $accessToken->tokenable;

        // Share the authenticated user instance with Laravel's auth manager.
        Auth::setUser($tokenable);

        $request->setUserResolver(function ($guard = null) use ($tokenable) {
            if ($guard) {
                return Auth::guard($guard)->user();
            }

            return $tokenable;
        });

        // Update the token's last used timestamp to mirror Sanctum's guard behaviour.
        if (method_exists($accessToken, 'forceFill')) {
            $accessToken->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }
}
