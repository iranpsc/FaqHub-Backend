<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\Question;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class ActivityService
{
    /**
     * Get activities with chronological pagination
     *
     * Returns activities from activity_log table, paginated by limit/offset.
     * Activities are grouped by Persian month for frontend display.
     *
     * @param  int  $limit  Number of activities to return (default: 30)
     * @param  int  $offset  Number of activities to skip (default: 0)
     */
    public function getActivities(int $limit = 30, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit)); // Clamp between 1 and 100
        $offset = max(0, $offset);

        // Query activity_log table, ordered by created_at descending
        // Eager load causer (user) - subject relationships are polymorphic and handled in transform methods
        $logs = Activity::with(['causer:id,name,image', 'subject'])
            ->orderByDesc('created_at')
            ->skip($offset)
            ->take($limit)
            ->get();

        $activities = $logs->map(function ($log) {
            return $this->transformLogToActivity($log);
        })->filter(function ($activity) {
            // Filter out activities with invalid data
            return $activity !== null;
        })->values();

        // Group activities by Persian month
        $groupedActivities = [];
        foreach ($activities as $activity) {
            $month = $activity['month'] ?? 'نامشخص';
            if (! isset($groupedActivities[$month])) {
                $groupedActivities[$month] = [];
            }
            $groupedActivities[$month][] = $activity;
        }

        // Check if there are more activities
        $hasMore = Activity::count() > ($offset + $limit);

        return [
            'activities' => $activities,
            'grouped_activities' => $groupedActivities,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'next_offset' => $offset + $limit,
                'has_more' => $hasMore,
                'total' => Activity::count(),
            ],
        ];
    }

    /**
     * Transform activity log entry to frontend-compatible format
     */
    private function transformLogToActivity(Activity $log): ?array
    {
        $description = $log->description;
        $causer = $log->causer;
        $subject = $log->subject;
        // Convert properties to array if it's a Collection
        $properties = $log->properties ?? [];
        if ($properties instanceof Collection) {
            $properties = $properties->toArray();
        } elseif (! is_array($properties)) {
            $properties = [];
        }

        $userName = $causer?->name ?? 'کاربر ناشناس';
        $userId = $causer?->id ?? null;
        $userImage = $causer?->image_url ?? null;

        $baseActivity = [
            'id' => 'activity_'.$log->id,
            'user_name' => $userName,
            'user_id' => $userId,
            'user_image' => $userImage,
            'created_at' => $log->created_at->toIso8601String(),
            'month' => $this->getPersianMonth($log->created_at),
        ];

        // Map description to activity type and generate appropriate data
        return match ($description) {
            'created_question' => $this->transformQuestionCreated($log, $baseActivity, $subject, $properties),
            'created_answer' => $this->transformAnswerCreated($log, $baseActivity, $subject, $properties),
            'created_comment' => $this->transformCommentCreated($log, $baseActivity, $subject, $properties),
            'voted' => $this->transformVote($log, $baseActivity, $subject, $properties),
            'published_question', 'published_answer', 'published_comment' => $this->transformPublishing($log, $baseActivity, $subject, $properties, $description),
            'featured_question' => $this->transformFeaturing($log, $baseActivity, $subject, $properties, true),
            'unfeatured_question' => $this->transformFeaturing($log, $baseActivity, $subject, $properties, false),
            'marked_correct' => $this->transformMarkedCorrect($log, $baseActivity, $subject, $properties),
            default => null,
        };
    }

    /**
     * Transform question created activity
     */
    private function transformQuestionCreated(Activity $log, array $base, $subject, array $properties): ?array
    {
        // Use properties if subject is deleted
        $title = $properties['title'] ?? $subject?->title ?? 'سوال حذف شده';
        $slug = $properties['slug'] ?? $subject?->slug ?? null;
        $questionId = $subject?->id ?? null;

        // Try to get category name from subject if available
        $categoryName = null;
        if ($subject instanceof Question && $subject->relationLoaded('category')) {
            $categoryName = $subject->category?->name;
        }

        return array_merge($base, [
            'type' => 'question',
            'title' => $title,
            'slug' => $slug,
            'question_id' => $questionId,
            'category_name' => $categoryName,
            'description' => "کاربر '{$base['user_name']}' سوال جدیدی با عنوان '{$title}' پرسید",
            'url' => $slug ? "/questions/{$slug}" : null,
        ]);
    }

    /**
     * Transform answer created activity
     */
    private function transformAnswerCreated(Activity $log, array $base, $subject, array $properties): ?array
    {
        // Use properties if subject is deleted
        $questionTitle = $properties['question_title'] ?? $subject?->question?->title ?? 'سوال حذف شده';
        $questionSlug = $properties['question_slug'] ?? $subject?->question?->slug ?? null;
        $questionId = $properties['question_id'] ?? $subject?->question_id ?? null;

        return array_merge($base, [
            'type' => 'answer',
            'title' => $questionTitle,
            'question_id' => $questionId,
            'description' => "کاربر '{$base['user_name']}' به سوال '{$questionTitle}' پاسخ داد",
            'url' => $questionSlug ? "/questions/{$questionSlug}" : null,
        ]);
    }

    /**
     * Transform comment created activity
     */
    private function transformCommentCreated(Activity $log, array $base, $subject, array $properties): ?array
    {
        if (! $subject instanceof Comment) {
            return null;
        }

        $questionTitle = $properties['question_title'] ?? 'محتوای حذف شده';
        $questionSlug = $properties['question_slug'] ?? null;

        return array_merge($base, [
            'type' => 'comment',
            'title' => $questionTitle,
            'question_slug' => $questionSlug,
            'description' => "کاربر '{$base['user_name']}' نظری در '{$questionTitle}' ثبت کرد",
            'url' => $questionSlug ? "/questions/{$questionSlug}" : null,
        ]);
    }

    /**
     * Transform vote activity
     */
    private function transformVote(Activity $log, array $base, $subject, array $properties): ?array
    {
        $voteType = $properties['vote_type'] ?? 'up';
        $questionTitle = $properties['question_title'] ?? 'محتوای حذف شده';
        $questionSlug = $properties['question_slug'] ?? null;

        // Determine votable type
        $votableType = $properties['votable_type'] ?? null;
        if (! $votableType && $subject) {
            $votableType = get_class($subject);
        }

        $typeLabel = match ($votableType) {
            Question::class => 'سوال',
            Answer::class => 'پاسخ',
            Comment::class => 'نظر',
            default => 'محتوا',
        };

        $voteLabel = $voteType === 'up' ? 'رای مثبت' : 'رای منفی';

        return array_merge($base, [
            'type' => 'vote',
            'title' => $questionTitle,
            'vote_type' => $voteType,
            'description' => "کاربر '{$base['user_name']}' به {$typeLabel} '{$questionTitle}' {$voteLabel} داد",
            'url' => $questionSlug ? "/questions/{$questionSlug}" : null,
        ]);
    }

    /**
     * Transform publishing activity
     */
    private function transformPublishing(Activity $log, array $base, $subject, array $properties, string $description): ?array
    {
        $questionTitle = $properties['question_title'] ?? 'محتوای حذف شده';
        $questionSlug = $properties['question_slug'] ?? null;

        $typeLabel = match ($description) {
            'published_question' => 'سوال',
            'published_answer' => 'پاسخ',
            'published_comment' => 'نظر',
            default => 'محتوا',
        };

        return array_merge($base, [
            'type' => 'publish',
            'title' => $questionTitle,
            'description' => "کاربر '{$base['user_name']}' {$typeLabel} '{$questionTitle}' را منتشر کرد",
            'url' => $questionSlug ? "/questions/{$questionSlug}" : null,
        ]);
    }

    /**
     * Transform featuring activity
     */
    private function transformFeaturing(Activity $log, array $base, $subject, array $properties, bool $isFeatured): ?array
    {
        // Use properties if subject is deleted
        $title = $properties['title'] ?? $subject?->title ?? 'سوال حذف شده';
        $slug = $properties['slug'] ?? $subject?->slug ?? null;
        $action = $isFeatured ? 'ویژه کرد' : 'ویژگی را از';

        return array_merge($base, [
            'type' => 'feature',
            'title' => $title,
            'is_featured' => $isFeatured,
            'description' => "کاربر '{$base['user_name']}' سوال '{$title}' را {$action}",
            'url' => $slug ? "/questions/{$slug}" : null,
        ]);
    }

    /**
     * Transform marked correct activity
     */
    private function transformMarkedCorrect(Activity $log, array $base, $subject, array $properties): ?array
    {
        // Use properties if subject is deleted
        $questionTitle = $properties['question_title'] ?? $subject?->question?->title ?? 'سوال حذف شده';
        $questionSlug = $properties['question_slug'] ?? $subject?->question?->slug ?? null;
        $isCorrect = $properties['is_correct'] ?? $subject?->is_correct ?? false;

        return array_merge($base, [
            'type' => 'answer',
            'title' => $questionTitle,
            'is_correct' => $isCorrect,
            'description' => "کاربر '{$base['user_name']}' پاسخ به سوال '{$questionTitle}' را ".($isCorrect ? 'صحیح' : 'عادی').' علامت زد',
            'url' => $questionSlug ? "/questions/{$questionSlug}" : null,
        ]);
    }

    /**
     * Get Persian month name with year from date
     *
     * @param  Carbon  $date
     */
    private function getPersianMonth($date): string
    {
        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return jdate($carbon)->format('F Y');
    }

    /**
     * Check if there are more activities available beyond the given offset
     */
    public function hasMoreActivities(int $offset): bool
    {
        return Activity::count() > $offset;
    }

    /**
     * Get activity statistics for a period
     *
     * Returns counts of activities by type within the time period.
     */
    public function getActivityStats(int $months = 3, int $offset = 0): array
    {
        $endDate = Carbon::now()->subMonths($offset);
        $startDate = $endDate->copy()->subMonths($months);

        $stats = [
            'total_questions' => Activity::where('description', 'created_question')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count(),
            'total_answers' => Activity::where('description', 'created_answer')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count(),
            'total_comments' => Activity::where('description', 'created_comment')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count(),
            'total_votes' => Activity::where('description', 'voted')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count(),
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'months' => $months,
                'offset' => $offset,
            ],
        ];

        return $stats;
    }
}
