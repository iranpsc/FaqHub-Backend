<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuestionFilterService
{
    /**
     * Filter questions based on request parameters
     */
    public function filter(Request $request): Builder
    {
        $query = Question::query();

        $user = $request->user();

        // Start with explicit select to avoid column conflicts from JOINs
        $query->select('questions.*');

        // Add is_solved as a subquery to avoid N+1
        $query->addSelect([
            'is_solved' => DB::table('answers')
                ->selectRaw('CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END')
                ->whereColumn('answers.question_id', 'questions.id')
                ->where('answers.is_correct', true)
                ->limit(1),
        ]);

        // Add user_vote as a subquery to avoid N+1 (only if user is authenticated)
        if ($user) {
            $query->addSelect([
                'user_vote' => DB::table('votes')
                    ->select('type')
                    ->whereColumn('votes.votable_id', 'questions.id')
                    ->where('votes.votable_type', Question::class)
                    ->where('votes.user_id', $user->id)
                    ->limit(1),
            ]);
        } else {
            $query->addSelect([DB::raw('NULL as user_vote')]);
        }

        // Apply base query with relations and counts
        $query->with(['user', 'category', 'tags'])
            ->withCount([
                'votes',
                'answers',
                'comments',
                'answers as unpublished_answers_count' => function ($query) {
                    $query->where('published', false);
                },
                'comments as unpublished_comments_count' => function ($query) {
                    $query->where('published', false);
                },
            ])
            ->visible($user)
            ->withUserPinStatus($user)
            ->withUserFeatureStatus($user);

        // Apply category filter
        if ($request->filled('category_id')) {
            $query->where('questions.category_id', $request->category_id);
        }

        // Apply tags filter (OR logic - questions must have ANY of the selected tags)
        if ($request->filled('tags')) {
            $tags = explode(',', $request->tags);
            $query->whereHas('tags', function ($q) use ($tags) {
                $q->whereIn('tags.id', $tags);
            });
        }

        if ($this->hasActiveFilters($request)) {
            $this->applySortingFilters($request, $query, $user);
        } else {
            $query->orderByPinStatus($user);
        }

        return $query;
    }

    /**
     * Apply sorting filters based on request parameters
     *
     * @param  mixed  $user
     */
    private function applySortingFilters(Request $request, Builder $query, $user): void
    {
        // Handle filter-based parameters (unanswered, unsolved)
        if ($request->filled('filter')) {
            switch ($request->filter) {
                case 'unanswered':
                    $query->whereDoesntHave('answers')
                        ->orderBy('created_at', 'desc');

                    return;
                case 'unsolved':
                    // Assuming unsolved means no accepted answer
                    $query->whereDoesntHave('answers', function ($q) {
                        $q->where('is_correct', true);
                    })->orderBy('created_at', 'desc');

                    return;
                case 'solved':
                    // Assuming solved means at least one accepted answer
                    $query->whereHas('answers', function ($q) {
                        $q->where('is_correct', true);
                    })->orderBy('created_at', 'desc');

                    return;
                case 'unpublished':
                    $query->where('questions.published', false)
                        ->orderBy('created_at', 'desc');

                    return;
            }
        }

        // Handle sort and order parameters
        if ($request->filled('sort') && $request->filled('order')) {
            $sortField = $request->sort;
            $sortOrder = strtolower($request->order) === 'asc' ? 'asc' : 'desc';

            switch ($sortField) {
                case 'created_at':
                    $query->orderBy('created_at', $sortOrder);
                    break;
                case 'votes':
                    $query->orderBy('votes_count', $sortOrder);
                    break;
                case 'answers_count':
                    $query->orderBy('answers_count', $sortOrder);
                    break;
                case 'views_count':
                    $query->orderBy('views', $sortOrder);
                    break;
                default:
                    $query->orderBy('created_at', 'desc');
                    break;
            }

            return;
        }
    }

    /**
     * Check if the request has any active filtering parameters
     */
    private function hasActiveFilters(Request $request): bool
    {
        return $request->filled('category_id') ||
               $request->filled('tags') ||
               $request->filled('filter') ||
               $request->filled('sort') ||
               $request->has('newest') ||
               $request->has('oldest') ||
               $request->has('most_votes') ||
               $request->has('most_answers') ||
               $request->has('most_views') ||
               $request->has('unanswered') ||
               $request->has('unsolved') ||
               $request->has('unpublished');
    }

    /**
     * Get paginated questions with filters applied
     *
     * @return LengthAwarePaginator
     */
    public function getPaginatedQuestions(Request $request, int $perPage = 10)
    {
        $query = $this->filter($request);

        return $query->paginate($perPage);
    }
}
