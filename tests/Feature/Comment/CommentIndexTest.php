<?php

namespace Tests\Feature\Comment;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithComments;
use Tests\TestCase;

class CommentIndexTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithComments;

    public function test_guest_can_list_published_question_comments_with_pagination_meta(): void
    {
        $question = $this->createPublishedQuestion();
        $visible = $this->createCommentOnQuestion($question, ['content' => 'Visible comment']);
        $this->createCommentOnQuestion($question, ['content' => 'Hidden draft'], published: false);

        $response = $this->getJson("/api/questions/{$question->id}/comments");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'content',
                        'published',
                        'published_at',
                        'published_by',
                        'created_at',
                        'updated_at',
                        'votes' => ['upvotes', 'downvotes', 'score', 'user_vote'],
                        'can' => ['update', 'delete', 'publish'],
                    ],
                ],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_guest_can_list_published_answer_comments(): void
    {
        $answer = Answer::factory()->published()->create();
        $visible = $this->createCommentOnAnswer($answer);
        $this->createCommentOnAnswer($answer, [], published: false);

        $this->getJson("/api/answers/{$answer->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_index_paginates_ten_comments_per_page(): void
    {
        $question = $this->createPublishedQuestion();
        Comment::factory()->published()->count(15)->for($question, 'commentable')->create();

        $response = $this->getJson("/api/questions/{$question->id}/comments");

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.current_page', 1);

        $this->getJson("/api/questions/{$question->id}/comments?page=2")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_index_returns_empty_collection_when_no_comments_exist(): void
    {
        $question = $this->createPublishedQuestion();

        $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_authenticated_user_sees_own_unpublished_comments(): void
    {
        $user = User::factory()->create(['level' => 2]);
        $other = User::factory()->create(['level' => 2]);
        $question = $this->createPublishedQuestion();

        $own = $this->createCommentOnQuestion($question, [
            'user_id' => $user->id,
            'content' => 'Mine',
        ], published: false);
        $this->createCommentOnQuestion($question, [
            'user_id' => $other->id,
            'content' => 'Theirs',
        ], published: false);

        Sanctum::actingAs($user);

        $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    public function test_higher_level_user_sees_lower_level_unpublished_comments(): void
    {
        $viewer = User::factory()->create(['level' => 4]);
        $author = User::factory()->create(['level' => 1]);
        $question = $this->createPublishedQuestion();
        $comment = $this->createCommentOnQuestion($question, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($viewer);

        $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.id', $comment->id);
    }

    public function test_same_level_user_cannot_see_another_users_unpublished_comment(): void
    {
        $viewer = User::factory()->create(['level' => 2]);
        $author = User::factory()->create(['level' => 2]);
        $question = $this->createPublishedQuestion();
        $this->createCommentOnQuestion($question, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($viewer);

        $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_lower_level_user_cannot_see_higher_level_unpublished_comments(): void
    {
        $viewer = User::factory()->create(['level' => 1]);
        $author = User::factory()->create(['level' => 3]);
        $question = $this->createPublishedQuestion();
        $this->createCommentOnQuestion($question, [
            'user_id' => $author->id,
        ], published: false);

        Sanctum::actingAs($viewer);

        $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_guest_can_permissions_are_false(): void
    {
        $question = $this->createPublishedQuestion();
        $this->createCommentOnQuestion($question);

        $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.can.update', false)
            ->assertJsonPath('data.0.can.delete', false)
            ->assertJsonPath('data.0.can.publish', false)
            ->assertJsonPath('data.0.votes.user_vote', null);
    }

    public function test_index_includes_user_vote_for_authenticated_voter(): void
    {
        $voter = User::factory()->create();
        $question = $this->createPublishedQuestion();
        $comment = $this->createCommentOnQuestion($question);
        $comment->votes()->create(['user_id' => $voter->id, 'type' => 'up']);

        Sanctum::actingAs($voter);

        $this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.votes.upvotes', 1)
            ->assertJsonPath('data.0.votes.user_vote', 'up');
    }

    public function test_index_returns_404_for_missing_question(): void
    {
        $this->getJson('/api/questions/999999/comments')->assertNotFound();
    }

    public function test_index_returns_404_for_missing_answer(): void
    {
        $this->getJson('/api/answers/999999/comments')->assertNotFound();
    }

    public function test_comments_on_different_parents_are_isolated(): void
    {
        $questionA = $this->createPublishedQuestion();
        $questionB = $this->createPublishedQuestion();
        $commentA = $this->createCommentOnQuestion($questionA);
        $this->createCommentOnQuestion($questionB);

        $this->getJson("/api/questions/{$questionA->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $commentA->id);
    }

    public function test_index_orders_comments_latest_first(): void
    {
        $question = $this->createPublishedQuestion();
        $older = $this->createCommentOnQuestion($question, [
            'content' => 'Older',
            'created_at' => now()->subDay(),
        ]);
        $newer = $this->createCommentOnQuestion($question, [
            'content' => 'Newer',
            'created_at' => now(),
        ]);

        $ids = collect($this->getJson("/api/questions/{$question->id}/comments")
            ->assertOk()
            ->json('data'))->pluck('id');

        $this->assertEquals([$newer->id, $older->id], $ids->all());
    }
}
