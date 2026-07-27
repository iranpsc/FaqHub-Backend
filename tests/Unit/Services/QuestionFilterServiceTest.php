<?php

namespace Tests\Unit\Services;

use App\Models\Answer;
use App\Models\Category;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use App\Services\QuestionFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class QuestionFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuestionFilterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(QuestionFilterService::class);
    }

    public function test_get_paginated_questions_defaults_to_ten_per_page(): void
    {
        Question::factory()->published()->count(12)->create();

        $paginator = $this->service->getPaginatedQuestions(Request::create('/api/questions', 'GET'), 10);

        $this->assertSame(10, $paginator->perPage());
        $this->assertSame(12, $paginator->total());
        $this->assertCount(10, $paginator->items());
    }

    public function test_filter_by_category_and_tags(): void
    {
        $category = Category::factory()->create();
        $tag = Tag::factory()->create();
        $match = Question::factory()->published()->create(['category_id' => $category->id]);
        $match->tags()->attach($tag);
        Question::factory()->published()->create();

        $byCategory = $this->service->filter(Request::create('/api/questions', 'GET', [
            'category_id' => $category->id,
        ]))->pluck('id');

        $this->assertEquals([$match->id], $byCategory->all());

        $byTag = $this->service->filter(Request::create('/api/questions', 'GET', [
            'tags' => (string) $tag->id,
        ]))->pluck('id');

        $this->assertEquals([$match->id], $byTag->all());
    }

    public function test_filter_unanswered_unsolved_solved_unpublished(): void
    {
        $user = User::factory()->create(['level' => 5]);
        $author = User::factory()->create(['level' => 1]);

        $unanswered = Question::factory()->published()->create(['user_id' => $author->id]);
        $unsolved = Question::factory()->published()->create(['user_id' => $author->id]);
        $solved = Question::factory()->published()->create(['user_id' => $author->id]);
        $unpublished = Question::factory()->unpublished()->create(['user_id' => $author->id]);

        Answer::factory()->create(['question_id' => $unsolved->id, 'is_correct' => false]);
        Answer::factory()->create(['question_id' => $solved->id, 'is_correct' => true]);

        // Higher-level viewers also see unpublished drafts (which have no answers).
        $request = Request::create('/api/questions', 'GET', ['filter' => 'unanswered']);
        $request->setUserResolver(fn () => $user);
        $unansweredIds = $this->service->filter($request)->pluck('id');
        $this->assertTrue($unansweredIds->contains($unanswered->id));
        $this->assertTrue($unansweredIds->contains($unpublished->id));
        $this->assertFalse($unansweredIds->contains($unsolved->id));
        $this->assertFalse($unansweredIds->contains($solved->id));

        $request = Request::create('/api/questions', 'GET', ['filter' => 'unsolved']);
        $request->setUserResolver(fn () => $user);
        $unsolvedIds = $this->service->filter($request)->pluck('id');
        $this->assertTrue($unsolvedIds->contains($unanswered->id));
        $this->assertTrue($unsolvedIds->contains($unsolved->id));
        $this->assertFalse($unsolvedIds->contains($solved->id));

        $request = Request::create('/api/questions', 'GET', ['filter' => 'solved']);
        $request->setUserResolver(fn () => $user);
        $this->assertEquals([$solved->id], $this->service->filter($request)->pluck('id')->all());

        $request = Request::create('/api/questions', 'GET', ['filter' => 'unpublished']);
        $request->setUserResolver(fn () => $user);
        $this->assertEquals([$unpublished->id], $this->service->filter($request)->pluck('id')->all());
    }

    public function test_sort_orders_by_requested_field(): void
    {
        $lowViews = Question::factory()->published()->create([
            'views' => 1,
            'created_at' => now()->subDays(2),
        ]);
        $highViews = Question::factory()->published()->create([
            'views' => 100,
            'created_at' => now()->subDay(),
        ]);

        $ids = $this->service->filter(Request::create('/api/questions', 'GET', [
            'sort' => 'views_count',
            'order' => 'desc',
        ]))->pluck('id')->all();

        $this->assertEquals([$highViews->id, $lowViews->id], $ids);
    }

    public function test_without_filters_orders_by_pin_status_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $olderPinned = Question::factory()->published()->create(['created_at' => now()->subDay()]);
        $newer = Question::factory()->published()->create(['created_at' => now()]);
        $user->pinnedQuestions()->attach($olderPinned->id, ['pinned_at' => now()]);

        $request = Request::create('/api/questions', 'GET');
        $request->setUserResolver(fn () => $user);

        $ids = $this->service->filter($request)->pluck('id')->all();

        $this->assertEquals([$olderPinned->id, $newer->id], $ids);
    }
}
