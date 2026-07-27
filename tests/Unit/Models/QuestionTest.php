<?php

namespace Tests\Unit\Models;

use App\Models\Answer;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_slug_replaces_spaces_and_lowercases(): void
    {
        $this->assertSame('hello-world', Question::generateSlug('Hello World'));
    }

    public function test_generate_slug_appends_counter_for_duplicates(): void
    {
        Question::factory()->create(['slug' => 'hello-world']);
        Question::factory()->create(['slug' => 'hello-world-1']);

        $this->assertSame('hello-world-2', Question::generateSlug('Hello World'));
    }

    public function test_published_scope_requires_published_at(): void
    {
        Question::factory()->create([
            'published' => true,
            'published_at' => null,
            'slug' => 'incomplete',
        ]);
        $complete = Question::factory()->published()->create(['slug' => 'complete']);

        $ids = Question::published()->pluck('id');

        $this->assertTrue($ids->contains($complete->id));
        $this->assertCount(1, $ids);
    }

    public function test_is_solved_depends_on_correct_answer_only(): void
    {
        $question = Question::factory()->create();

        $this->assertFalse($question->isSolved());

        Answer::factory()->create([
            'question_id' => $question->id,
            'is_correct' => false,
        ]);
        $this->assertFalse($question->fresh()->isSolved());

        Answer::factory()->create([
            'question_id' => $question->id,
            'is_correct' => true,
        ]);
        $this->assertTrue($question->fresh()->isSolved());
    }

    public function test_visible_scope_for_guests_and_authenticated_users(): void
    {
        $low = User::factory()->create(['level' => 1]);
        $high = User::factory()->create(['level' => 3]);

        $published = Question::factory()->published()->create(['user_id' => $low->id]);
        $ownDraft = Question::factory()->unpublished()->create(['user_id' => $high->id]);
        $lowerDraft = Question::factory()->unpublished()->create(['user_id' => $low->id]);
        $peerDraft = Question::factory()->unpublished()->create([
            'user_id' => User::factory()->create(['level' => 3])->id,
        ]);

        $guestIds = Question::visible(null)->pluck('id');
        $this->assertEquals([$published->id], $guestIds->all());

        $highIds = Question::visible($high)->pluck('id');
        $this->assertTrue($highIds->contains($published->id));
        $this->assertTrue($highIds->contains($ownDraft->id));
        $this->assertTrue($highIds->contains($lowerDraft->id));
        $this->assertFalse($highIds->contains($peerDraft->id));
    }
}
