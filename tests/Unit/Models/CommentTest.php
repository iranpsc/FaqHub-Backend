<?php

namespace Tests\Unit\Models;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_scope_requires_published_at(): void
    {
        Comment::factory()->create([
            'published' => true,
            'published_at' => null,
        ]);
        $complete = Comment::factory()->published()->create();

        $ids = Comment::published()->pluck('id');

        $this->assertTrue($ids->contains($complete->id));
        $this->assertCount(1, $ids);
    }

    public function test_visible_scope_for_guests_and_authenticated_users(): void
    {
        $low = User::factory()->create(['level' => 1]);
        $high = User::factory()->create(['level' => 3]);

        $published = Comment::factory()->published()->create(['user_id' => $low->id]);
        $ownDraft = Comment::factory()->unpublished()->create(['user_id' => $high->id]);
        $lowerDraft = Comment::factory()->unpublished()->create(['user_id' => $low->id]);
        $peerDraft = Comment::factory()->unpublished()->create([
            'user_id' => User::factory()->create(['level' => 3])->id,
        ]);

        $guestIds = Comment::visible(null)->pluck('id');
        $this->assertEquals([$published->id], $guestIds->all());

        $highIds = Comment::visible($high)->pluck('id');
        $this->assertTrue($highIds->contains($published->id));
        $this->assertTrue($highIds->contains($ownDraft->id));
        $this->assertTrue($highIds->contains($lowerDraft->id));
        $this->assertFalse($highIds->contains($peerDraft->id));
    }

    public function test_relationships_resolve_expected_models(): void
    {
        $publisher = User::factory()->create();
        $author = User::factory()->create();
        $question = Question::factory()->create();
        $comment = Comment::factory()->published($publisher)->for($question, 'commentable')->create([
            'user_id' => $author->id,
        ]);

        $this->assertTrue($comment->user->is($author));
        $this->assertTrue($comment->publisher->is($publisher));
        $this->assertTrue($comment->commentable->is($question));
        $this->assertInstanceOf(MorphMany::class, $comment->votes());
        $this->assertInstanceOf(MorphMany::class, $comment->upVotes());
        $this->assertInstanceOf(MorphMany::class, $comment->downVotes());
    }

    public function test_commentable_can_be_answer(): void
    {
        $answer = Answer::factory()->create();
        $comment = Comment::factory()->for($answer, 'commentable')->create();

        $this->assertInstanceOf(Answer::class, $comment->commentable);
        $this->assertTrue($comment->commentable->is($answer));
    }

    public function test_casts_booleans_and_datetime(): void
    {
        $comment = Comment::factory()->published()->create();

        $this->assertTrue($comment->published);
        $this->assertInstanceOf(Carbon::class, $comment->published_at);
    }

    public function test_up_and_down_votes_scopes_filter_by_type(): void
    {
        $comment = Comment::factory()->published()->create();
        $upVoter = User::factory()->create();
        $downVoter = User::factory()->create();

        $comment->votes()->create(['user_id' => $upVoter->id, 'type' => 'up']);
        $comment->votes()->create(['user_id' => $downVoter->id, 'type' => 'down']);

        $this->assertEquals(1, $comment->upVotes()->count());
        $this->assertEquals(1, $comment->downVotes()->count());
        $this->assertEquals(2, $comment->votes()->count());
    }
}
