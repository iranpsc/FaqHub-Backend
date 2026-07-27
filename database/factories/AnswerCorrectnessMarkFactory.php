<?php

namespace Database\Factories;

use App\Models\Answer;
use App\Models\AnswerCorrectnessMark;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnswerCorrectnessMark>
 */
class AnswerCorrectnessMarkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'answer_id' => Answer::factory(),
            'marker_user_id' => User::factory(),
            'is_correct' => $this->faker->boolean(),
        ];
    }

    /**
     * Mark answer as correct.
     */
    public function correct(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_correct' => true,
        ]);
    }

    /**
     * Mark answer as incorrect.
     */
    public function incorrect(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_correct' => false,
        ]);
    }
}
