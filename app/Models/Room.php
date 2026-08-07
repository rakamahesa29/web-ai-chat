<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Room extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',   
        'title',
        'category',
        'persona',  
        'model',
        'system_prompt',
        'memory_config',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'memory_config' => 'array', 
    ];

    /**
     * Get the messages for the room.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get the summaries for the room.
     */
    public function summaries(): HasMany
    {
        return $this->hasMany(RoomSummary::class);
    }

    /**
     * Get the thesis evaluations for the room.
     */
    public function thesisEvaluations(): HasMany
    {
        return $this->hasMany(ThesisEvaluation::class);
    }

    /**
     * Get the skills attached to this room.
     */
    public function skills(): HasMany
    {
        return $this->hasMany(RoomSkill::class);
    }

    /**
     * Get the synopses (meta-summaries) for the room.
     */
    public function synopses(): HasMany
    {
        return $this->hasMany(RoomSynopsis::class);
    }

    /**
     * Get the user that owns the room.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}