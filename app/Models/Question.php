<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

class Question extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'category_id',
        'user_id',
        'title',
        'slug',
        'content',
        'featured',
        'last_activity',
        'views',
        'published',
        'published_at',
        'published_by',
    ];

    /**
     * The attributes with default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'featured' => false,
        'published' => false,
        'last_activity' => null,
        'published_at' => null,
        'published_by' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'published' => 'boolean',
            'last_activity' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Get the category that owns the question.
     *
     * @return BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the user that owns the question.
     *
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user that published the question.
     *
     * @return BelongsTo
     */
    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Get the tags for the question.
     *
     * @return BelongsToMany
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Get the answers for the question.
     *
     * @return HasMany
     */
    public function answers()
    {
        return $this->hasMany(Answer::class);
    }

    /**
     * Get all of the question's comments.
     *
     * @return MorphMany
     */
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Get all of the question's votes.
     *
     * @return MorphMany
     */
    public function votes()
    {
        return $this->morphMany(Vote::class, 'votable');
    }

    /**
     * Get the upvotes for the question.
     *
     * @return MorphMany
     */
    public function upVotes()
    {
        return $this->morphMany(Vote::class, 'votable')->where('type', 'up');
    }

    /**
     * Get the downvotes for the question.
     *
     * @return MorphMany
     */
    public function downVotes()
    {
        return $this->morphMany(Vote::class, 'votable')->where('type', 'down');
    }

    /**
     * Get the users who have pinned this question.
     *
     * @return BelongsToMany
     */
    public function pinnedByUsers()
    {
        return $this->belongsToMany(User::class, 'user_pinned_questions')
            ->withTimestamps()
            ->withPivot('pinned_at');
    }

    /**
     * Get the users who have featured this question.
     *
     * @return BelongsToMany
     */
    public function featuredByUsers()
    {
        return $this->belongsToMany(User::class, 'user_featured_questions')
            ->withTimestamps()
            ->withPivot('featured_at');
    }

    /**
     * Get all of the question's verifications.
     *
     * @return MorphMany
     */
    public function verifications()
    {
        return $this->morphMany(Verification::class, 'verifiable');
    }

    /**
     * Check if the question is solved (has a correct or best answer).
     *
     * @return bool
     */
    public function isSolved()
    {
        return $this->answers()->where(function ($query) {
            $query->where('is_correct', true);
        })->exists();
    }

    /**
     * Scope a query to only include published questions.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopePublished($query)
    {
        return $query->where('questions.published', true)
            ->whereNotNull('questions.published_at');
    }

    /**
     * Scope a query to include visible questions based on user authentication and level.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeVisible($query, ?User $user)
    {
        // If user is not authenticated, only show published questions
        if (! $user) {
            return $query->published();
        }

        // For authenticated users, show:
        // 1. All published questions
        // 2. Their own unpublished questions
        // 3. Unpublished questions from users with lower level
        return $query->where(function ($q) use ($user) {
            $q->where('questions.published', true)
                ->orWhere('questions.user_id', $user->id)
                ->orWhere(function ($subQuery) use ($user) {
                    $subQuery->where('questions.published', false)
                        ->whereHas('user', function ($userQuery) use ($user) {
                            $userQuery->where('level', '<', $user->level);
                        });
                });
        });
    }

    /**
     * Scope a query to get questions with user's pin status.
     * Uses subqueries instead of JOINs for better performance.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeWithUserPinStatus($query, ?User $user)
    {
        if (! $user) {
            return $query->addSelect([
                DB::raw('0 as is_pinned_by_user'),
                DB::raw('NULL as pinned_at'),
            ]);
        }

        return $query->addSelect([
            'is_pinned_by_user' => DB::table('user_pinned_questions')
                ->selectRaw('CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END')
                ->whereColumn('user_pinned_questions.question_id', 'questions.id')
                ->where('user_pinned_questions.user_id', $user->id)
                ->limit(1),
            'pinned_at' => DB::table('user_pinned_questions')
                ->select('pinned_at')
                ->whereColumn('user_pinned_questions.question_id', 'questions.id')
                ->where('user_pinned_questions.user_id', $user->id)
                ->limit(1),
        ]);
    }

    /**
     * Scope a query to order questions with pinned ones first.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeOrderByPinStatus($query, ?User $user)
    {
        if (! $user) {
            return $query->latest('questions.created_at');
        }

        return $query->orderByRaw('is_pinned_by_user DESC')
            ->orderByRaw('COALESCE(pinned_at, \'1970-01-01\') DESC')
            ->orderBy('questions.created_at', 'desc');
    }

    /**
     * Scope a query to get questions with user's feature status.
     * Uses subqueries instead of JOINs for better performance.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeWithUserFeatureStatus($query, ?User $user)
    {
        if (! $user) {
            return $query->addSelect([
                DB::raw('0 as is_featured_by_user'),
                DB::raw('NULL as featured_at'),
            ]);
        }

        return $query->addSelect([
            'is_featured_by_user' => DB::table('user_featured_questions')
                ->selectRaw('CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END')
                ->whereColumn('user_featured_questions.question_id', 'questions.id')
                ->where('user_featured_questions.user_id', $user->id)
                ->limit(1),
            'featured_at' => DB::table('user_featured_questions')
                ->select('featured_at')
                ->whereColumn('user_featured_questions.question_id', 'questions.id')
                ->where('user_featured_questions.user_id', $user->id)
                ->limit(1),
        ]);
    }

    /**
     * Generate a unique slug from the title.
     */
    public static function generateSlug(string $title): string
    {
        // Replace spaces with hyphens and make lowercase
        $slug = strtolower(str_replace(' ', '-', trim($title)));
        $originalSlug = $slug;
        $counter = 1;

        // Ensure uniqueness
        while (static::where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
