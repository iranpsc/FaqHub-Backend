<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\User;
use App\Models\UserFeaturedQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserFeaturedQuestion>
 */
class UserFeaturedQuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'question_id' => Question::factory(),
            'type' => $this->faker->randomElement(['featured', 'unfeatured']),
            'featured_at' => now(),
        ];
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'featured',
        ]);
    }

    public function unfeatured(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'unfeatured',
        ]);
    }
}
