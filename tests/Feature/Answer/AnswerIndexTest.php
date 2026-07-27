<?php

namespace Tests\Feature\Answer;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAnswers;
use Tests\TestCase;

class AnswerIndexTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAnswers;

    public function test_guest_can_list_published_answers_with_pagination_meta(): void
    {
        $question = $this->createPublishedQuestion();
        $visible = $this->createAnswerForQuestion($question, ['content' => 'Visible answer']);
        $this->createAnswerForQuestion($question, ['content' => 'Hidden draft'], published: false);

        $response = $this->getJson("/api/questions/{$question->id}/answers");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'question_id',
                        'user_id',
                        'content',
                        'published',
                        'published_at',
                        'is_correct',
                        'created_at',
                        'votes' => ['upvotes', 'downvotes', 'user_vote'],
                        'can' => ['toggle_correctness', 'update', 'delete', 'publish'],
                    ],
                ],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_index_paginates_ten_answers_per_page(): void
    {
        $question = $this->createPublishedQuestion();
        Answer::factory()->published()->count(15)->create(['question_id' => $question->id]);

        $response = $this->getJson("/api/questions/{$question->id}/answers");

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.current_page', 1);

        $this->getJson("/api/questions/{$question->id}/answers?page=2")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_authenticated_user_sees_own_unpublished_answers(): void
    {
        $user = User::factory()->create(['level' => 2]);
        $other = User::factory()->create(['level' => 2]);
        $question = $this->createPublishedQuestion();

        $own = $this->createAnswerForQuestion($question, [
            'user_id' => $user->id,
            'content' => 'Mine',
        ], published: false);
        $this->createAnswerForQuestion($question, [
            'user_id' => $other->id,
            'content' => 'Theirs',
        ], published: false);

        Sanctum::actingAs($user);

        $this->getJson("/api/questions/{$question->id}/answers")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    public function test_higher_level_user_sees_lower_level_unpublished_answers(): void
    {
        $viewer = User::factory()->create(['level' => 4]);
        $author = User::factory()->create(['level' => 1]);
        $question = $this->createPublishedQuestion();
        $answer = $this->createAnswerForQuestion($question, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($viewer);

        $this->getJson("/api/questions/{$question->id}/answers")
            ->assertOk()
            ->assertJsonPath('data.0.id', $answer->id);
    }

    public function test_same_level_user_cannot_see_another_users_unpublished_answer(): void
    {
        $viewer = User::factory()->create(['level' => 2]);
        $author = User::factory()->create(['level' => 2]);
        $question = $this->createPublishedQuestion();
        $this->createAnswerForQuestion($question, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($viewer);

        $this->getJson("/api/questions/{$question->id}/answers")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_sort_newest_is_default_and_explicit(): void
    {
        $question = $this->createPublishedQuestion();
        $older = $this->createAnswerForQuestion($question, [
            'content' => 'Older',
            'created_at' => now()->subDay(),
        ]);
        $newer = $this->createAnswerForQuestion($question, [
            'content' => 'Newer',
            'created_at' => now(),
        ]);

        $default = $this->getJson("/api/questions/{$question->id}/answers")->assertOk();
        $newest = $this->getJson("/api/questions/{$question->id}/answers?sort=newest")->assertOk();

        $this->assertSame($newer->id, $default->json('data.0.id'));
        $this->assertSame($newer->id, $newest->json('data.0.id'));
        $this->assertSame($older->id, $default->json('data.1.id'));
    }

    public function test_index_sort_oldest_orders_ascending(): void
    {
        $question = $this->createPublishedQuestion();
        $older = $this->createAnswerForQuestion($question, ['created_at' => now()->subDay()]);
        $newer = $this->createAnswerForQuestion($question, ['created_at' => now()]);

        $this->getJson("/api/questions/{$question->id}/answers?sort=oldest")
            ->assertOk()
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.1.id', $newer->id);
    }

    public function test_index_sort_votes_orders_by_net_score(): void
    {
        $question = $this->createPublishedQuestion();
        $low = $this->createAnswerForQuestion($question);
        $high = $this->createAnswerForQuestion($question);

        $high->votes()->create(['user_id' => User::factory()->create()->id, 'type' => 'up']);
        $high->votes()->create(['user_id' => User::factory()->create()->id, 'type' => 'up']);
        $low->votes()->create(['user_id' => User::factory()->create()->id, 'type' => 'down']);

        $this->getJson("/api/questions/{$question->id}/answers?sort=votes")
            ->assertOk()
            ->assertJsonPath('data.0.id', $high->id)
            ->assertJsonPath('data.1.id', $low->id);
    }

    public function test_index_sort_comments_orders_by_comment_count(): void
    {
        $question = $this->createPublishedQuestion();
        $few = $this->createAnswerForQuestion($question);
        $many = $this->createAnswerForQuestion($question);

        Comment::factory()->count(3)->create([
            'commentable_type' => Answer::class,
            'commentable_id' => $many->id,
        ]);
        Comment::factory()->create([
            'commentable_type' => Answer::class,
            'commentable_id' => $few->id,
        ]);

        $this->getJson("/api/questions/{$question->id}/answers?sort=comments")
            ->assertOk()
            ->assertJsonPath('data.0.id', $many->id)
            ->assertJsonPath('data.1.id', $few->id);
    }

    public function test_index_sort_correct_returns_only_correct_answers(): void
    {
        $question = $this->createPublishedQuestion();
        $correct = $this->createAnswerForQuestion($question, ['is_correct' => true]);
        $this->createAnswerForQuestion($question, ['is_correct' => false]);

        $this->getJson("/api/questions/{$question->id}/answers?sort=correct")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $correct->id)
            ->assertJsonPath('data.0.is_correct', true);
    }

    public function test_index_only_returns_answers_for_requested_question(): void
    {
        $questionA = $this->createPublishedQuestion();
        $questionB = $this->createPublishedQuestion();
        $answerA = $this->createAnswerForQuestion($questionA);
        $this->createAnswerForQuestion($questionB);

        $this->getJson("/api/questions/{$questionA->id}/answers")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $answerA->id);
    }

    public function test_index_returns_404_for_missing_question(): void
    {
        $this->getJson('/api/questions/999999/answers')->assertNotFound();
    }

    public function test_guest_can_abilities_are_false(): void
    {
        $question = $this->createPublishedQuestion();
        $this->createAnswerForQuestion($question);

        $this->getJson("/api/questions/{$question->id}/answers")
            ->assertOk()
            ->assertJsonPath('data.0.can.update', false)
            ->assertJsonPath('data.0.can.delete', false)
            ->assertJsonPath('data.0.can.publish', false)
            ->assertJsonPath('data.0.can.toggle_correctness', false);
    }
}
