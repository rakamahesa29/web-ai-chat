<?php

namespace App\Services\KnowledgeGraph;

use App\Models\KnowledgeNode;
use App\Models\KnowledgeEdge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GraphBuilder - Creates and updates knowledge graph nodes and edges.
 * 
 * Handles:
 * - Node creation with deduplication
 * - Edge creation with weight management
 * - Frequency tracking for importance ranking
 */
class GraphBuilder
{
    /**
     * Build graph from extracted entities and relationships.
     */
    public function build(int $roomId, array $extractionResult, ?int $messageId = null): array
    {
        $stats = [
            'nodes_created' => 0,
            'nodes_updated' => 0,
            'edges_created' => 0,
            'edges_updated' => 0,
        ];

        try {
            DB::beginTransaction();

            $nodeMap = [];

            foreach ($extractionResult['entities'] as $entity) {
                $node = $this->findOrCreateNode(
                    $roomId,
                    $entity['text'],
                    $entity['type'],
                    $messageId
                );

                if ($node->wasRecentlyCreated) {
                    $stats['nodes_created']++;
                } else {
                    $stats['nodes_updated']++;
                }

                $nodeMap[$entity['text']] = $node;
            }

            foreach ($extractionResult['relationships'] as $rel) {
                $sourceNode = $nodeMap[$rel['from']] ?? $this->findNodeByContent($roomId, $rel['from']);
                $targetNode = $nodeMap[$rel['to']] ?? $this->findNodeByContent($roomId, $rel['to']);

                if (!$sourceNode || !$targetNode) {
                    continue;
                }

                $edge = $this->findOrCreateEdge(
                    $roomId,
                    $sourceNode->id,
                    $targetNode->id,
                    $rel['relation']
                );

                if ($edge->wasRecentlyCreated) {
                    $stats['edges_created']++;
                } else {
                    $stats['edges_updated']++;
                }
            }

            $this->createImplicitEdges($roomId, array_values($nodeMap));

            DB::commit();

            Log::info("GraphBuilder: Built graph for room {$roomId}", $stats);

            return $stats;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("GraphBuilder Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Find or create a node with deduplication.
     */
    private function findOrCreateNode(int $roomId, string $content, string $type, ?int $messageId): KnowledgeNode
    {
        $normalizedContent = $this->normalizeContent($content);

        $existing = KnowledgeNode::where('room_id', $roomId)
            ->where('content', $normalizedContent)
            ->first();

        if ($existing) {
            $existing->frequency++;
            $existing->last_seen_at = now();
            if ($messageId && !$existing->message_id) {
                $existing->message_id = $messageId;
            }
            $existing->save();
            $existing->wasRecentlyCreated = false;
            return $existing;
        }

        $node = KnowledgeNode::create([
            'room_id' => $roomId,
            'message_id' => $messageId,
            'content' => $normalizedContent,
            'node_type' => $type,
            'frequency' => 1,
            'last_seen_at' => now(),
        ]);

        $node->wasRecentlyCreated = true;
        return $node;
    }

    /**
     * Find node by content (fuzzy match).
     */
    private function findNodeByContent(int $roomId, string $content): ?KnowledgeNode
    {
        $normalizedContent = $this->normalizeContent($content);

        return KnowledgeNode::where('room_id', $roomId)
            ->where('content', $normalizedContent)
            ->first();
    }

    /**
     * Find or create an edge.
     */
    private function findOrCreateEdge(int $roomId, int $sourceId, int $targetId, string $relation): KnowledgeEdge
    {
        $normalizedRelation = $this->normalizeContent($relation);

        $existing = KnowledgeEdge::where('room_id', $roomId)
            ->where('source_node_id', $sourceId)
            ->where('target_node_id', $targetId)
            ->where('relation', $normalizedRelation)
            ->first();

        if ($existing) {
            $existing->weight += 1.0;
            $existing->save();
            $existing->wasRecentlyCreated = false;
            return $existing;
        }

        $edge = KnowledgeEdge::create([
            'room_id' => $roomId,
            'source_node_id' => $sourceId,
            'target_node_id' => $targetId,
            'edge_type' => KnowledgeEdge::TYPE_EXTRACTED,
            'relation' => $normalizedRelation,
            'weight' => 1.0,
        ]);

        $edge->wasRecentlyCreated = true;
        return $edge;
    }

    /**
     * Create implicit edges between co-occurring entities.
     * Entities mentioned in the same message are likely related.
     */
    private function createImplicitEdges(int $roomId, array $nodes): void
    {
        if (count($nodes) < 2) {
            return;
        }

        for ($i = 0; $i < count($nodes); $i++) {
            for ($j = $i + 1; $j < count($nodes); $j++) {
                $existingEdge = KnowledgeEdge::where('room_id', $roomId)
                    ->where(function ($query) use ($nodes, $i, $j) {
                        $query->where(function ($q) use ($nodes, $i, $j) {
                            $q->where('source_node_id', $nodes[$i]->id)
                              ->where('target_node_id', $nodes[$j]->id);
                        })->orWhere(function ($q) use ($nodes, $i, $j) {
                            $q->where('source_node_id', $nodes[$j]->id)
                              ->where('target_node_id', $nodes[$i]->id);
                        });
                    })
                    ->first();

                if ($existingEdge) {
                    if ($existingEdge->edge_type === KnowledgeEdge::TYPE_INFERRED) {
                        $existingEdge->weight += 0.5;
                        $existingEdge->save();
                    }
                    continue;
                }

                KnowledgeEdge::create([
                    'room_id' => $roomId,
                    'source_node_id' => $nodes[$i]->id,
                    'target_node_id' => $nodes[$j]->id,
                    'edge_type' => KnowledgeEdge::TYPE_INFERRED,
                    'relation' => 'co_mentioned',
                    'weight' => 0.5,
                ]);
            }
        }
    }

    /**
     * Normalize content for consistent matching.
     */
    private function normalizeContent(string $content): string
    {
        $content = trim(strtolower($content));
        $content = preg_replace('/\s+/', ' ', $content);
        return $content;
    }

    /**
     * Merge duplicate nodes (cleanup utility).
     */
    public function mergeDuplicates(int $roomId): int
    {
        $merged = 0;

        $duplicates = KnowledgeNode::where('room_id', $roomId)
            ->select('content', DB::raw('COUNT(*) as count'), DB::raw('MIN(id) as keep_id'))
            ->groupBy('content')
            ->having('count', '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            $keepId = $dup->keep_id;

            $toMerge = KnowledgeNode::where('room_id', $roomId)
                ->where('content', $dup->content)
                ->where('id', '!=', $keepId)
                ->get();

            foreach ($toMerge as $node) {
                KnowledgeEdge::where('source_node_id', $node->id)
                    ->update(['source_node_id' => $keepId]);
                KnowledgeEdge::where('target_node_id', $node->id)
                    ->update(['target_node_id' => $keepId]);

                $keepNode = KnowledgeNode::find($keepId);
                $keepNode->frequency += $node->frequency;
                $keepNode->save();

                $node->delete();
                $merged++;
            }
        }

        return $merged;
    }

    /**
     * Prune low-frequency nodes (cleanup utility).
     */
    public function pruneWeakNodes(int $roomId, int $minFrequency = 1, int $minAgeDays = 30): int
    {
        $cutoffDate = now()->subDays($minAgeDays);

        $weakNodes = KnowledgeNode::where('room_id', $roomId)
            ->where('frequency', '<=', $minFrequency)
            ->where('last_seen_at', '<', $cutoffDate)
            ->get();

        $pruned = 0;
        foreach ($weakNodes as $node) {
            $node->outgoingEdges()->delete();
            $node->incomingEdges()->delete();
            $node->delete();
            $pruned++;
        }

        return $pruned;
    }
}
