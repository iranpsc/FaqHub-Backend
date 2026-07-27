<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class UsernameGenerator
{
    /**
     * Generate a unique username based on the provided name.
     */
    public static function generate(string $name, ?int $ignoreUserId = null): string
    {
        $baseUsername = self::normalize($name);

        $username = $baseUsername;
        $counter = 1;

        while (self::usernameExists($username, $ignoreUserId)) {
            $username = $baseUsername.'-'.$counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Normalize the provided name into a slug/username-friendly format.
     */
    protected static function normalize(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            $name = 'user';
        }

        if (self::containsNonLatinCharacters($name)) {
            $normalized = Str::of($name)
                ->replaceMatches('/\s+/u', '-')
                ->trim('-');
        } else {
            $normalized = Str::of($name)
                ->lower()
                ->replace(' ', '-')
                ->slug('-')
                ->trim('-');
        }

        if ($normalized->isEmpty()) {
            $normalized = Str::of('user');
        }

        // Limit length to avoid overly long usernames
        return $normalized->substr(0, 60)->value();
    }

    /**
     * Determine if the username already exists.
     */
    protected static function usernameExists(string $username, ?int $ignoreUserId = null): bool
    {
        $query = User::where('username', $username);

        if ($ignoreUserId) {
            $query->where('id', '!=', $ignoreUserId);
        }

        return $query->exists();
    }

    /**
     * Determine if the provided string contains non-Latin (non-ASCII) characters.
     */
    protected static function containsNonLatinCharacters(string $value): bool
    {
        return (bool) preg_match('/[^\x00-\x7F]/', $value);
    }
}
