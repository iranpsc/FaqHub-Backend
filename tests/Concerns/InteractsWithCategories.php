<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Question;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait InteractsWithCategories
{
    protected function makeCategoryPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Laravel Basics',
            'parent_id' => null,
        ], $overrides);
    }

    protected function createCategory(array $attributes = []): Category
    {
        return Category::factory()->create($attributes);
    }

    protected function createChildCategory(Category $parent, array $attributes = []): Category
    {
        return Category::factory()->create(array_merge([
            'parent_id' => $parent->id,
        ], $attributes));
    }

    protected function createQuestionsForCategory(
        Category $category,
        int $count = 1,
        bool $published = true,
        array $attributes = []
    ): void {
        $factory = Question::factory()->count($count)->for($category);

        if ($published) {
            $factory->published()->create($attributes);

            return;
        }

        $factory->unpublished()->create($attributes);
    }

    protected function actingAsAdmin(array $attributes = []): User
    {
        $user = User::factory()->admin()->create($attributes);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function actingAsUser(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['role' => 'user'], $attributes));

        Sanctum::actingAs($user);

        return $user;
    }
}
