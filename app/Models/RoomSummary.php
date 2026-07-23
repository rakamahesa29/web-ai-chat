<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomSummary extends Model
{
    protected $fillable = [
        'room_id',
        'content',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
