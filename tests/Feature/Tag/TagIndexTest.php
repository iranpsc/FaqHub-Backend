<?php

namespace Tests\Feature\Tag;

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTags;
use Tests\TestCase;

class TagIndexTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTags;

    public function test_guest_can_list_tags_with_pagination_meta_and_questions_count(): void
    {
        $tag = $this->createTag(['name' => 'Eloquent', 'slug' => 'eloquent']);
        $this->attachPublishedQuestions($tag, 2);

        $response = $this->getJson('/api/tags');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tag->id)
            ->assertJsonPath('data.0.name', 'Eloquent')
            ->assertJsonPath('data.0.slug', 'eloquent')
            ->assertJsonPath('data.0.questions_count', 2)
            ->assertJsonPath('data.0.can.update', false)
            ->assertJsonPath('data.0.can.delete', false)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'questions_count',
                        'can' => ['update', 'delete'],
                    ],
                ],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_index_defaults_to_twelve_tags_per_page(): void
    {
        Tag::factory()->count(15)->create();

        $this->getJson('/api/tags')
            ->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.per_page', 12)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.current_page', 1);

        $this->getJson('/api/tags?page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_index_respects_custom_per_page(): void
    {
        Tag::factory()->count(10)->create();

        $this->getJson('/api/tags?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 10);
    }

    public function test_index_filters_tags_by_partial_name_query(): void
    {
        $this->createTag(['name' => 'Laravel', 'slug' => 'laravel']);
        $this->createTag(['name' => 'PHPUnit', 'slug' => 'phpunit']);
        $this->createTag(['name' => 'Livewire', 'slug' => 'livewire']);

        $this->getJson('/api/tags?query=lar')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Laravel');
    }

    public function test_index_empty_query_returns_all_tags(): void
    {
        Tag::factory()->count(3)->create();

        $this->getJson('/api/tags?query=')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_index_returns_empty_data_when_no_tags_exist(): void
    {
        $this->getJson('/api/tags')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_index_query_with_no_matches_returns_empty_collection(): void
    {
        $this->createTag(['name' => 'Vue', 'slug' => 'vue']);

        $this->getJson('/api/tags?query=nonexistent-tag-xyz')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_authenticated_admin_sees_can_permissions_as_true(): void
    {
        $this->createTag();
        $this->actingAsAdmin();

        $this->getJson('/api/tags')
            ->assertOk()
            ->assertJsonPath('data.0.can.update', true)
            ->assertJsonPath('data.0.can.delete', true);
    }

    public function test_authenticated_non_admin_sees_can_permissions_as_false(): void
    {
        $this->createTag();
        $this->actingAsUser();

        $this->getJson('/api/tags')
            ->assertOk()
            ->assertJsonPath('data.0.can.update', false)
            ->assertJsonPath('data.0.can.delete', false);
    }

    #[DataProvider('indexQueryEdgeCasesProvider')]
    public function test_index_handles_query_edge_cases(string $query): void
    {
        $this->createTag(['name' => 'Safe Tag', 'slug' => 'safe-tag']);

        $this->getJson('/api/tags?query='.urlencode($query))
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public static function indexQueryEdgeCasesProvider(): array
    {
        return [
            'sql injection attempt' => ["' OR 1=1 --"],
            'wildcard percent' => ['%'],
            'underscore wildcard' => ['_'],
            'unicode' => ['تست'],
            'very long query' => [str_repeat('a', 500)],
        ];
    }

    public function test_index_does_not_expose_unexpected_attributes(): void
    {
        $this->createTag();

        $tagPayload = $this->getJson('/api/tags')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('created_at', $tagPayload);
        $this->assertArrayNotHasKey('updated_at', $tagPayload);
        $this->assertArrayNotHasKey('deleted_at', $tagPayload);
    }

    public function test_index_questions_count_includes_unpublished_questions(): void
    {
        // withCount('questions') has no published filter — documents current behavior.
        $tag = $this->createTag();
        $this->attachPublishedQuestions($tag, 1);
        $this->attachUnpublishedQuestions($tag, 2);

        $this->getJson('/api/tags')
            ->assertOk()
            ->assertJsonPath('data.0.questions_count', 3);
    }
}
