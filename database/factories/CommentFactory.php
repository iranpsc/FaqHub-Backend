<?php

namespace Database\Factories;

use App\Models\Answer;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Comment>
 */
class CommentFactory extends Factory
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
            'commentable_type' => Question::class,
            'commentable_id' => Question::factory(),
            'content' => fake()->paragraph(2, true),
            'published' => false,
            'published_at' => null,
            'published_by' => null,
        ];
    }

    /**
     * Indicate that the comment is published.
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
     * Indicate that the comment is unpublished.
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
     * Attach the comment to a question.
     */
    public function forQuestion(?Question $question = null): static
    {
        return $this->state(fn (array $attributes) => [
            'commentable_type' => Question::class,
            'commentable_id' => $question?->id ?? Question::factory(),
        ]);
    }

    /**
     * Attach the comment to an answer.
     */
    public function forAnswer(?Answer $answer = null): static
    {
        return $this->state(fn (array $attributes) => [
            'commentable_type' => Answer::class,
            'commentable_id' => $answer?->id ?? Answer::factory(),
        ]);
    }
}
