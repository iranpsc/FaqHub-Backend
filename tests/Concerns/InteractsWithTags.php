<?php

namespace Tests\Concerns;

use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait InteractsWithTags
{
    protected function makeTagPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Laravel Testing',
            'slug' => 'laravel-testing',
        ], $overrides);
    }

    protected function createTag(array $attributes = []): Tag
    {
        return Tag::factory()->create($attributes);
    }

    protected function attachPublishedQuestions(Tag $tag, int $count = 1, array $attributes = []): void
    {
        $questions = Question::factory()
            ->published()
            ->count($count)
            ->create($attributes);

        $tag->questions()->attach($questions->pluck('id'));
    }

    protected function attachUnpublishedQuestions(Tag $tag, int $count = 1, array $attributes = []): void
    {
        $questions = Question::factory()
            ->unpublished()
            ->count($count)
            ->create($attributes);

        $tag->questions()->attach($questions->pluck('id'));
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
