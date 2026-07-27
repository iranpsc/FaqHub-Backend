<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\User;
use App\Services\ActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     * Optimized: Uses a single query with subqueries instead of 4 separate queries
     */
    public function stats(): JsonResponse
    {
        try {
            // Single query with all counts as subqueries for better performance
            $stats = DB::selectOne('
                SELECT
                    (SELECT COUNT(*) FROM questions WHERE published = 1 AND published_at IS NOT NULL) as totalQuestions,
                    (SELECT COUNT(*) FROM answers WHERE published = 1) as totalAnswers,
                    (SELECT COUNT(*) FROM users) as totalUsers,
                    (SELECT COUNT(DISTINCT q.id) FROM questions q
                     INNER JOIN answers a ON q.id = a.question_id
                     WHERE a.is_correct = 1) as solvedQuestions
            ');

            return response()->json([
                'success' => true,
                'data' => [
                    'totalQuestions' => (int) $stats->totalQuestions,
                    'totalAnswers' => (int) $stats->totalAnswers,
                    'totalUsers' => (int) $stats->totalUsers,
                    'solvedQuestions' => (int) $stats->solvedQuestions,
                ],
                'message' => 'آمار با موفقیت دریافت شد',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت آمار',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get recommended questions (random selection)
     * Optimized: Uses efficient random selection with indexed columns
     */
    public function recommendedQuestions(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:50',
            ]);
            $limit = $validated['limit'] ?? 15;

            // Get random IDs first (more efficient than inRandomOrder on full table)
            // Use a subquery with RAND() on a limited set
            $randomIds = Question::where('published', true)
                ->select('id')
                ->inRandomOrder()
                ->limit($limit)
                ->pluck('id');

            // Then fetch the full data for those IDs
            $questions = Question::with(['user:id,name', 'tags:id,name', 'category:id,name'])
                ->withCount(['answers', 'votes'])
                ->whereIn('id', $randomIds)
                ->get()
                ->map(function ($question) {
                    return [
                        'id' => $question->id,
                        'title' => $question->title,
                        'slug' => $question->slug,
                        'created_at' => $question->created_at,
                        'answers_count' => $question->answers_count,
                        'votes_count' => $question->votes_count,
                        'views_count' => $question->views ?? 0,
                        'user' => [
                            'id' => $question->user?->id,
                            'name' => $question->user?->name,
                        ],
                        'category' => $question->category ? [
                            'id' => $question->category->id,
                            'name' => $question->category->name,
                        ] : null,
                        'tags' => $question->tags->map(fn ($tag) => [
                            'id' => $tag->id,
                            'name' => $tag->name,
                        ]),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $questions,
                'message' => 'سوالات پیشنهادی با موفقیت دریافت شد',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت سوالات پیشنهادی',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get popular questions based on views and period
     * Optimized: Uses selective eager loading and efficient sorting
     */
    public function popularQuestions(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:50',
                'period' => 'nullable|in:week,month,year,all',
            ]);
            $limit = $validated['limit'] ?? 15;
            $period = $validated['period'] ?? 'all'; // week, month, year, all

            $query = Question::select('questions.*')
                ->with(['user:id,name', 'tags:id,name', 'category:id,name'])
                ->withCount(['answers', 'votes'])
                ->where('published', true);

            // Apply date filter based on period
            switch ($period) {
                case 'week':
                    $query->where('questions.created_at', '>=', now()->subWeek());
                    break;
                case 'month':
                    $query->where('questions.created_at', '>=', now()->subMonth());
                    break;
                case 'year':
                    $query->where('questions.created_at', '>=', now()->subYear());
                    break;
                case 'all':
                default:
                    // No date filter for 'all'
                    break;
            }

            $questions = $query
                ->orderByDesc('views') // Primary sort by views (indexed)
                ->orderByDesc('votes_count') // Secondary sort by votes
                ->limit($limit)
                ->get()
                ->map(function ($question) {
                    return [
                        'id' => $question->id,
                        'title' => $question->title,
                        'slug' => $question->slug,
                        'created_at' => $question->created_at,
                        'answers_count' => $question->answers_count,
                        'votes_count' => $question->votes_count,
                        'views_count' => $question->views ?? 0,
                        'user' => [
                            'id' => $question->user?->id,
                            'name' => $question->user?->name,
                        ],
                        'category' => $question->category ? [
                            'id' => $question->category->id,
                            'name' => $question->category->name,
                        ] : null,
                        'tags' => $question->tags->map(fn ($tag) => [
                            'id' => $tag->id,
                            'name' => $tag->name,
                        ]),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $questions,
                'message' => 'سوالات محبوب با موفقیت دریافت شد',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت سوالات محبوب',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get most active users based on score and recent activity
     * Optimized: Uses subqueries instead of withCount for better performance
     */
    public function activeUsers(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:20',
            ]);
            $limit = $validated['limit'] ?? 5;

            // Use subqueries for counts - more efficient than withCount for ordering
            $users = User::select([
                'users.id',
                'users.name',
                'users.username',
                'users.image',
                'users.score',
            ])
                ->selectSub(
                    DB::table('questions')->selectRaw('COUNT(*)')
                        ->whereColumn('questions.user_id', 'users.id'),
                    'questions_count'
                )
                ->selectSub(
                    DB::table('answers')->selectRaw('COUNT(*)')
                        ->whereColumn('answers.user_id', 'users.id'),
                    'answers_count'
                )
                ->selectSub(
                    DB::table('comments')->selectRaw('COUNT(*)')
                        ->whereColumn('comments.user_id', 'users.id'),
                    'comments_count'
                )
                ->orderByDesc('score')
                ->limit($limit)
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'image' => $user->image,
                        'score' => $user->score ?? 0,
                        'questions_count' => (int) $user->questions_count,
                        'answers_count' => (int) $user->answers_count,
                        'comments_count' => (int) $user->comments_count,
                        'total_activity' => (int) $user->questions_count + (int) $user->answers_count + (int) $user->comments_count,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $users,
                'message' => 'کاربران فعال با موفقیت دریافت شد',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت کاربران فعال',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get activity feed showing activities with chronological pagination
     *
     * Query Parameters:
     * - limit (int): Number of activities to return (default: 30)
     * - offset (int): Number of activities to skip (default: 0)
     */
    public function activity(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
                'offset' => 'nullable|integer|min:0|max:5000',
            ]);
            $limit = $validated['limit'] ?? 30;
            $offset = $validated['offset'] ?? 0;

            $activityService = new ActivityService;
            $result = $activityService->getActivities($limit, $offset);

            return response()->json([
                'success' => true,
                'data' => $result['activities'],
                'grouped_data' => $result['grouped_activities'],
                'pagination' => $result['pagination'],
                'message' => 'فعالیت‌ها با موفقیت دریافت شد',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت فعالیت‌ها',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
