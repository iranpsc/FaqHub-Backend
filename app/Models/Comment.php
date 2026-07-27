<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'commentable_type',
        'commentable_id',
        'content',
        'published',
        'published_at',
        'published_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the comment.
     *
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user that published the comment.
     *
     * @return BelongsTo
     */
    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Get the parent commentable model.
     *
     * @return MorphTo
     */
    public function commentable()
    {
        return $this->morphTo();
    }

    /**
     * Get all of the comment's votes.
     */
    public function votes()
    {
        return $this->morphMany(Vote::class, 'votable');
    }

    /**
     * Get the upvotes for the comment.
     *
     * @return MorphMany
     */
    public function upVotes()
    {
        return $this->morphMany(Vote::class, 'votable')->where('type', 'up');
    }

    /**
     * Get the downvotes for the comment.
     *
     * @return MorphMany
     */
    public function downVotes()
    {
        return $this->morphMany(Vote::class, 'votable')->where('type', 'down');
    }

    /**
     * Scope a query to include only published comments.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopePublished($query)
    {
        return $query->where('comments.published', true)
            ->whereNotNull('comments.published_at');
    }

    /**
     * Scope a query to include visible comments based on user authentication and level.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeVisible($query, ?User $user)
    {
        // If user is not authenticated, only show published comments
        if (! $user) {
            return $query->published();
        }

        // For authenticated users, show:
        // 1. All published comments
        // 2. Their own unpublished comments
        // 3. Unpublished comments from users with lower level
        return $query->where(function ($q) use ($user) {
            $q->published()
                ->orWhere('user_id', $user->id)
                ->orWhere(function ($subQuery) use ($user) {
                    $subQuery->where('published', false)
                        ->whereHas('user', function ($userQuery) use ($user) {
                            $userQuery->where('level', '<', $user->level);
                        });
                });
        });
    }
}
