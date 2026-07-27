<?php

namespace Tests\Feature\Tag;

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTags;
use Tests\TestCase;

class TagDestroyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTags;

    public function test_guest_cannot_delete_tag(): void
    {
        $tag = $this->createTag(['slug' => 'protected']);

        $this->deleteJson('/api/tags/protected')
            ->assertUnauthorized();

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_authenticated_non_admin_cannot_delete_tag(): void
    {
        $tag = $this->createTag(['slug' => 'protected']);
        $this->actingAsUser();

        $this->deleteJson('/api/tags/protected')
            ->assertForbidden();

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_admin_can_delete_tag_and_receives_no_content(): void
    {
        $tag = $this->createTag(['name' => 'Remove Me', 'slug' => 'remove-me']);
        $this->actingAsAdmin();

        $this->deleteJson('/api/tags/remove-me')
            ->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseCount('tags', 0);
    }

    public function test_deleting_tag_cascades_question_tag_pivot_rows(): void
    {
        $tag = $this->createTag(['slug' => 'cascade']);
        $this->attachPublishedQuestions($tag, 2);
        $this->actingAsAdmin();

        $this->assertDatabaseCount('question_tag', 2);

        $this->deleteJson('/api/tags/cascade')->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseCount('question_tag', 0);
    }

    public function test_deleting_tag_does_not_delete_related_questions(): void
    {
        $tag = $this->createTag(['slug' => 'keep-questions']);
        $this->attachPublishedQuestions($tag, 1);
        $this->actingAsAdmin();

        $this->deleteJson('/api/tags/keep-questions')->assertNoContent();

        $this->assertDatabaseCount('questions', 1);
    }

    public function test_destroy_returns_404_for_unknown_slug(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson('/api/tags/missing')->assertNotFound();
    }

    public function test_deleted_tag_cannot_be_shown_or_listed(): void
    {
        $tag = $this->createTag(['name' => 'Gone', 'slug' => 'gone']);
        $this->actingAsAdmin();

        $this->deleteJson('/api/tags/gone')->assertNoContent();

        $this->getJson('/api/tags/gone')->assertNotFound();
        $this->getJson('/api/tags')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertNull(Tag::find($tag->id));
    }

    public function test_tags_are_hard_deleted_not_soft_deleted(): void
    {
        // Tag model does not use SoftDeletes — document hard-delete behavior.
        $tag = $this->createTag(['slug' => 'hard-delete']);
        $this->actingAsAdmin();

        $this->deleteJson('/api/tags/hard-delete')->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertFalse(method_exists($tag, 'trashed'));
    }

    public function test_admin_can_delete_one_tag_without_affecting_others(): void
    {
        $keep = $this->createTag(['slug' => 'keep']);
        $this->createTag(['slug' => 'drop']);
        $this->actingAsAdmin();

        $this->deleteJson('/api/tags/drop')->assertNoContent();

        $this->assertDatabaseHas('tags', ['id' => $keep->id, 'slug' => 'keep']);
        $this->assertDatabaseMissing('tags', ['slug' => 'drop']);
        $this->assertDatabaseCount('tags', 1);
    }
}
