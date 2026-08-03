<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Answer extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'question_id',
        'user_id',
        'content',
        'published',
        'published_at',
        'published_by',
        'is_correct',
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
            'is_correct' => 'boolean',
        ];
    }

    /**
     * Get the question that owns the answer.
     *
     * @return BelongsTo
     */
    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Get the user that owns the answer.
     *
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user that published the answer.
     *
     * @return BelongsTo
     */
    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Get all of the answer's comments.
     *
     * @return MorphMany
     */
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Get all of the answer's votes.
     *
     * @return MorphMany
     */
    public function votes()
    {
        return $this->morphMany(Vote::class, 'votable');
    }

    /**
     * Get the upvotes for the answer.
     *
     * @return MorphMany
     */
    public function upVotes()
    {
        return $this->morphMany(Vote::class, 'votable')->where('type', 'up');
    }

    /**
     * Get the downvotes for the answer.
     *
     * @return MorphMany
     */
    public function downVotes()
    {
        return $this->morphMany(Vote::class, 'votable')->where('type', 'down');
    }

    /**
     * Get all of the answer's verifications.
     *
     * @return MorphMany
     */
    public function verifications()
    {
        return $this->morphMany(Verification::class, 'verifiable');
    }

    /**
     * Get all correctness marks for this answer.
     *
     * @return HasMany
     */
    public function correctnessMarks()
    {
        return $this->hasMany(AnswerCorrectnessMark::class);
    }

    /**
     * Scope a query to only include published answers.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopePublished($query)
    {
        return $query->where('answers.published', true)
            ->whereNotNull('answers.published_at');
    }

    /**
     * Scope a query to include visible answers based on user authentication and level.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeVisible($query, ?User $user)
    {
        // If user is not authenticated, only show published answers
        if (! $user) {
            return $query->published();
        }

        // For authenticated users, show:
        // 1. All published answers
        // 2. Their own unpublished answers
        // 3. Unpublished answers from users with lower level
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
