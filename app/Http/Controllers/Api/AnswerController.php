<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnswerRequest;
use App\Http\Requests\UpdateAnswerRequest;
use App\Http\Resources\AnswerResource;
use App\Models\Answer;
use App\Models\Question;
use App\Notifications\QuestionInteractionNotification;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class AnswerController extends Controller
{
    public function __construct(
        private ActivityLogger $activityLogger
    ) {
        $this->middleware('auth:sanctum')->except(['index']);
        $this->middleware('auth.optional')->only(['index']);

        $this->authorizeResource(Answer::class, 'answer', [
            'except' => ['index', 'store'],
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Question $question)
    {
        $user = $request->user();
        $sort = $request->get('sort'); // votes, comments, newest, oldest, correct

        $query = $question->answers()
            ->with('user', 'votes')
            ->visible($user);

        // Apply dynamic ordering / filtering
        switch ($sort) {
            case 'votes':
                // Need counts of up/down votes to compute score
                $query->withCount([
                    'upVotes as upvotes_count',
                    'downVotes as downvotes_count',
                ])->orderByRaw('(upvotes_count - downvotes_count) DESC')
                    ->orderByDesc('created_at');
                break;
            case 'comments':
                $query->withCount('comments')
                    ->orderByDesc('comments_count')
                    ->orderByDesc('created_at');
                break;
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'correct':
                // Only correct answers
                $query->where('is_correct', true)
                    ->orderByDesc('created_at');
                break;
            case 'newest':
            default:
                $query->orderByDesc('created_at');
                break;
        }

        $answers = $query->paginate(10);

        return AnswerResource::collection($answers);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAnswerRequest $request, Question $question)
    {
        $user = $request->user();

        $answer = $question->answers()->create([
            'user_id' => $user->id,
            'content' => $request->content,
            'published' => false, // All answers are unpublished by default
        ]);

        $question->update([
            'last_activity' => now(),
        ]);

        // Log answer creation
        $this->activityLogger->logAnswerCreated($answer, $user);

        return new AnswerResource($answer);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAnswerRequest $request, Answer $answer)
    {
        $answer->update($request->validated());

        $answer->question->update([
            'last_activity' => now(),
        ]);

        return new AnswerResource($answer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Answer $answer)
    {
        $answer->delete();

        return response()->noContent();
    }

    /**
     * Publish an answer.
     */
    public function publish(Request $request, Answer $answer)
    {
        $this->authorize('publish', $answer);

        $user = $request->user();

        $answer->update([
            'published' => true,
            'published_at' => now(),
            'published_by' => $user->id,
        ]);

        // Log publishing
        $this->activityLogger->logPublishing($answer, $user);

        // Award 3 points for publishing an answer
        $user->increment('score', 3);

        // Award 5 points for answering
        $answer->user->increment('score', 5);

        if (! is_null($answer->question->user)) {
            $answer->question->user->notify(new QuestionInteractionNotification($user, $answer->question, 'answer'));
        }

        return response()->json([
            'success' => true,
            'data' => new AnswerResource($answer),
            'message' => 'پاسخ با موفقیت منتشر شد',
        ]);
    }

    /**
     * Vote on the specified answer.
     */
    public function vote(Request $request, Answer $answer)
    {
        $request->validate([
            'type' => 'required|in:up,down',
        ]);

        $userId = $request->user()->id;
        $voteType = $request->type;

        // Enforce one-time voting per user per answer
        $existingVote = $answer->votes()
            ->where('user_id', $userId)
            ->first();

        if ($existingVote) {
            $answer->load('upVotes', 'downVotes');

            return response()->json([
                'success' => false,
                'message' => 'شما قبلا به این مورد رای داده‌اید',
                'upvotes' => $answer->upVotes->count(),
                'downvotes' => $answer->downVotes->count(),
                'user_vote' => $existingVote->type,
            ], 409);
        }

        $answer->votes()->create([
            'user_id' => $userId,
            'type' => $voteType,
            'last_voted_at' => now(),
        ]);

        // Log voting
        $this->activityLogger->logVote($answer, $request->user(), $voteType);

        if ($voteType == 'up') {
            $answer->user->increment('score', 10);
        } elseif ($voteType == 'down') {
            $answer->user->decrement('score', 2);
        }

        // Return updated vote counts and user vote status
        $answer->load('upVotes', 'downVotes');

        return response()->json([
            'upvotes' => $answer->upVotes->count(),
            'downvotes' => $answer->downVotes->count(),
            'user_vote' => $voteType,
        ]);
    }

    /**
     * Toggle answer correctness marking.
     */
    public function toggleCorrectness(Request $request, Answer $answer)
    {
        $user = $request->user();
        $currentCorrectness = $answer->is_correct; // boolean

        // Determine intended action based on front-end checkbox toggle behavior
        $action = $currentCorrectness ? 'markAsNormal' : 'markAsCorrect';

        $this->authorize('toggleCorrectness', [$answer, $action]);

        // Create mark record (audit) with resulting state
        $user->correctnessMarks()->create([
            'answer_id' => $answer->id,
            'is_correct' => ! $currentCorrectness,
        ]);

        // Update answer correctness
        $answer->update(['is_correct' => ! $currentCorrectness]);

        // Log correctness marking
        $this->activityLogger->logAnswerCorrectness($answer, $user, ! $currentCorrectness);

        // Score adjustments (only affect answer owner)
        if (! $currentCorrectness) { // now became correct
            $answer->user->increment('score', 10);
            $user->increment('score', 2); // marker reward
        } else { // now became normal
            $answer->user->decrement('score', 10);
            $user->increment('score', 2); // marker reward
        }

        return response()->json([
            'success' => true,
            'message' => $answer->is_correct ? 'پاسخ به صحیح تغییر داده شد' : 'پاسخ به عادی تغییر داده شد.',
            'is_correct' => $answer->is_correct,
            'data' => new AnswerResource($answer),
        ]);
    }
}
