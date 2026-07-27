<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use App\Notifications\QuestionInteractionNotification;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(
        private ActivityLogger $activityLogger
    ) {
        $this->middleware('auth:sanctum')->except(['index', 'show']);
        $this->middleware('auth.optional')->only(['index', 'show']);

        $this->authorizeResource(Comment::class, 'comment', [
            'except' => ['index'],
        ]);
    }

    public function index(Request $request, $parent, $parentId = null)
    {
        $user = $request->user();

        // Handle both question comments and answer comments
        if ($parent instanceof Question) {
            $comments = $parent->comments()->visible($user)->with('user', 'votes')->latest()->paginate(10);
        } elseif ($parent instanceof Answer) {
            $comments = $parent->comments()->visible($user)->with('user', 'votes')->latest()->paginate(10);
        } else {
            // Fallback: if parent is not a model instance, assume it's an ID and determine type from route
            $routeName = request()->route()->getName();
            if ($routeName === 'questions.comments.index') {
                $question = Question::findOrFail($parent);
                $comments = $question->comments()->visible($user)->with('user', 'votes')->latest()->paginate(10);
            } elseif ($routeName === 'answers.comments.index') {
                $answer = Answer::findOrFail($parent);
                $comments = $answer->comments()->visible($user)->with('user', 'votes')->latest()->paginate(10);
            } else {
                // If we can't determine the parent type, return empty collection
                $comments = collect();
            }
        }

        return CommentResource::collection($comments);
    }

    public function store(StoreCommentRequest $request, $parent)
    {
        $user = $request->user();

        $commentData = [
            'user_id' => $user->id,
            'content' => $request->content,
        ];

        $question = null;
        $comment = null;

        // Handle both question comments and answer comments
        // First, try to get the parent from route model binding
        $routeQuestion = request()->route('question');
        $routeAnswer = request()->route('answer');

        if ($routeQuestion instanceof Question) {
            // Question comment route with model binding
            $comment = $routeQuestion->comments()->create($commentData);
            $question = $routeQuestion;
        } elseif ($routeAnswer instanceof Answer) {
            // Answer comment route with model binding
            $comment = $routeAnswer->comments()->create($commentData);
            $question = $routeAnswer->question;
        } elseif ($parent instanceof Question) {
            // Fallback: $parent is a Question instance
            $comment = $parent->comments()->create($commentData);
            $question = $parent;
        } elseif ($parent instanceof Answer) {
            // Fallback: $parent is an Answer instance
            $comment = $parent->comments()->create($commentData);
            $question = $parent->question;
        } else {
            // Route model binding didn't work - determine parent type from route parameters
            $routePath = request()->path();

            if (str_contains($routePath, '/questions/') && str_contains($routePath, '/comments')) {
                // Question comment route - $parent should be the question ID
                $question = Question::findOrFail($parent);
                $comment = $question->comments()->create($commentData);
            } elseif (str_contains($routePath, '/answers/') && str_contains($routePath, '/comments')) {
                // Answer comment route - $parent should be the answer ID
                $answer = Answer::findOrFail($parent);
                $comment = $answer->comments()->create($commentData);
                $question = $answer->question;
            } else {
                // If we can't determine the parent type, return an error
                return response()->json([
                    'message' => 'نوع والد مشخص نشده است',
                ], 400);
            }
        }

        // Log comment creation
        if ($comment) {
            $this->activityLogger->logCommentCreated($comment, $user);

            if ($user->can('publish', $comment)) {
                $comment->update([
                    'published' => true,
                    'published_at' => now(),
                    'published_by' => $user->id,
                ]);
                // Log publishing
                $this->activityLogger->logPublishing($comment, $user);
            }
        }

        return response()->json([
            'data' => new CommentResource($comment->load('user')),
            'message' => 'نظر با موفقیت اضافه شد',
        ], 201);
    }

    public function update(UpdateCommentRequest $request, Comment $comment)
    {
        $comment->update($request->validated());

        return new CommentResource($comment);
    }

    public function destroy(Comment $comment)
    {
        $comment->delete();

        return response()->noContent();
    }

    /**
     * Publish a comment.
     */
    public function publish(Request $request, Comment $comment)
    {
        $this->authorize('publish', $comment);

        $user = $request->user();

        $comment->update([
            'published' => true,
            'published_at' => now(),
            'published_by' => $user->id,
        ]);

        // Log publishing
        $this->activityLogger->logPublishing($comment, $user);

        // Award 2 points for publishing a comment
        $user->increment('score', 2);

        // Add 2 score scores for commenting
        $comment->user->increment('score', 2);

        if (! is_null($comment->commentable->user)) {
            $comment->commentable->user->notify(new QuestionInteractionNotification($user, $comment->commentable, 'comment'));
        }

        return response()->json([
            'success' => true,
            'data' => new CommentResource($comment),
            'message' => 'نظر با موفقیت منتشر شد',
        ]);
    }

    public function vote(Request $request, Comment $comment)
    {
        $request->validate([
            'type' => 'required|in:up,down',
        ]);

        $userId = $request->user()->id;
        $voteType = $request->type;

        // Enforce one-time voting per user per comment
        $existingVote = $comment->votes()
            ->where('user_id', $userId)
            ->first();

        if ($existingVote) {
            $comment->load('upVotes', 'downVotes');

            return response()->json([
                'success' => false,
                'message' => 'شما قبلا به این مورد رای داده‌اید',
                'upvotes' => $comment->upVotes->count(),
                'downvotes' => $comment->downVotes->count(),
                'user_vote' => $existingVote->type,
            ], 409);
        }

        $comment->votes()->create([
            'user_id' => $userId,
            'type' => $voteType,
            'last_voted_at' => now(),
        ]);

        // Log voting
        $this->activityLogger->logVote($comment, $request->user(), $voteType);

        // Return updated vote counts and user vote status
        $comment->load('upVotes', 'downVotes');

        return response()->json([
            'upvotes' => $comment->upVotes->count(),
            'downvotes' => $comment->downVotes->count(),
            'user_vote' => $voteType,
        ]);
    }
}
