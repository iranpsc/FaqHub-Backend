<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\Api\AuthorController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Requests\StoreCommentRequest;
use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Mockery;
use Tests\TestCase;

class ControllerCoverageEdgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_show_catch_returns_404_json(): void
    {
        $author = Mockery::mock(User::class)->makePartial();
        $author->shouldReceive('loadCount')->once()->andThrow(new \RuntimeException('boom'));

        $response = (new AuthorController)->show($author);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame('نویسنده یافت نشد.', $response->getData(true)['message']);
    }

    public function test_author_questions_catch_returns_500_json(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 1;

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('input')->with('per_page', 10)->andThrow(new \RuntimeException('fail'));

        $response = (new AuthorController)->questions($request, $user);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
    }

    public function test_comment_index_fallback_with_question_route_name(): void
    {
        $question = Question::factory()->published()->create();
        Comment::factory()->published()->create([
            'commentable_type' => Question::class,
            'commentable_id' => $question->id,
        ]);

        $request = Request::create('/api/questions/'.$question->id.'/comments', 'GET');
        $route = new Route(['GET'], 'api/questions/{question}/comments', []);
        $route->name('questions.comments.index');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);

        $controller = new CommentController(app(ActivityLogger::class));
        $response = $controller->index($request, (string) $question->id);

        $this->assertSame(200, $response->response()->getStatusCode());
    }

    public function test_comment_index_fallback_with_answer_route_name(): void
    {
        $answer = Answer::factory()->published()->create();
        Comment::factory()->published()->create([
            'commentable_type' => Answer::class,
            'commentable_id' => $answer->id,
        ]);

        $request = Request::create('/api/answers/'.$answer->id.'/comments', 'GET');
        $route = new Route(['GET'], 'api/answers/{answer}/comments', []);
        $route->name('answers.comments.index');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);

        $controller = new CommentController(app(ActivityLogger::class));
        $response = $controller->index($request, (string) $answer->id);

        $this->assertSame(200, $response->response()->getStatusCode());
    }

    public function test_comment_index_unknown_route_returns_empty_collection_resource(): void
    {
        $request = Request::create('/api/unknown/1/comments', 'GET');
        $route = new Route(['GET'], 'api/unknown/{id}/comments', []);
        $route->name('unknown.comments.index');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);

        $controller = new CommentController(app(ActivityLogger::class));
        $response = $controller->index($request, '1');

        $this->assertSame(200, $response->response()->getStatusCode());
    }

    public function test_comment_store_returns_400_when_parent_type_unknown(): void
    {
        $user = User::factory()->create(['level' => 3]);
        $this->actingAs($user);

        $request = StoreCommentRequest::create('/api/orphan/comments', 'POST', [
            'content' => 'hello',
        ]);
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $request->setUserResolver(fn () => $user);
        $request->validateResolved();

        $controller = new CommentController(app(ActivityLogger::class));
        $response = $controller->store($request, 'not-a-model');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('نوع والد مشخص نشده است', $response->getData(true)['message']);
    }
}
