<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'email_verified_at',
        'mobile',
        'code',
        'image',
        'login_notification_enabled',
    ];

    /**
     * The attributes with default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'score' => 0,
        'level' => 1,
        'access_token' => '',
        'refresh_token' => '',
        'expires_in' => null,
        'token_type' => 'Bearer',
        'login_notification_enabled' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'expires_in' => 'datetime',
            'score' => 'integer',
            'login_notification_enabled' => 'boolean',
        ];
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'refresh_token',
    ];

    /**
     * The attributes that should be appended to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'image_url',
        'level_name',
    ];

    /**
     * Get the user's image URL.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('storage/'.$this->image) : null;
    }

    /**
     * Get the user's level name based on level number.
     */
    public function getLevelNameAttribute(): string
    {
        $levelNames = [
            1 => 'شهروند',
            2 => 'خبرنگار',
            3 => 'مشارکت کننده',
            4 => 'توسعه دهنده',
            5 => 'بازرس',
            6 => 'تاجر',
            7 => 'وکیل',
            8 => 'شورای شهر',
            9 => 'شهردار',
            10 => 'فرماندار',
            11 => 'وزیر',
            12 => 'قاضی',
            13 => 'قانون گذار',
        ];

        return $levelNames[$this->level] ?? 'نامشخص';
    }

    /**
     * Determine if the user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Get all questions created by the user.
     *
     * @return HasMany
     */
    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    /**
     * Get all questions published by the user.
     *
     * @return HasMany
     */
    public function publishedQuestions()
    {
        return $this->hasMany(Question::class, 'published_by');
    }

    /**
     * Get all answers created by the user.
     *
     * @return HasMany
     */
    public function answers()
    {
        return $this->hasMany(Answer::class);
    }

    /**
     * Get all answers published by the user.
     *
     * @return HasMany
     */
    public function publishedAnswers()
    {
        return $this->hasMany(Answer::class, 'published_by');
    }

    /**
     * Get all comments created by the user.
     *
     * @return HasMany
     */
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Get all comments published by the user.
     *
     * @return HasMany
     */
    public function publishedComments()
    {
        return $this->hasMany(Comment::class, 'published_by');
    }

    /**
     * Get all votes by the user.
     *
     * @return HasMany
     */
    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    /**
     * Get all verifications by the user.
     *
     * @return HasMany
     */
    public function verifications()
    {
        return $this->hasMany(Verification::class);
    }

    /**
     * Get all correctness marks made by this user.
     *
     * @return HasMany
     */
    public function correctnessMarks()
    {
        return $this->hasMany(AnswerCorrectnessMark::class, 'marker_user_id');
    }

    /**
     * Get the questions pinned by this user.
     *
     * @return BelongsToMany
     */
    public function pinnedQuestions()
    {
        return $this->belongsToMany(Question::class, 'user_pinned_questions')
            ->withTimestamps()
            ->withPivot('pinned_at')
            ->orderByPivot('pinned_at', 'desc');
    }

    /**
     * Get the questions featured by this user.
     *
     * @return HasMany
     */
    public function featuredQuestions()
    {
        return $this->hasMany(UserFeaturedQuestion::class)->where('type', 'featured');
    }

    /**
     * Get the questions unfeatured by this user.
     *
     * @return HasMany
     */
    public function unfeaturedQuestions()
    {
        return $this->hasMany(UserFeaturedQuestion::class)->where('type', 'unfeatured');
    }

    /**
     * Get the answers that user has as correct.
     *
     * @return HasMany
     */
    public function markedAsCorrectAnswers()
    {
        return $this->hasMany(AnswerCorrectnessMark::class, 'marker_user_id')->where('is_correct', true);
    }

    /**
     * Get the answers that user has marked as normal.
     *
     * @return HasMany
     */
    public function markedAsNormalAnswers()
    {
        return $this->hasMany(AnswerCorrectnessMark::class, 'marker_user_id')->where('is_correct', false);
    }
}
