<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Question>
 */
class QuestionFactory extends Factory
{
    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Question $question) {
            if (blank($question->slug) && filled($question->title)) {
                $question->slug = Question::generateSlug($question->title);
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<int|string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'user_id' => User::factory(),
            'title' => fake()->unique()->sentence(),
            'slug' => null,
            'content' => fake()->paragraphs(3, true),
            'featured' => false,
            'last_activity' => now(),
            'views' => fake()->numberBetween(0, 1000),
            'published' => false,
            'published_at' => null,
            'published_by' => null,
        ];
    }

    /**
     * Indicate that the question is published.
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
     * Indicate that the question is featured.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'featured' => true,
        ]);
    }

    /**
     * Indicate that the question is unpublished.
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'published' => false,
            'published_at' => null,
            'published_by' => null,
        ]);
    }
}
