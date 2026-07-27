<?php

namespace Tests\Feature\User;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserStatsAndActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_return_published_counts_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Question::factory()->count(2)->published()->create(['user_id' => $user->id]);
        Question::factory()->create(['user_id' => $user->id, 'published' => false]);
        Question::factory()->published()->create(['user_id' => $other->id]);

        Answer::factory()->count(3)->create([
            'user_id' => $user->id,
            'published' => true,
            'published_at' => now(),
        ]);
        Answer::factory()->create([
            'user_id' => $user->id,
            'published' => false,
            'published_at' => null,
        ]);

        $question = Question::factory()->published()->create();
        Comment::factory()->count(4)->create([
            'user_id' => $user->id,
            'commentable_type' => Question::class,
            'commentable_id' => $question->id,
            'published' => true,
            'published_at' => now(),
        ]);
        Comment::factory()->create([
            'user_id' => $user->id,
            'commentable_type' => Question::class,
            'commentable_id' => $question->id,
            'published' => false,
            'published_at' => null,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/user/stats')
            ->assertOk()
            ->assertExactJson([
                'questionsCount' => 2,
                'answersCount' => 3,
                'commentsCount' => 4,
            ]);
    }

    public function test_stats_are_zero_for_new_user(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/user/stats')
            ->assertOk()
            ->assertExactJson([
                'questionsCount' => 0,
                'answersCount' => 0,
                'commentsCount' => 0,
            ]);
    }

    public function test_guest_cannot_view_stats(): void
    {
        $this->getJson('/api/user/stats')->assertUnauthorized();
    }

    public function test_activity_returns_merged_sorted_items_limited_to_ten(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $i) {
            Question::factory()->published()->create([
                'user_id' => $user->id,
                'title' => "Question {$i}",
                'created_at' => now()->subMinutes(60 - $i),
            ]);
        }

        foreach (range(1, 6) as $i) {
            $question = Question::factory()->published()->create();
            Answer::factory()->create([
                'user_id' => $user->id,
                'question_id' => $question->id,
                'published' => true,
                'published_at' => now(),
                'created_at' => now()->subMinutes(40 - $i),
            ]);
        }

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/activity');

        $response->assertOk();
        $activities = $response->json();
        $this->assertCount(10, $activities);
        $this->assertContains($activities[0]['type'], ['question', 'answer', 'comment']);

        $timestamps = array_map(fn ($item) => $item['created_at'], $activities);
        $sorted = $timestamps;
        rsort($sorted);
        $this->assertSame($sorted, $timestamps);
    }

    public function test_activity_excludes_unpublished_content(): void
    {
        $user = User::factory()->create();

        Question::factory()->create([
            'user_id' => $user->id,
            'published' => false,
            'title' => 'Draft Question',
        ]);

        Question::factory()->published()->create([
            'user_id' => $user->id,
            'title' => 'Live Question',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/activity')->assertOk();
        $descriptions = collect($response->json())->pluck('description')->all();

        $this->assertTrue(collect($descriptions)->contains(fn ($d) => str_contains($d, 'Live Question')));
        $this->assertFalse(collect($descriptions)->contains(fn ($d) => str_contains($d, 'Draft Question')));
    }

    public function test_activity_does_not_include_other_users_content(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Question::factory()->published()->create([
            'user_id' => $other->id,
            'title' => 'Other User Question',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/user/activity')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_guest_cannot_view_activity(): void
    {
        $this->getJson('/api/user/activity')->assertUnauthorized();
    }

    public function test_activity_includes_persian_descriptions_and_expected_shape(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create([
            'user_id' => $user->id,
            'title' => 'عنوان تست',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/user/activity')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 'question_'.$question->id,
                'type' => 'question',
                'description' => 'سوال جدید: عنوان تست',
                'question_slug' => $question->slug,
            ]);
    }
}
