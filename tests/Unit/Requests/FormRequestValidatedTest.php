<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\StoreAnswerRequest;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Models\Category;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class FormRequestValidatedTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(Request $request, ?User $user = null): void
    {
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));

        if ($user) {
            $request->setUserResolver(fn () => $user);
        }
    }

    public function test_store_answer_validated_sanitizes_content(): void
    {
        $request = StoreAnswerRequest::create('/api/answers', 'POST', [
            'content' => '<p>Safe</p><script>alert(1)</script>',
        ]);
        $this->prepare($request);
        $request->validateResolved();

        $data = $request->validated();

        $this->assertArrayHasKey('content', $data);
        $this->assertStringContainsString('Safe', $data['content']);
        $this->assertStringNotContainsString('<script', $data['content']);
    }

    public function test_store_answer_validated_with_key_skips_sanitize_branch(): void
    {
        $request = StoreAnswerRequest::create('/api/answers', 'POST', [
            'content' => '<p>Hello</p>',
        ]);
        $this->prepare($request);
        $request->validateResolved();

        $this->assertSame('<p>Hello</p>', $request->validated('content'));
    }

    public function test_store_comment_validated_escapes_content(): void
    {
        $request = StoreCommentRequest::create('/api/comments', 'POST', [
            'content' => '<b>Hi & Bye</b>',
        ]);
        $this->prepare($request);
        $request->validateResolved();

        $data = $request->validated();

        $this->assertSame('Hi &amp; Bye', $data['content']);
    }

    public function test_store_question_validated_sanitizes_content(): void
    {
        $user = User::factory()->create(['level' => 2]);
        $category = Category::factory()->create();
        $tag = Tag::factory()->create();

        $request = StoreQuestionRequest::create('/api/questions', 'POST', [
            'category_id' => $category->id,
            'title' => '<b>Title</b>',
            'content' => '<p>Body</p><script>x</script>',
            'tags' => [
                ['id' => $tag->id],
                ['name' => '<i>NewTag</i>'],
            ],
        ]);
        $this->prepare($request, $user);
        $request->validateResolved();

        $data = $request->validated();

        $this->assertStringContainsString('Body', $data['content']);
        $this->assertStringNotContainsString('<script', $data['content']);
    }

    public function test_update_question_validated_sanitizes_content(): void
    {
        $user = User::factory()->create(['level' => 2]);
        $category = Category::factory()->create();
        $question = Question::factory()->unpublished()->create(['user_id' => $user->id]);
        $tag = Tag::factory()->create();

        $request = UpdateQuestionRequest::create('/api/questions/'.$question->id, 'PUT', [
            'category_id' => $category->id,
            'title' => 'Updated',
            'content' => '<p>Updated</p><script>bad</script>',
            'tags' => [
                ['id' => $tag->id],
                ['name' => '<em>Tag</em>'],
            ],
        ]);
        $this->prepare($request, $user);

        $route = new Route(['PUT'], '/api/questions/{question}', []);
        $route->bind($request);
        $route->setParameter('question', $question);
        $request->setRouteResolver(fn () => $route);

        $request->validateResolved();

        $data = $request->validated();

        $this->assertStringContainsString('Updated', $data['content']);
        $this->assertStringNotContainsString('<script', $data['content']);
    }
}
