<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'parent_id' => null,
            'last_activity' => $this->faker->dateTime(),
        ];
    }

    /**
     * Indicate that the category is a child category.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function child(?Category $parent = null)
    {
        return $this->state(function (array $attributes) use ($parent) {
            return [
                'parent_id' => $parent?->id ?? CategoryFactory::new(),
            ];
        });
    }

    /**
     * Category with a fixed slug/name pair for route lookups.
     */
    public function withSlug(string $slug, ?string $name = null): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $name ?? str_replace('-', ' ', $slug),
            'slug' => $slug,
        ]);
    }
}
