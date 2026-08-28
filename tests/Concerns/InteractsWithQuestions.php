<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait InteractsWithQuestions
{
    protected function makeQuestionPayload(
        array $overrides = [],
        ?Category $category = null,
        ?array $tags = null
    ): array {
        $category ??= Category::factory()->create();
        $tags ??= Tag::factory()->count(2)->create()
            ->map(fn (Tag $tag) => ['id' => $tag->id])
            ->all();

        return array_merge([
            'category_id' => $category->id,
            'title' => 'How does Laravel routing work?',
            'content' => 'I need a detailed explanation of route model binding.',
            'tags' => $tags,
        ], $overrides);
    }

    protected function createPublishedQuestion(array $attributes = []): Question
    {
        return Question::factory()->published()->create($attributes);
    }

    protected function createUnpublishedQuestion(array $attributes = []): Question
    {
        return Question::factory()->unpublished()->create($attributes);
    }

    protected function actingAsLevel(int $level, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['level' => $level, 'score' => 0], $attributes));

        Sanctum::actingAs($user);

        return $user;
    }
}
