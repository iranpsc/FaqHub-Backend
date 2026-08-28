<?php

namespace Tests\Feature\Tag;

use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTags;
use Tests\TestCase;

class TagQuestionsTest extends TestCase
{
    use InteractsWithTags;
    use RefreshDatabase;

    public function test_guest_can_list_published_questions_for_tag_by_slug(): void
    {
        $tag = $this->createTag(['name' => 'API', 'slug' => 'api']);
        $published = Question::factory()->published()->create(['title' => 'Published for tag']);
        $unpublished = Question::factory()->unpublished()->create(['title' => 'Draft for tag']);
        $tag->questions()->attach([$published->id, $unpublished->id]);

        $otherTag = $this->createTag(['slug' => 'other']);
        $otherQuestion = Question::factory()->published()->create(['title' => 'Other tag question']);
        $otherTag->questions()->attach($otherQuestion->id);

        $response = $this->getJson('/api/tags/api/questions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonPath('data.0.title', 'Published for tag')
            ->assertJsonPath('tag.id', $tag->id)
            ->assertJsonPath('tag.name', 'API')
            ->assertJsonPath('tag.slug', 'api')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'slug',
                        'user',
                        'category',
                        'tags',
                        'votes_count',
                        'answers_count',
                    ],
                ],
                'links',
                'meta' => ['current_page', 'per_page', 'total'],
                'tag' => [
                    'id',
                    'name',
                    'slug',
                    'can' => ['update', 'delete'],
                ],
            ]);
    }

    public function test_tag_questions_paginates_ten_per_page(): void
    {
        $tag = $this->createTag(['slug' => 'paginated']);
        $this->attachPublishedQuestions($tag, 15);

        $this->getJson('/api/tags/paginated/questions')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.current_page', 1);

        $this->getJson('/api/tags/paginated/questions?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_tag_questions_orders_by_created_at_descending(): void
    {
        $tag = $this->createTag(['slug' => 'ordered']);
        $older = Question::factory()->published()->create([
            'title' => 'Older',
            'created_at' => now()->subDays(2),
        ]);
        $newer = Question::factory()->published()->create([
            'title' => 'Newer',
            'created_at' => now()->subDay(),
        ]);
        $tag->questions()->attach([$older->id, $newer->id]);

        $this->getJson('/api/tags/ordered/questions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_tag_questions_excludes_unpublished_even_for_authenticated_owner(): void
    {
        // TagController::questions uses published() scope only — no visible() scope.
        $owner = $this->actingAsUser(['level' => 5]);
        $tag = $this->createTag(['slug' => 'drafts-hidden']);
        $draft = Question::factory()->unpublished()->create([
            'user_id' => $owner->id,
            'title' => 'My draft',
        ]);
        $tag->questions()->attach($draft->id);

        $this->getJson('/api/tags/drafts-hidden/questions')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_tag_questions_returns_empty_data_when_tag_has_no_questions(): void
    {
        $this->createTag(['slug' => 'lonely']);

        $this->getJson('/api/tags/lonely/questions')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('tag.slug', 'lonely')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_tag_questions_returns_404_for_unknown_tag_slug(): void
    {
        $this->getJson('/api/tags/missing/questions')->assertNotFound();
    }

    public function test_tag_questions_eager_loads_user_category_and_tags(): void
    {
        $tag = $this->createTag(['slug' => 'relations']);
        $question = Question::factory()->published()->create();
        $question->tags()->attach($tag);

        $payload = $this->getJson('/api/tags/relations/questions')
            ->assertOk()
            ->json('data.0');

        $this->assertIsArray($payload['user']);
        $this->assertIsArray($payload['category']);
        $this->assertIsArray($payload['tags']);
        $this->assertNotEmpty($payload['tags']);
    }

    public function test_admin_sees_can_permissions_on_embedded_tag_resource(): void
    {
        $tag = $this->createTag(['slug' => 'admin-tag']);
        $this->attachPublishedQuestions($tag, 1);
        $this->actingAsAdmin();

        $this->getJson('/api/tags/admin-tag/questions')
            ->assertOk()
            ->assertJsonPath('tag.can.update', true)
            ->assertJsonPath('tag.can.delete', true);
    }

    public function test_guest_sees_false_can_permissions_on_embedded_tag_resource(): void
    {
        $tag = $this->createTag(['slug' => 'guest-tag']);
        $this->attachPublishedQuestions($tag, 1);

        $this->getJson('/api/tags/guest-tag/questions')
            ->assertOk()
            ->assertJsonPath('tag.can.update', false)
            ->assertJsonPath('tag.can.delete', false);
    }

    public function test_tag_questions_endpoint_is_public_without_authentication(): void
    {
        $tag = $this->createTag(['slug' => 'public']);
        $this->attachPublishedQuestions($tag, 1);

        $this->getJson('/api/tags/public/questions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
