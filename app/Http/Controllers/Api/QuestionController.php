<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Question;
use App\Models\Tag;
use App\Services\ActivityLogger;
use App\Services\QuestionFilterService;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    public function __construct(
        private QuestionFilterService $questionFilterService,
        private ActivityLogger $activityLogger
    ) {
        $this->middleware('auth.optional')->only(['search', 'index', 'show']);
        $this->middleware('auth:sanctum')->except(['search', 'index', 'show']);

        $this->authorizeResource(Question::class, 'question', [
            'except' => ['search', 'index', 'show'],
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $questions = $this->questionFilterService->getPaginatedQuestions($request, 10);

        return QuestionResource::collection($questions);
    }

    /**
     * Search questions by title
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:150',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $query = $validated['q'] ?? '';
        $limit = $validated['limit'] ?? 10;

        $questions = Question::with('user', 'category')
            ->withCount([
                'votes',
                'upVotes',
                'downVotes',
                'answers',
                'comments',
                'answers as unpublished_answers_count' => function ($query) {
                    $query->where('published', false);
                },
                'comments as unpublished_comments_count' => function ($query) {
                    $query->where('published', false);
                },
            ])
            ->published()
            ->where('title', 'like', '%'.$query.'%')
            ->orderByDesc('views')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => QuestionResource::collection($questions),
            'message' => 'جستجو با موفقیت انجام شد',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreQuestionRequest $request)
    {
        $user = $request->user();

        $question = Question::create([
            'category_id' => $request->category_id,
            'user_id' => $user->id,
            'title' => $request->title,
            'slug' => Question::generateSlug($request->title),
            'content' => $request->content,
        ]);

        $tagIds = $this->processTags($request->tags);
        $question->tags()->sync($tagIds);

        // Log question creation
        $this->activityLogger->logQuestionCreated($question, $user);

        if ($user->can('publish', $question)) {
            $question->update([
                'published' => true,
                'published_at' => now(),
                'published_by' => $user->id,
            ]);
            // Log publishing
            $this->activityLogger->logPublishing($question, $user);
        }

        $question->load('user', 'category', 'tags', 'upVotes', 'downVotes');

        return new QuestionResource($question);
    }

    /**
     * Display the specified resource.
     */
    public function show(Question $question)
    {
        $question->increment('views');

        $user = request()->user();

        $this->loadPinStatus($question, $user);
        $this->loadFeatureStatus($question, $user);
        $this->loadQuestionRelations($question, $user);

        return new QuestionResource($question);
    }

    private function loadPinStatus(Question $question, $user): void
    {
        if (! $user) {
            return;
        }

        $questionWithPinStatus = Question::where('questions.id', $question->id)
            ->withUserPinStatus($user)
            ->first();

        if ($questionWithPinStatus) {
            $question->is_pinned_by_user = $questionWithPinStatus->is_pinned_by_user;
            $question->pinned_at = $questionWithPinStatus->pinned_at;
        }
    }

    private function loadFeatureStatus(Question $question, $user): void
    {
        if (! $user) {
            return;
        }

        $questionWithFeatureStatus = Question::where('questions.id', $question->id)
            ->withUserFeatureStatus($user)
            ->first();

        if ($questionWithFeatureStatus) {
            $question->is_featured_by_user = $questionWithFeatureStatus->is_featured_by_user;
            $question->featured_at = $questionWithFeatureStatus->featured_at;
        }
    }

    private function loadQuestionRelations(Question $question, $user): void
    {
        $question->load([
            'user',
            'category',
            'tags',
            'upVotes',
            'downVotes',
            'comments',
        ])->loadCount([
            'votes',
            'upVotes',
            'downVotes',
            'answers',
            'comments',
            'answers as unpublished_answers_count' => function ($query) {
                $query->where('published', false);
            },
            'comments as unpublished_comments_count' => function ($query) {
                $query->where('published', false);
            },
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateQuestionRequest $request, Question $question)
    {
        $updateData = [
            'category_id' => $request->category_id,
            'title' => $request->title,
            'content' => $request->content,
        ];

        // If title has changed, generate a new slug
        if ($question->title !== $request->title) {
            $updateData['slug'] = Question::generateSlug($request->title);
        }

        $question->update($updateData);

        $tagIds = $this->processTags($request->tags);
        $question->tags()->sync($tagIds);

        $question->load('user', 'category', 'tags', 'upVotes', 'downVotes');

        return new QuestionResource($question);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Question $question)
    {
        $question->delete();

        return response()->noContent();
    }

    /**
     * Publish a question.
     */
    public function publish(Request $request, Question $question)
    {
        $this->authorize('publish', $question);

        $user = $request->user();

        $question->update([
            'published' => true,
            'published_at' => now(),
            'published_by' => $user->id,
        ]);

        // Log publishing
        $this->activityLogger->logPublishing($question, $user);

        // Award 2 points for publishing a question
        $user->increment('score', 2);

        $question->load('user', 'category', 'tags', 'upVotes', 'downVotes');

        return response()->json([
            'success' => true,
            'data' => new QuestionResource($question),
            'message' => 'سوال با موفقیت منتشر شد',
        ]);
    }

    /**
     * Vote on a question (upvote or downvote).
     */
    public function vote(Request $request, Question $question)
    {
        $request->validate([
            'type' => 'required|in:up,down',
        ]);

        $userId = $request->user()->id;
        $voteType = $request->type;

        // Enforce one-time voting per user per question
        $existingVote = $question->votes()
            ->where('user_id', $userId)
            ->first();

        if ($existingVote) {
            return response()->json([
                'success' => false,
                'message' => 'شما قبلا به این مورد رای داده‌اید',
                'upvotes' => $question->upVotes()->count(),
                'downvotes' => $question->downVotes()->count(),
                'user_vote' => $existingVote->type,
            ], 409);
        }

        $question->votes()->create([
            'user_id' => $userId,
            'type' => $voteType,
            'last_voted_at' => now(),
        ]);

        // Log voting
        $this->activityLogger->logVote($question, $request->user(), $voteType);

        if ($voteType == 'up') {
            $question->user?->increment('score', 10);
        } elseif ($voteType == 'down') {
            $question->user?->decrement('score', 2);
        }

        // Return updated vote counts and user vote status
        $question->load('upVotes', 'downVotes');

        return response()->json([
            'upvotes' => $question->upVotes->count(),
            'downvotes' => $question->downVotes->count(),
            'user_vote' => $voteType,
        ]);
    }

    /**
     * Pin a question for the authenticated user.
     */
    public function pin(Request $request, Question $question)
    {
        $user = $request->user();

        // Check if user has already pinned this question
        $isAlreadyPinned = $user->pinnedQuestions()->where('question_id', $question->id)->exists();

        if ($isAlreadyPinned) {
            return response()->json([
                'success' => false,
                'message' => 'شما قبلا این سوال را پین کرده‌اید',
            ], 409);
        }

        $user->pinnedQuestions()->attach($question->id, [
            'pinned_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'سوال با موفقیت پین شد',
            'is_pinned_by_user' => true,
            'pinned_at' => now()->toISOString(),
        ]);
    }

    /**
     * Unpin a question for the authenticated user.
     */
    public function unpin(Request $request, Question $question)
    {
        $user = $request->user();

        $user->pinnedQuestions()->detach($question->id);

        return response()->json([
            'success' => true,
            'message' => 'پین سوال برداشته شد',
            'is_pinned_by_user' => false,
            'pinned_at' => null,
        ]);
    }

    /**
     * Feature a question for the authenticated user.
     */
    public function feature(Request $request, Question $question)
    {
        $this->authorize('feature', $question);

        $user = $request->user();

        $user->featuredQuestions()->create([
            'question_id' => $question->id,
            'featured_at' => now(),
            'type' => 'featured',
        ]);

        // Update question's featured status
        $question->update(['featured' => true]);

        // Log featuring
        $this->activityLogger->logFeaturing($question, $user);

        return response()->json([
            'success' => true,
            'message' => 'سوال با موفقیت ویژه شد',
            'is_featured_by_user' => true,
            'featured_at' => now()->toISOString(),
        ]);
    }

    /**
     * Unfeature a question for the authenticated user.
     */
    public function unfeature(Request $request, Question $question)
    {
        $this->authorize('unfeature', $question);

        $user = $request->user();

        $question->update(['featured' => false]);

        $user->unfeaturedQuestions()->create([
            'question_id' => $question->id,
            'featured_at' => now(),
            'type' => 'unfeatured',
        ]);

        // Log unfeaturing
        $this->activityLogger->logUnfeaturing($question, $user);

        return response()->json([
            'success' => true,
            'message' => 'ویژگی سوال برداشته شد',
            'is_featured_by_user' => $user->featuredQuestions()->where('question_id', $question->id)->exists(),
            'featured_at' => null,
        ]);
    }

    /**
     * Process tags array to handle both existing and new tags
     *
     * @return array Array of tag IDs
     */
    private function processTags(array $tags): array
    {
        $tagIds = [];

        foreach ($tags as $tag) {
            if (isset($tag['id']) && is_numeric($tag['id'])) {
                // Existing tag
                $tagIds[] = $tag['id'];
            } elseif (isset($tag['name']) && ! empty($tag['name'])) {
                // New tag - create it if it doesn't exist
                $tagName = trim($tag['name']);
                $existingTag = Tag::where('name', $tagName)->first();

                if ($existingTag) {
                    $tagIds[] = $existingTag->id;
                } else {
                    $newTag = Tag::create(['name' => $tagName]);
                    $tagIds[] = $newTag->id;
                }
            }
        }

        return array_unique($tagIds);
    }
}
