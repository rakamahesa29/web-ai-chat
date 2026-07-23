<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeEdge extends Model
{
    const TYPE_EXTRACTED = 'EXTRACTED';
    const TYPE_INFERRED = 'INFERRED';
    const TYPE_AMBIGUOUS = 'AMBIGUOUS';

    protected $fillable = [
        'room_id',
        'source_node_id',
        'target_node_id',
        'edge_type',
        'relation',
        'weight',
        'context',
    ];

    protected $casts = [
        'weight' => 'float',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(KnowledgeNode::class, 'source_node_id');
    }

    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(KnowledgeNode::class, 'target_node_id');
    }

    /**
     * Scope: Filter by room.
     */
    public function scopeForRoom($query, int $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    /**
     * Scope: Filter by edge type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('edge_type', $type);
    }

    /**
     * Scope: Get high-weight edges.
     */
    public function scopeStrongConnections($query, float $minWeight = 2.0)
    {
        return $query->where('weight', '>=', $minWeight);
    }

    /**
     * Increment edge weight.
     */
    public function strengthen(float $amount = 1.0): bool
    {
        $this->weight += $amount;
        return $this->save();
    }
}
