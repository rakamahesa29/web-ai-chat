<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'room_id',
        'sender_id',      
        'sender_type',     
        'content',
        'context_code',
        'search_context',
        'graph_indexed',
        'graph_tokens_saved',
        'tokens_used',
        'satisfaction_score',
        'category',
    ];

    protected $casts = [
        'graph_indexed' => 'boolean',
        'graph_tokens_saved' => 'integer',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function sender()
    {
        return $this->morphTo();
    }
}