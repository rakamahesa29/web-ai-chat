<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrainChunk extends Model
{
    protected $fillable = [
        'brain_id',
        'chunk_index',
        'content',
        'embedding',
        'token_count',
        'chunk_type',
    ];

    protected $casts = [
        'embedding' => 'array',
        'token_count' => 'integer',
        'chunk_index' => 'integer',
    ];

    public function brain(): BelongsTo
    {
        return $this->belongsTo(Brain::class);
    }
}
