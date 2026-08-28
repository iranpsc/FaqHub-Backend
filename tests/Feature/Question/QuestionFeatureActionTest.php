<?php

namespace Tests\Feature\Question;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithQuestions;
use Tests\TestCase;

class QuestionFeatureActionTest extends TestCase
{
    use InteractsWithQuestions;
    use RefreshDatabase;

    public function test_level_four_user_can_feature_another_users_published_question(): void
    {
        $author = User::factory()->create(['level' => 2]);
        $actor = User::factory()->create(['level' => 4]);
        $question = $this->createPublishedQuestion(['user_id' => $author->id, 'featured' => false]);

        Sanctum::actingAs($actor);

        $this->postJson("/api/questions/{$question->id}/feature")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_featured_by_user', true);

        $this->assertTrue($question->fresh()->featured);
        $this->assertDatabaseHas('user_featured_questions', [
            'user_id' => $actor->id,
            'question_id' => $question->id,
            'type' => 'featured',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'description' => 'featured_question',
            'subject_id' => $question->id,
            'causer_id' => $actor->id,
        ]);
    }

    public function test_user_cannot_feature_own_question(): void
    {
        $owner = User::factory()->create(['level' => 5]);
        $question = $this->createPublishedQuestion(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/questions/{$question->id}/feature")->assertForbidden();
    }

    public function test_user_below_level_four_cannot_feature(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 3]);
        $question = $this->createPublishedQuestion(['user_id' => $author->id]);

        Sanctum::actingAs($actor);

        $this->postJson("/api/questions/{$question->id}/feature")->assertForbidden();
    }

    public function test_cannot_feature_unpublished_question(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 5]);
        $question = $this->createUnpublishedQuestion(['user_id' => $author->id]);

        Sanctum::actingAs($actor);

        $this->postJson("/api/questions/{$question->id}/feature")->assertForbidden();
    }

    public function test_cannot_feature_same_question_twice(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 5]);
        $question = $this->createPublishedQuestion(['user_id' => $author->id]);
        $actor->featuredQuestions()->create([
            'question_id' => $question->id,
            'featured_at' => now(),
            'type' => 'featured',
        ]);

        Sanctum::actingAs($actor);

        $this->postJson("/api/questions/{$question->id}/feature")->assertForbidden();
    }

    public function test_cannot_feature_more_than_two_questions(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 5]);
        $q1 = $this->createPublishedQuestion(['user_id' => $author->id]);
        $q2 = $this->createPublishedQuestion(['user_id' => $author->id]);
        $q3 = $this->createPublishedQuestion(['user_id' => $author->id]);

        $actor->featuredQuestions()->create([
            'question_id' => $q1->id,
            'featured_at' => now(),
            'type' => 'featured',
        ]);
        $actor->featuredQuestions()->create([
            'question_id' => $q2->id,
            'featured_at' => now(),
            'type' => 'featured',
        ]);

        Sanctum::actingAs($actor);

        $this->postJson("/api/questions/{$q3->id}/feature")->assertForbidden();
    }

    public function test_level_four_user_can_unfeature_featured_question(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 4]);
        $question = $this->createPublishedQuestion([
            'user_id' => $author->id,
            'featured' => true,
        ]);

        Sanctum::actingAs($actor);

        $this->deleteJson("/api/questions/{$question->id}/feature")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('featured_at', null);

        $this->assertFalse($question->fresh()->featured);
        $this->assertDatabaseHas('user_featured_questions', [
            'user_id' => $actor->id,
            'question_id' => $question->id,
            'type' => 'unfeatured',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'description' => 'unfeatured_question',
            'subject_id' => $question->id,
            'causer_id' => $actor->id,
        ]);
    }

    public function test_cannot_unfeature_when_question_not_featured(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 5]);
        $question = $this->createPublishedQuestion([
            'user_id' => $author->id,
            'featured' => false,
        ]);

        Sanctum::actingAs($actor);

        $this->deleteJson("/api/questions/{$question->id}/feature")->assertForbidden();
    }

    public function test_cannot_unfeature_own_question(): void
    {
        $owner = User::factory()->create(['level' => 5]);
        $question = $this->createPublishedQuestion([
            'user_id' => $owner->id,
            'featured' => true,
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/questions/{$question->id}/feature")->assertForbidden();
    }

    public function test_cannot_unfeature_more_than_two_questions(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 5]);
        $q1 = $this->createPublishedQuestion(['user_id' => $author->id, 'featured' => true]);
        $q2 = $this->createPublishedQuestion(['user_id' => $author->id, 'featured' => true]);
        $q3 = $this->createPublishedQuestion(['user_id' => $author->id, 'featured' => true]);

        $actor->unfeaturedQuestions()->create([
            'question_id' => $q1->id,
            'featured_at' => now(),
            'type' => 'unfeatured',
        ]);
        $actor->unfeaturedQuestions()->create([
            'question_id' => $q2->id,
            'featured_at' => now(),
            'type' => 'unfeatured',
        ]);

        Sanctum::actingAs($actor);

        $this->deleteJson("/api/questions/{$q3->id}/feature")->assertForbidden();
    }

    public function test_guest_cannot_feature_or_unfeature(): void
    {
        $question = $this->createPublishedQuestion(['featured' => true]);

        $this->postJson("/api/questions/{$question->id}/feature")->assertUnauthorized();
        $this->deleteJson("/api/questions/{$question->id}/feature")->assertUnauthorized();
    }
}
