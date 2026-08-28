<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFeaturedQuestion extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'question_id',
        'featured_at',
        'type',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'featured_at' => 'datetime',
        ];
    }

    /**
     * The user that featured the question.
     *
     * @return BelongsTo<User>
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The question that was featured.
     *
     * @return BelongsTo<Question>
     */
    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}
