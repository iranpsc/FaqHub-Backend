<?php

namespace App\Http\Controllers;

use App\Jobs\FetchUserLevel;
use App\Models\User;
use App\Notifications\LoginNotification;
use App\Services\UsernameGenerator;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Redirect to the OAuth server for authentication.
     *
     * @return JsonResponse
     */
    public function redirect(Request $request)
    {
        // Check redirect depth to prevent infinite loops (per session)
        $redirectDepth = $request->session()->get('redirect_depth', 0);
        if ($redirectDepth >= 3) {
            return response()->json([
                'error' => 'Too many redirect attempts. Please try again later.',
            ], 429);
        }

        cache()->put('oauth_state', $state = Str::random(40));

        // Validate and cache the intended URL if provided
        if ($request->has('intended_url')) {
            $intendedUrl = $request->input('intended_url');

            // Validate the intended URL before caching
            if ($this->validateAndSanitizeUrl($intendedUrl)) {
                $request->session()->put('oauth_intended_url', $intendedUrl);
                // Increment redirect depth
                $request->session()->put('redirect_depth', $redirectDepth + 1);
            }
        }

        $query = http_build_query([
            'client_id' => config('services.oauth.client_id'),
            'redirect_uri' => config('services.oauth.redirect'),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
            // 'prompt' => '', // "none", "consent", or "login"
        ]);

        return response()->json([
            'redirect_url' => config('services.oauth.url').'/oauth/authorize?'.$query,
        ]);
    }

    /**
     * Handle the OAuth callback.
     *
     * @return JsonResponse
     */
    public function callback(Request $request)
    {
        $state = cache()->pull('oauth_state');

        throw_unless(
            strlen($state) > 0 && $state === $request->state,
            \InvalidArgumentException::class
        );

        $response = Http::asForm()->post(config('services.oauth.url').'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.oauth.client_id'),
            'client_secret' => config('services.oauth.client_secret'),
            'redirect_uri' => config('services.oauth.redirect'),
            'code' => $request->code,
        ]);

        $accessToken = $response->json('access_token');

        $userResponse = Http::withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$accessToken,
        ])
            ->acceptJson()
            ->get(config('services.oauth.url').'/api/user');

        $userArray = $userResponse->json();

        $user = User::updateOrCreate(
            [
                'email' => $userArray['email'],
            ],
            [
                'name' => $userArray['name'],
                'mobile' => $userArray['mobile'],
                'code' => $userArray['code'],
            ]
        );

        $user->forceFill([
            'access_token' => $accessToken,
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => $response->json('expires_in'),
            'token_type' => $response->json('token_type'),
        ])->save();

        if (empty($user->username)) {
            $user->update([
                'username' => UsernameGenerator::generate($user->name, $user->id),
            ]);
        }

        $user->update(['email_verified_at' => $userArray['email_verified_at']]);

        if ($user->wasChanged('email_verified_at')) {
            $user->increment('score', 10); // Increment score for new user
        }

        $this->guard()->login($user);

        // Send login notification if enabled
        if ($user->login_notification_enabled) {
            $loginData = [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ];
            $user->notify(new LoginNotification($user, $loginData));
        }

        // Dispatch job to fetch user level
        FetchUserLevel::dispatch($user);

        $tokenExpiry = now()->addHour();

        $token = $user->createToken('auth-token', ['*'], $tokenExpiry)->plainTextToken;

        // Get cached intended URL and clean up session
        $intendedUrl = $request->session()->pull('oauth_intended_url');

        // Reset redirect depth on successful authentication
        $request->session()->forget('redirect_depth');

        // Validate and sanitize the intended URL
        // Falls back to frontend app URL from FRONTEND_APP_URL env variable
        $baseUrl = $this->validateAndSanitizeUrl($intendedUrl, $request);

        // If validation failed or URL is dangerous, use default app URL
        if (! $baseUrl) {
            $baseUrl = config('services.oauth.app_url');
        }

        // Final check: ensure we're not redirecting to the callback route itself
        $finalUrl = $baseUrl.'#token='.$token;
        if ($this->isRedirectLoop($finalUrl, $request)) {
            // Fallback to safe default URL
            $baseUrl = config('services.oauth.app_url');
            $finalUrl = $baseUrl.'#token='.$token;
        }

        // Use URL fragment to avoid leaking tokens via Referer headers
        return redirect($finalUrl);
    }

    /**
     * Get the authenticated user.
     *
     * @return JsonResponse
     */
    public function me(Request $request)
    {
        $userData = [
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'username' => $request->user()->username,
            'code' => $request->user()->code,
            'level' => $request->user()->level,
            'score' => $request->user()->score,
            'image_url' => $request->user()->image_url,
            'role' => $request->user()->role,
            'login_notification_enabled' => $request->user()->login_notification_enabled,
        ];

        return response()->json($userData);
    }

    /**
     * Log out the user and revoke all tokens.
     *
     * @return JsonResponse
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $user->tokens()->delete();
        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Validate and sanitize the intended URL to prevent open redirects and infinite loops.
     *
     * @param  string|null  $url
     * @return string|null
     */
    protected function validateAndSanitizeUrl($url, ?Request $request = null)
    {
        if (! $url) {
            return null;
        }

        // Parse the URL
        $parsedUrl = parse_url($url);

        // Reject malformed URLs
        if ($parsedUrl === false) {
            return null;
        }

        // Get allowed domains from frontend app URL
        $appDomain = parse_url(config('services.oauth.app_url'), PHP_URL_HOST);
        $urlDomain = $parsedUrl['host'] ?? null;

        // Only allow redirects to the same domain as the app
        if ($urlDomain !== $appDomain) {
            return null;
        }

        // Ensure the URL uses HTTPS in production
        if (app()->environment('production') && ($parsedUrl['scheme'] ?? '') !== 'https') {
            return null;
        }

        // Extract and validate the path
        $path = $parsedUrl['path'] ?? '/';

        // Block dangerous paths that could cause redirect loops
        $dangerousPaths = [
            '/auth/callback',
            '/auth/redirect',
            '/api/auth/callback',
            '/api/auth/redirect',
            '/oauth/callback',
            '/oauth/authorize',
            '/login',
            '/logout',
        ];

        // Normalize path for comparison (remove trailing slashes, lowercase)
        $normalizedPath = rtrim(strtolower($path), '/');

        foreach ($dangerousPaths as $dangerousPath) {
            $normalizedDangerous = rtrim(strtolower($dangerousPath), '/');
            // Check if path starts with or equals dangerous path
            if ($normalizedPath === $normalizedDangerous ||
                str_starts_with($normalizedPath, $normalizedDangerous.'/')) {
                return null;
            }
        }

        // Check if redirecting to the same URL as current request (redirect loop)
        if ($request && $this->isRedirectLoop($url, $request)) {
            return null;
        }

        // Reconstruct a clean URL (without fragment, as it will be added separately)
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'];
        $port = isset($parsedUrl['port']) ? ':'.$parsedUrl['port'] : '';
        $query = isset($parsedUrl['query']) ? '?'.$parsedUrl['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    /**
     * Check if a redirect would cause an infinite loop.
     */
    protected function isRedirectLoop(string $redirectUrl, Request $request): bool
    {
        // Parse both URLs
        $redirectParsed = parse_url($redirectUrl);
        $currentParsed = parse_url($request->fullUrl());

        if ($redirectParsed === false || $currentParsed === false) {
            return false;
        }

        // Compare hosts
        $redirectHost = ($redirectParsed['scheme'] ?? '').'://'.($redirectParsed['host'] ?? '');
        $currentHost = ($currentParsed['scheme'] ?? '').'://'.($currentParsed['host'] ?? '');

        if ($redirectHost !== $currentHost) {
            return false;
        }

        // Compare paths (normalize)
        $redirectPath = rtrim($redirectParsed['path'] ?? '/', '/');
        $currentPath = rtrim($currentParsed['path'] ?? '/', '/');

        // If paths match, it's a potential loop
        // But allow if it's just the root path
        if ($redirectPath === $currentPath && $redirectPath !== '/') {
            return true;
        }

        // Check if redirecting to callback route
        if (str_contains($redirectPath, '/auth/callback') ||
            str_contains($redirectPath, '/api/auth/callback')) {
            return true;
        }

        return false;
    }

    /**
     * Get the guard for the controller.
     *
     * @return StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard('web');
    }
}
