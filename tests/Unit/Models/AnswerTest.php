<?php

namespace Tests\Unit\Models;

use App\Models\Answer;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnswerTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_scope_requires_published_at(): void
    {
        Answer::factory()->create([
            'published' => true,
            'published_at' => null,
        ]);
        $complete = Answer::factory()->published()->create();

        $ids = Answer::published()->pluck('id');

        $this->assertTrue($ids->contains($complete->id));
        $this->assertCount(1, $ids);
    }

    public function test_visible_scope_for_guests_and_authenticated_users(): void
    {
        $low = User::factory()->create(['level' => 1]);
        $high = User::factory()->create(['level' => 3]);

        $published = Answer::factory()->published()->create(['user_id' => $low->id]);
        $ownDraft = Answer::factory()->unpublished()->create(['user_id' => $high->id]);
        $lowerDraft = Answer::factory()->unpublished()->create(['user_id' => $low->id]);
        $peerDraft = Answer::factory()->unpublished()->create([
            'user_id' => User::factory()->create(['level' => 3])->id,
        ]);

        $guestIds = Answer::visible(null)->pluck('id');
        $this->assertEquals([$published->id], $guestIds->all());

        $highIds = Answer::visible($high)->pluck('id');
        $this->assertTrue($highIds->contains($published->id));
        $this->assertTrue($highIds->contains($ownDraft->id));
        $this->assertTrue($highIds->contains($lowerDraft->id));
        $this->assertFalse($highIds->contains($peerDraft->id));
    }

    public function test_relationships_resolve_expected_models(): void
    {
        $publisher = User::factory()->create();
        $author = User::factory()->create();
        $answer = Answer::factory()->published($publisher)->create(['user_id' => $author->id]);

        $this->assertTrue($answer->user->is($author));
        $this->assertTrue($answer->publisher->is($publisher));
        $this->assertNotNull($answer->question);
        $this->assertInstanceOf(MorphMany::class, $answer->votes());
        $this->assertInstanceOf(HasMany::class, $answer->correctnessMarks());
    }

    public function test_casts_booleans_and_datetime(): void
    {
        $answer = Answer::factory()->published()->correct()->create();

        $this->assertTrue($answer->published);
        $this->assertTrue($answer->is_correct);
        $this->assertInstanceOf(Carbon::class, $answer->published_at);
    }
}
