<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brain extends Model
{
    protected $fillable = [
        'title',
        'content',
        'tag',
        'type',
        'file_path',
        'file_hash',
        'embedding',
        'is_indexed',
        'indexed_at',
    ];

    protected $casts = [
        'tag' => 'array',
        'embedding' => 'array',
        'is_indexed' => 'boolean',
        'indexed_at' => 'datetime',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(BrainChunk::class)->orderBy('chunk_index');
    }
}
