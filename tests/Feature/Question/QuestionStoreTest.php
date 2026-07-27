<?php

namespace Tests\Feature\Question;

use App\Models\Category;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithQuestions;
use Tests\TestCase;

class QuestionStoreTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithQuestions;

    public function test_guest_cannot_create_question(): void
    {
        $this->postJson('/api/questions', $this->makeQuestionPayload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('questions', 0);
    }

    public function test_authenticated_user_can_create_unpublished_question_when_level_below_two(): void
    {
        $user = $this->actingAsLevel(1);
        $payload = $this->makeQuestionPayload();

        $response = $this->postJson('/api/questions', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonPath('data.content', $payload['content'])
            ->assertJsonPath('data.published', false)
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('questions', [
            'title' => $payload['title'],
            'user_id' => $user->id,
            'category_id' => $payload['category_id'],
            'published' => false,
            'published_at' => null,
            'published_by' => null,
        ]);

        $question = Question::first();
        $this->assertNotNull($question->slug);
        $this->assertEquals(2, $question->tags()->count());
        $this->assertEquals(0, $user->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'created_question',
            'subject_type' => Question::class,
            'subject_id' => $question->id,
            'causer_id' => $user->id,
        ]);
    }

    public function test_level_two_or_higher_user_auto_publishes_on_create_without_score_award(): void
    {
        $user = $this->actingAsLevel(3, ['score' => 0]);
        $payload = $this->makeQuestionPayload();

        $this->postJson('/api/questions', $payload)->assertCreated();

        $question = Question::first();

        $this->assertTrue($question->published);
        $this->assertNotNull($question->published_at);
        $this->assertEquals($user->id, $question->published_by);
        // Auto-publish in store logs publishing but does not increment score (unlike publish endpoint).
        $this->assertEquals(0, $user->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'published_question',
            'subject_id' => $question->id,
            'causer_id' => $user->id,
        ]);
    }

    public function test_store_creates_new_tags_and_reuses_existing_tag_names(): void
    {
        $existing = Tag::factory()->create(['name' => 'Laravel']);
        $this->actingAsLevel(1);

        $payload = $this->makeQuestionPayload(
            tags: [
                ['id' => $existing->id],
                ['name' => 'Eloquent'],
                ['name' => 'Laravel'],
            ]
        );

        $this->postJson('/api/questions', $payload)->assertCreated();

        $question = Question::first();
        $this->assertEquals(2, $question->tags()->count());
        $this->assertDatabaseHas('tags', ['name' => 'Eloquent']);
        $this->assertEquals(1, Tag::where('name', 'Laravel')->count());
    }

    public function test_store_generates_unique_slug_when_title_collides(): void
    {
        $this->createPublishedQuestion([
            'title' => 'Same Title',
            'slug' => 'same-title',
        ]);
        $this->actingAsLevel(1);

        $this->postJson('/api/questions', $this->makeQuestionPayload([
            'title' => 'Same Title',
        ]))->assertCreated();

        $this->assertDatabaseHas('questions', ['slug' => 'same-title-1']);
    }

    #[DataProvider('invalidStorePayloadProvider')]
    public function test_store_validation_rejects_invalid_payloads(array $overrides, array $errorKeys): void
    {
        $this->actingAsLevel(1);
        $base = $this->makeQuestionPayload();

        $this->postJson('/api/questions', array_merge($base, $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKeys);

        $this->assertDatabaseCount('questions', 0);
    }

    public static function invalidStorePayloadProvider(): array
    {
        return [
            'missing required fields' => [
                ['category_id' => null, 'title' => null, 'content' => null, 'tags' => null],
                ['category_id', 'title', 'content', 'tags'],
            ],
            'nonexistent category' => [
                ['category_id' => 999999],
                ['category_id'],
            ],
            'title too long' => [
                ['title' => str_repeat('a', 256)],
                ['title'],
            ],
            'empty tags array' => [
                ['tags' => []],
                ['tags'],
            ],
            'too many tags' => [
                ['tags' => array_fill(0, 11, ['name' => 'tag'])],
                ['tags'],
            ],
            'nonexistent tag id' => [
                ['tags' => [['id' => 999999]]],
                ['tags.0.id'],
            ],
            'tag without id or name' => [
                ['tags' => [[]]],
                ['tags.0.id', 'tags.0.name'],
            ],
            'tag name too long' => [
                ['tags' => [['name' => str_repeat('t', 51)]]],
                ['tags.0.name'],
            ],
        ];
    }

    public function test_store_sanitizes_html_from_title_and_tag_names(): void
    {
        $this->actingAsLevel(1);

        $response = $this->postJson('/api/questions', $this->makeQuestionPayload([
            'title' => '<script>alert(1)</script>Safe Title',
            'tags' => [['name' => '<script>bad</script>CleanTag']],
        ]));

        $response->assertCreated();

        $question = Question::first();
        $this->assertStringNotContainsString('<script>', $question->title);
        $this->assertStringContainsString('Safe Title', $question->title);
        $this->assertDatabaseHas('tags', ['name' => 'CleanTag']);
        $this->assertDatabaseMissing('tags', ['name' => '<script>bad</script>CleanTag']);
    }

    public function test_store_ignores_mass_assignment_of_published_and_featured_flags(): void
    {
        $user = $this->actingAsLevel(1);

        $this->postJson('/api/questions', $this->makeQuestionPayload([
            'published' => true,
            'featured' => true,
            'views' => 9999,
            'user_id' => User::factory()->create()->id,
            'published_by' => $user->id,
        ]))->assertCreated();

        $question = Question::first();

        $this->assertFalse($question->published);
        $this->assertFalse($question->featured);
        $this->assertEquals(0, $question->views);
        $this->assertEquals($user->id, $question->user_id);
        $this->assertNull($question->published_by);
    }

    public function test_store_does_not_create_partial_question_when_tag_sync_would_fail(): void
    {
        $this->actingAsLevel(1);
        $category = Category::factory()->create();

        // Invalid nested tag shape after outer tags pass required|array|min:1
        $response = $this->postJson('/api/questions', [
            'category_id' => $category->id,
            'title' => 'Valid title',
            'content' => 'Valid content',
            'tags' => ['not-an-array-item'],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('questions', 0);
        $this->assertEquals(0, DB::table('question_tag')->count());
    }
}
