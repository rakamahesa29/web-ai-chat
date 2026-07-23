<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KnowledgeNode extends Model
{
    const TYPE_TOPIC = 'topic';
    const TYPE_CONCEPT = 'concept';
    const TYPE_PERSON = 'person';
    const TYPE_ACTION = 'action';
    const TYPE_ENTITY = 'entity';

    protected $fillable = [
        'room_id',
        'message_id',
        'content',
        'node_type',
        'embedding',
        'frequency',
        'last_seen_at',
    ];

    protected $casts = [
        'embedding' => 'array',
        'frequency' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(KnowledgeEdge::class, 'source_node_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(KnowledgeEdge::class, 'target_node_id');
    }

    /**
     * Get related nodes through outgoing edges.
     */
    public function relatedNodes(): BelongsToMany
    {
        return $this->belongsToMany(
            KnowledgeNode::class,
            'knowledge_edges',
            'source_node_id',
            'target_node_id'
        )->withPivot(['edge_type', 'relation', 'weight', 'context']);
    }

    /**
     * Get nodes that reference this node (inverse relationship).
     */
    public function referencedByNodes(): BelongsToMany
    {
        return $this->belongsToMany(
            KnowledgeNode::class,
            'knowledge_edges',
            'target_node_id',
            'source_node_id'
        )->withPivot(['edge_type', 'relation', 'weight', 'context']);
    }

    /**
     * Get all connected nodes (both directions).
     */
    public function getAllConnectedNodes(): \Illuminate\Support\Collection
    {
        $outgoing = $this->relatedNodes()->get();
        $incoming = $this->referencedByNodes()->get();
        
        return $outgoing->merge($incoming)->unique('id');
    }

    /**
     * Increment frequency and update last_seen_at.
     */
    public function recordSighting(): bool
    {
        $this->frequency++;
        $this->last_seen_at = now();
        return $this->save();
    }

    /**
     * Scope: Filter by room.
     */
    public function scopeForRoom($query, int $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    /**
     * Scope: Search by content (case-insensitive).
     */
    public function scopeSearch($query, string $term)
    {
        return $query->where('content', 'LIKE', "%{$term}%");
    }

    /**
     * Scope: Get most frequent nodes.
     */
    public function scopeMostFrequent($query, int $limit = 10)
    {
        return $query->orderByDesc('frequency')->limit($limit);
    }

    /**
     * Scope: Get recently seen nodes.
     */
    public function scopeRecentlySeen($query, int $days = 7)
    {
        return $query->where('last_seen_at', '>=', now()->subDays($days));
    }
}
