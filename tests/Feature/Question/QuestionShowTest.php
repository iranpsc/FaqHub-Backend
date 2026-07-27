<?php

namespace Tests\Feature\Question;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithQuestions;
use Tests\TestCase;

class QuestionShowTest extends TestCase
{
    use InteractsWithQuestions;
    use RefreshDatabase;

    public function test_guest_can_show_published_question_by_slug_and_increments_views(): void
    {
        $question = $this->createPublishedQuestion([
            'title' => 'Published Show',
            'slug' => 'published-show',
            'views' => 3,
            'content' => 'Body content',
        ]);
        $tags = Tag::factory()->count(2)->create();
        $question->tags()->attach($tags);
        Answer::factory()->count(2)->create(['question_id' => $question->id, 'published' => true]);
        Comment::factory()->create([
            'commentable_type' => Question::class,
            'commentable_id' => $question->id,
            'published' => true,
        ]);

        $response = $this->getJson('/api/questions/published-show');

        $response->assertOk()
            ->assertJsonPath('data.id', $question->id)
            ->assertJsonPath('data.slug', 'published-show')
            ->assertJsonPath('data.title', 'Published Show')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'slug',
                    'content',
                    'user',
                    'category',
                    'tags',
                    'comments',
                    'votes',
                    'can',
                ],
            ]);

        $this->assertDatabaseHas('questions', [
            'id' => $question->id,
            'views' => 4,
        ]);
    }

    public function test_show_returns_404_for_unknown_slug(): void
    {
        $this->getJson('/api/questions/does-not-exist')->assertNotFound();
    }

    public function test_show_by_numeric_id_returns_404_because_route_binds_slug(): void
    {
        $question = $this->createPublishedQuestion(['slug' => 'real-slug']);

        $this->getJson("/api/questions/{$question->id}")->assertNotFound();
    }

    public function test_show_includes_pin_and_feature_status_for_authenticated_user(): void
    {
        $user = User::factory()->create(['level' => 5]);
        $question = $this->createPublishedQuestion(['slug' => 'pinned-featured']);
        $user->pinnedQuestions()->attach($question->id, ['pinned_at' => now()]);
        $user->featuredQuestions()->create([
            'question_id' => $question->id,
            'featured_at' => now(),
            'type' => 'featured',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/questions/pinned-featured')
            ->assertOk()
            ->assertJsonPath('data.is_pinned_by_user', true)
            ->assertJsonPath('data.is_featured_by_user', true);
    }

    public function test_show_loads_vote_counts_for_question(): void
    {
        $question = $this->createPublishedQuestion(['slug' => 'voted-question']);
        $question->votes()->create(['user_id' => User::factory()->create()->id, 'type' => 'up']);
        $question->votes()->create(['user_id' => User::factory()->create()->id, 'type' => 'up']);
        $question->votes()->create(['user_id' => User::factory()->create()->id, 'type' => 'down']);

        $response = $this->getJson('/api/questions/voted-question')->assertOk();

        $this->assertCount(2, $response->json('data.votes.upvotes'));
        $this->assertCount(1, $response->json('data.votes.downvotes'));
    }

    public function test_show_skips_policy_so_guest_can_open_unpublished_question_by_slug(): void
    {
        // Documented behavior: authorizeResource excludes show, so slug is enough.
        $question = $this->createUnpublishedQuestion([
            'slug' => 'secret-draft',
            'title' => 'Draft',
        ]);

        $this->getJson('/api/questions/secret-draft')
            ->assertOk()
            ->assertJsonPath('data.id', $question->id)
            ->assertJsonPath('data.published', false);
    }

    public function test_is_solved_is_true_when_correct_answer_exists(): void
    {
        $question = $this->createPublishedQuestion(['slug' => 'solved-q']);
        Answer::factory()->create([
            'question_id' => $question->id,
            'is_correct' => true,
            'published' => true,
        ]);

        $this->getJson('/api/questions/solved-q')
            ->assertOk()
            ->assertJsonPath('data.is_solved', true);
    }
}
