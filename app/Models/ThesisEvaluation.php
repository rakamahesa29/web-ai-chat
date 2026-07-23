<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThesisEvaluation extends Model
{
    protected $fillable = [
        'room_id',
        'message_id',
        'evaluation_type',
        'overall_score',
        'chapter_scores',
        'strengths',
        'weaknesses',
        'recommendations',
        'raw_evaluation',
    ];

    protected $casts = [
        'chapter_scores' => 'array',
        'strengths'      => 'array',
        'weaknesses'     => 'array',
        'overall_score'  => 'decimal:1',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
