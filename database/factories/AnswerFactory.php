<?php

namespace Database\Factories;

use App\Models\Answer;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Answer>
 */
class AnswerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<int|string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_id' => Question::factory(),
            'user_id' => User::factory(),
            'content' => fake()->paragraphs(3, true),
            'published' => false,
            'published_at' => null,
            'published_by' => null,
            'is_correct' => false,
        ];
    }

    /**
     * Indicate that the answer is published.
     */
    public function published(?User $publisher = null): static
    {
        return $this->state(fn (array $attributes) => [
            'published' => true,
            'published_at' => now(),
            'published_by' => $publisher?->id ?? User::factory(),
        ]);
    }

    /**
     * Indicate that the answer is unpublished.
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'published' => false,
            'published_at' => null,
            'published_by' => null,
        ]);
    }

    /**
     * Indicate that the answer is marked correct.
     */
    public function correct(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_correct' => true,
        ]);
    }

    /**
     * Indicate that the answer is not marked correct.
     */
    public function incorrect(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_correct' => false,
        ]);
    }
}
