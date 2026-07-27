<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\UsernameGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'username' => UsernameGenerator::generate($name),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'mobile' => fake()->phoneNumber(),
            'code' => fake()->numerify('######'), // 6-digit code
            'role' => 'user',
            'level' => fake()->numberBetween(1, 13),
            'score' => fake()->numberBetween(0, 1000),
            'image' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expires_in' => null,
            'token_type' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     *
     * @return $this
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * User with login email notifications enabled.
     */
    public function withLoginNotification(): static
    {
        return $this->state(fn (array $attributes) => [
            'login_notification_enabled' => true,
        ]);
    }

    /**
     * User without a username (triggers generation on OAuth callback).
     */
    public function withoutUsername(): static
    {
        return $this->state(fn (array $attributes) => [
            'username' => null,
        ]);
    }

    /**
     * User with an existing avatar path.
     */
    public function withImage(?string $path = 'avatars/existing.jpg'): static
    {
        return $this->state(fn (array $attributes) => [
            'image' => $path,
        ]);
    }

    /**
     * Admin role user.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }
}
