<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnswerCorrectnessMark extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'answer_id',
        'marker_user_id',
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
            'is_correct' => 'boolean',
        ];
    }

    /**
     * Get the answer that this mark belongs to.
     *
     * @return BelongsTo
     */
    public function answer()
    {
        return $this->belongsTo(Answer::class);
    }

    /**
     * Get the user who marked this answer.
     *
     * @return BelongsTo
     */
    public function marker()
    {
        return $this->belongsTo(User::class, 'marker_user_id');
    }
}
