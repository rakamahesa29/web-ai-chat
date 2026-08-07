<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomSynopsis extends Model
{
    protected $table = 'room_synopses';

    protected $fillable = [
        'room_id',
        'content',
        'summaries_consumed',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
