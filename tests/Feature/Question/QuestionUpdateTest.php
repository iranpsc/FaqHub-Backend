<?php

namespace Tests\Feature\Question;

use App\Models\Category;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithQuestions;
use Tests\TestCase;

class QuestionUpdateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithQuestions;

    public function test_owner_can_update_unpublished_question_and_regenerate_slug_on_title_change(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $question = $this->createUnpublishedQuestion([
            'user_id' => $owner->id,
            'title' => 'Old Title',
            'slug' => 'old-title',
            'content' => 'Old content',
        ]);
        $oldTag = Tag::factory()->create();
        $question->tags()->attach($oldTag);
        $newCategory = Category::factory()->create();
        $newTag = Tag::factory()->create();

        Sanctum::actingAs($owner);

        $payload = [
            'category_id' => $newCategory->id,
            'title' => 'Brand New Title',
            'content' => 'Brand new content',
            'tags' => [
                ['id' => $newTag->id],
                ['name' => 'Fresh Tag'],
            ],
        ];

        $this->putJson("/api/questions/{$question->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.title', 'Brand New Title')
            ->assertJsonPath('data.content', 'Brand new content')
            ->assertJsonPath('data.slug', 'brand-new-title');

        $this->assertDatabaseHas('questions', [
            'id' => $question->id,
            'category_id' => $newCategory->id,
            'title' => 'Brand New Title',
            'slug' => 'brand-new-title',
        ]);

        $question->refresh();
        $this->assertEquals(2, $question->tags()->count());
        $this->assertFalse($question->tags->contains('id', $oldTag->id));
        $this->assertDatabaseHas('tags', ['name' => 'Fresh Tag']);
    }

    public function test_owner_can_clear_all_tags_with_empty_array(): void
    {
        $owner = User::factory()->create();
        $question = $this->createUnpublishedQuestion(['user_id' => $owner->id]);
        $question->tags()->attach(Tag::factory()->count(2)->create());

        Sanctum::actingAs($owner);

        $this->putJson("/api/questions/{$question->id}", [
            'category_id' => $question->category_id,
            'title' => $question->title,
            'content' => $question->content,
            'tags' => [],
        ])->assertOk();

        $this->assertEquals(0, $question->fresh()->tags()->count());
    }

    public function test_slug_unchanged_when_title_is_unchanged(): void
    {
        $owner = User::factory()->create();
        $question = $this->createUnpublishedQuestion([
            'user_id' => $owner->id,
            'title' => 'Keep Title',
            'slug' => 'custom-slug',
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/questions/{$question->id}", [
            'category_id' => $question->category_id,
            'title' => 'Keep Title',
            'content' => 'Updated body only',
            'tags' => [['name' => 'OnlyTag']],
        ])->assertOk()
            ->assertJsonPath('data.slug', 'custom-slug');
    }

    public function test_guest_cannot_update_question(): void
    {
        $question = $this->createUnpublishedQuestion();

        $this->putJson("/api/questions/{$question->id}", $this->makeQuestionPayload())
            ->assertUnauthorized();
    }

    public function test_non_owner_cannot_update_unpublished_question(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $intruder = User::factory()->create(['level' => 5]);
        $question = $this->createUnpublishedQuestion(['user_id' => $owner->id]);

        Sanctum::actingAs($intruder);

        $this->putJson("/api/questions/{$question->id}", $this->makeQuestionPayload([
            'category_id' => $question->category_id,
        ]))->assertForbidden();

        $this->assertDatabaseHas('questions', [
            'id' => $question->id,
            'title' => $question->title,
        ]);
    }

    public function test_owner_cannot_update_published_question(): void
    {
        $owner = User::factory()->create(['level' => 3]);
        $question = $this->createPublishedQuestion(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/questions/{$question->id}", $this->makeQuestionPayload([
            'category_id' => $question->category_id,
        ]))->assertForbidden();
    }

    public function test_update_returns_404_for_missing_question(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/questions/999999', $this->makeQuestionPayload())
            ->assertNotFound();
    }

    public function test_cannot_update_question_with_null_owner(): void
    {
        $question = $this->createUnpublishedQuestion();
        $question->forceFill(['user_id' => null])->saveQuietly();
        $actor = User::factory()->create(['level' => 5]);

        Sanctum::actingAs($actor);

        $this->putJson("/api/questions/{$question->id}", $this->makeQuestionPayload([
            'category_id' => $question->category_id,
        ]))->assertForbidden();
    }

    #[DataProvider('invalidUpdatePayloadProvider')]
    public function test_update_validation_rejects_invalid_payloads(array $overrides, array $errorKeys): void
    {
        $owner = User::factory()->create();
        $question = $this->createUnpublishedQuestion(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $payload = array_merge([
            'category_id' => $question->category_id,
            'title' => 'Valid',
            'content' => 'Valid',
            'tags' => [['name' => 'ok']],
        ], $overrides);

        $this->putJson("/api/questions/{$question->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKeys);
    }

    public static function invalidUpdatePayloadProvider(): array
    {
        return [
            'missing tags key' => [
                // tags must be present on update
                ['tags' => null],
                ['tags'],
            ],
            'invalid category' => [
                ['category_id' => 999999],
                ['category_id'],
            ],
            'title too long' => [
                ['title' => str_repeat('x', 256)],
                ['title'],
            ],
            'too many tags' => [
                ['tags' => array_fill(0, 11, ['name' => 't'])],
                ['tags'],
            ],
        ];
    }

    public function test_update_sanitizes_title_html(): void
    {
        $owner = User::factory()->create();
        $question = $this->createUnpublishedQuestion(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/questions/{$question->id}", [
            'category_id' => $question->category_id,
            'title' => '<script>bad</script>Clean',
            'content' => 'Body',
            'tags' => [['name' => 'ok']],
        ])->assertOk();

        $this->assertStringNotContainsString('<script>', $question->fresh()->title);
        $this->assertStringContainsString('Clean', $question->fresh()->title);
    }

    public function test_idor_attacker_cannot_update_another_users_draft_by_id(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create(['level' => 5]);
        $question = $this->createUnpublishedQuestion([
            'user_id' => $victim->id,
            'title' => 'Victim Draft',
        ]);

        Sanctum::actingAs($attacker);

        $this->putJson("/api/questions/{$question->id}", [
            'category_id' => $question->category_id,
            'title' => 'Hijacked',
            'content' => 'Hijacked content',
            'tags' => [['name' => 'hack']],
        ])->assertForbidden();

        $this->assertDatabaseHas('questions', [
            'id' => $question->id,
            'title' => 'Victim Draft',
            'user_id' => $victim->id,
        ]);
    }
}
