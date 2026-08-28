<?php

namespace Tests\Feature\Question;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithQuestions;
use Tests\TestCase;

class QuestionPublishTest extends TestCase
{
    use InteractsWithQuestions;
    use RefreshDatabase;

    public function test_higher_level_user_can_publish_lower_level_users_question_and_earns_score(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $publisher = User::factory()->create(['level' => 3, 'score' => 0]);
        $question = $this->createUnpublishedQuestion(['user_id' => $author->id]);

        Sanctum::actingAs($publisher);

        $this->postJson("/api/questions/{$question->id}/publish")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'سوال با موفقیت منتشر شد')
            ->assertJsonPath('data.published', true)
            ->assertJsonPath('data.published_by', $publisher->id);

        $this->assertDatabaseHas('questions', [
            'id' => $question->id,
            'published' => true,
            'published_by' => $publisher->id,
        ]);
        $this->assertNotNull($question->fresh()->published_at);
        $this->assertEquals(2, $publisher->fresh()->score);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'published_question',
            'subject_id' => $question->id,
            'causer_id' => $publisher->id,
        ]);
    }

    public function test_owner_with_level_two_or_higher_can_publish_own_question(): void
    {
        $owner = User::factory()->create(['level' => 2, 'score' => 5]);
        $question = $this->createUnpublishedQuestion(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/questions/{$question->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.published', true);

        $this->assertEquals(7, $owner->fresh()->score);
    }

    public function test_guest_cannot_publish_question(): void
    {
        $question = $this->createUnpublishedQuestion();

        $this->postJson("/api/questions/{$question->id}/publish")
            ->assertUnauthorized();

        $this->assertFalse($question->fresh()->published);
    }

    public function test_level_one_user_cannot_publish_any_question(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 1]);
        $own = $this->createUnpublishedQuestion(['user_id' => $actor->id]);
        $other = $this->createUnpublishedQuestion(['user_id' => $author->id]);

        Sanctum::actingAs($actor);

        $this->postJson("/api/questions/{$own->id}/publish")->assertForbidden();
        $this->postJson("/api/questions/{$other->id}/publish")->assertForbidden();
    }

    public function test_cannot_publish_already_published_question(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $publisher = User::factory()->create(['level' => 4]);
        $question = $this->createPublishedQuestion(['user_id' => $author->id]);

        Sanctum::actingAs($publisher);

        $this->postJson("/api/questions/{$question->id}/publish")->assertForbidden();
    }

    public function test_cannot_publish_question_from_same_or_higher_level_non_owner(): void
    {
        $actor = User::factory()->create(['level' => 2]);
        $same = User::factory()->create(['level' => 2]);
        $higher = User::factory()->create(['level' => 3]);

        $sameLevelQuestion = $this->createUnpublishedQuestion(['user_id' => $same->id]);
        $higherLevelQuestion = $this->createUnpublishedQuestion(['user_id' => $higher->id]);

        Sanctum::actingAs($actor);

        $this->postJson("/api/questions/{$sameLevelQuestion->id}/publish")->assertForbidden();
        $this->postJson("/api/questions/{$higherLevelQuestion->id}/publish")->assertForbidden();
    }

    public function test_cannot_publish_question_with_null_owner(): void
    {
        $question = $this->createUnpublishedQuestion();
        $question->forceFill(['user_id' => null])->saveQuietly();
        $actor = User::factory()->create(['level' => 5]);

        Sanctum::actingAs($actor);

        $this->postJson("/api/questions/{$question->id}/publish")->assertForbidden();
    }
}
