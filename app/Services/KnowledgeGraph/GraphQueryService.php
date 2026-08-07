<?php

namespace App\Services\KnowledgeGraph;

use App\Models\KnowledgeNode;
use App\Models\KnowledgeEdge;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GraphQueryService - Traverses knowledge graph to retrieve relevant context.
 * 
 * Implements efficient context retrieval:
 * - Keyword-based node matching
 * - Graph traversal (1-2 hops)
 * - Relevance scoring and ranking
 * - Context formatting for LLM consumption
 */
class GraphQueryService
{
    private EntityExtractor $extractor;
    private int $maxHops = 2;
    private int $maxNodes = 15;
    private int $maxContextTokens = 1500;

    public function __construct()
    {
        $this->extractor = new EntityExtractor();
    }

    /**
     * Query the knowledge graph for relevant context.
     */
    public function query(int $roomId, string $userQuery): array
    {
        $keywords = $this->extractor->extractKeywords($userQuery);

        if (empty($keywords)) {
            return $this->emptyResult();
        }

        $matchedNodes = $this->findMatchingNodes($roomId, $keywords);

        if ($matchedNodes->isEmpty()) {
            return $this->emptyResult();
        }

        $expandedNodes = $this->expandGraph($matchedNodes, $this->maxHops);

        $rankedNodes = $this->rankNodes($expandedNodes, $keywords);

        $topNodes = $rankedNodes->take($this->maxNodes);

        $context = $this->buildContext($topNodes);

        $tokenEstimate = intval(strlen($context) / 4);

        return [
            'success' => true,
            'context' => $context,
            'nodes' => $topNodes,
            'keywords' => $keywords,
            'token_estimate' => $tokenEstimate,
            'nodes_used' => $topNodes->count(),
        ];
    }

    /**
     * Find nodes matching keywords.
     */
    private function findMatchingNodes(int $roomId, array $keywords): Collection
    {
        $query = KnowledgeNode::where('room_id', $roomId);

        $query->where(function ($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                $q->orWhere('content', 'LIKE', "%{$keyword}%");
            }
        });

        return $query->orderByDesc('frequency')
            ->limit(20)
            ->get()
            ->map(function ($node) use ($keywords) {
                $node->match_score = $this->calculateMatchScore($node, $keywords);
                return $node;
            });
    }

    /**
     * Calculate match score for a node.
     */
    private function calculateMatchScore(KnowledgeNode $node, array $keywords): float
    {
        $score = 0.0;
        $content = strtolower($node->content);

        foreach ($keywords as $keyword) {
            $keyword = strtolower($keyword);

            if ($content === $keyword) {
                $score += 10.0;
            } elseif (str_contains($content, $keyword)) {
                $score += 5.0;
            }
        }

        $score += log($node->frequency + 1) * 2;

        if ($node->last_seen_at) {
            $daysSinceSeen = now()->diffInDays($node->last_seen_at);
            $recencyBoost = max(0, 5 - ($daysSinceSeen / 7));
            $score += $recencyBoost;
        }

        $typeWeights = [
            'topic' => 1.5,
            'concept' => 1.3,
            'action' => 1.0,
            'person' => 0.8,
            'entity' => 0.7,
        ];
        $score *= $typeWeights[$node->node_type] ?? 1.0;

        return $score;
    }

    /**
     * Expand graph by traversing edges.
     */
    private function expandGraph(Collection $seedNodes, int $maxHops): Collection
    {
        $allNodes = collect();
        $visited = [];

        foreach ($seedNodes as $node) {
            $node->hop_distance = 0;
            $node->expansion_score = $node->match_score ?? 10.0;
            $allNodes->push($node);
            $visited[$node->id] = true;
        }

        $currentLayer = $seedNodes;

        for ($hop = 1; $hop <= $maxHops; $hop++) {
            $nextLayer = collect();

            foreach ($currentLayer as $node) {
                $connectedIds = KnowledgeEdge::where(function ($q) use ($node) {
                    $q->where('source_node_id', $node->id)
                      ->orWhere('target_node_id', $node->id);
                })
                ->get()
                ->map(function ($edge) use ($node) {
                    return [
                        'node_id' => $edge->source_node_id === $node->id 
                            ? $edge->target_node_id 
                            : $edge->source_node_id,
                        'weight' => $edge->weight,
                        'type' => $edge->edge_type,
                    ];
                });

                foreach ($connectedIds as $conn) {
                    if (isset($visited[$conn['node_id']])) {
                        continue;
                    }

                    $connectedNode = KnowledgeNode::find($conn['node_id']);
                    if (!$connectedNode) {
                        continue;
                    }

                    $connectedNode->hop_distance = $hop;

                    $decayFactor = 0.5 / $hop;
                    $weightBoost = $conn['weight'] * 0.5;
                    $typeBoost = $conn['type'] === KnowledgeEdge::TYPE_EXTRACTED ? 1.5 : 1.0;

                    $connectedNode->expansion_score = 
                        ($node->expansion_score * $decayFactor) + 
                        ($weightBoost * $typeBoost) +
                        (log($connectedNode->frequency + 1));

                    $nextLayer->push($connectedNode);
                    $allNodes->push($connectedNode);
                    $visited[$connectedNode->id] = true;
                }
            }

            $currentLayer = $nextLayer;

            if ($currentLayer->isEmpty()) {
                break;
            }
        }

        return $allNodes;
    }

    /**
     * Rank nodes by combined relevance score.
     */
    private function rankNodes(Collection $nodes, array $keywords): Collection
    {
        return $nodes->map(function ($node) use ($keywords) {
            $baseScore = $node->match_score ?? 0;
            $expansionScore = $node->expansion_score ?? 0;
            $hopPenalty = isset($node->hop_distance) ? (1 / ($node->hop_distance + 1)) : 1;

            $keywordBonus = 0;
            foreach ($keywords as $kw) {
                if (str_contains(strtolower($node->content), strtolower($kw))) {
                    $keywordBonus += 3;
                }
            }

            $node->final_score = ($baseScore * 0.4) + 
                                 ($expansionScore * 0.3) + 
                                 ($node->frequency * 0.2) +
                                 ($keywordBonus * $hopPenalty);

            return $node;
        })
        ->sortByDesc('final_score')
        ->values();
    }

    /**
     * Build context string from ranked nodes.
     */
    private function buildContext(Collection $nodes): string
    {
        if ($nodes->isEmpty()) {
            return '';
        }

        $context = "=== KNOWLEDGE GRAPH CONTEXT ===\n";
        $context .= "(Retrieved from conversation history graph)\n\n";

        $byType = $nodes->groupBy('node_type');

        foreach ($byType as $type => $typeNodes) {
            $typeLabel = ucfirst($type) . 's';
            $nodeList = $typeNodes->pluck('content')->implode(', ');
            $context .= "**{$typeLabel}**: {$nodeList}\n";
        }

        $context .= "\n";

        $relationships = $this->getRelationshipsBetweenNodes($nodes);
        if (!empty($relationships)) {
            $context .= "**Connections**:\n";
            foreach (array_slice($relationships, 0, 10) as $rel) {
                $context .= "- {$rel['source']} → {$rel['relation']} → {$rel['target']}\n";
            }
            $context .= "\n";
        }

        $messageSnippets = $this->getMessageSnippets($nodes);
        if (!empty($messageSnippets)) {
            $context .= "**Relevant Conversation Snippets**:\n";
            foreach (array_slice($messageSnippets, 0, 3) as $snippet) {
                $context .= "- \"{$snippet}\"\n";
            }
        }

        $context .= "\n===================================\n";

        return $context;
    }

    /**
     * Get relationships between a set of nodes.
     */
    private function getRelationshipsBetweenNodes(Collection $nodes): array
    {
        $nodeIds = $nodes->pluck('id')->toArray();

        $edges = KnowledgeEdge::whereIn('source_node_id', $nodeIds)
            ->whereIn('target_node_id', $nodeIds)
            ->where('edge_type', KnowledgeEdge::TYPE_EXTRACTED)
            ->orderByDesc('weight')
            ->limit(15)
            ->get();

        $relationships = [];
        foreach ($edges as $edge) {
            $source = $nodes->firstWhere('id', $edge->source_node_id);
            $target = $nodes->firstWhere('id', $edge->target_node_id);

            if ($source && $target) {
                $relationships[] = [
                    'source' => $source->content,
                    'target' => $target->content,
                    'relation' => $edge->relation ?? 'related_to',
                ];
            }
        }

        return $relationships;
    }

    /**
     * Get message snippets related to nodes.
     */
    private function getMessageSnippets(Collection $nodes): array
    {
        $messageIds = $nodes->pluck('message_id')->filter()->unique()->toArray();

        if (empty($messageIds)) {
            return [];
        }

        $messages = Message::whereIn('id', $messageIds)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $snippets = [];
        foreach ($messages as $msg) {
            $content = $msg->content;
            if (strlen($content) > 150) {
                $content = substr($content, 0, 147) . '...';
            }
            $snippets[] = $content;
        }

        return $snippets;
    }

    /**
     * Return empty result structure.
     */
    private function emptyResult(): array
    {
        return [
            'success' => false,
            'context' => '',
            'nodes' => collect(),
            'keywords' => [],
            'token_estimate' => 0,
            'nodes_used' => 0,
        ];
    }

    /**
     * Get statistics for a room's knowledge graph.
     */
    public function getGraphStats(int $roomId): array
    {
        $nodeCount = KnowledgeNode::where('room_id', $roomId)->count();
        $edgeCount = KnowledgeEdge::where('room_id', $roomId)->count();

        $topNodes = KnowledgeNode::where('room_id', $roomId)
            ->orderByDesc('frequency')
            ->limit(5)
            ->pluck('content')
            ->toArray();

        $avgDegree = $edgeCount > 0 && $nodeCount > 0 
            ? round(($edgeCount * 2) / $nodeCount, 2) 
            : 0;

        $extractedEdges = KnowledgeEdge::where('room_id', $roomId)
            ->where('edge_type', KnowledgeEdge::TYPE_EXTRACTED)
            ->count();

        $inferredEdges = KnowledgeEdge::where('room_id', $roomId)
            ->where('edge_type', KnowledgeEdge::TYPE_INFERRED)
            ->count();

        return [
            'node_count' => $nodeCount,
            'edge_count' => $edgeCount,
            'avg_degree' => $avgDegree,
            'top_nodes' => $topNodes,
            'extracted_edges' => $extractedEdges,
            'inferred_edges' => $inferredEdges,
        ];
    }

    /**
     * Get graph data for visualization (vis.js format).
     */
    public function getVisualizationData(int $roomId, int $limit = 100): array
    {
        $nodes = KnowledgeNode::where('room_id', $roomId)
            ->orderByDesc('frequency')
            ->limit($limit)
            ->get();

        $nodeIds = $nodes->pluck('id')->toArray();

        $edges = KnowledgeEdge::where('room_id', $roomId)
            ->whereIn('source_node_id', $nodeIds)
            ->whereIn('target_node_id', $nodeIds)
            ->get();

        $typeColors = [
            'topic' => '#6366f1',
            'concept' => '#22c55e',
            'person' => '#f59e0b',
            'action' => '#ef4444',
            'entity' => '#8b5cf6',
        ];

        $visNodes = $nodes->map(function ($node) use ($typeColors) {
            return [
                'id' => $node->id,
                'label' => $node->content,
                'title' => "Type: {$node->node_type}\nFrequency: {$node->frequency}",
                'value' => $node->frequency,
                'color' => $typeColors[$node->node_type] ?? '#888888',
                'group' => $node->node_type,
            ];
        })->values();

        $visEdges = $edges->map(function ($edge) {
            return [
                'from' => $edge->source_node_id,
                'to' => $edge->target_node_id,
                'label' => $edge->relation,
                'title' => "Weight: {$edge->weight}\nType: {$edge->edge_type}",
                'value' => $edge->weight,
                'dashes' => $edge->edge_type === KnowledgeEdge::TYPE_INFERRED,
            ];
        })->values();

        return [
            'nodes' => $visNodes,
            'edges' => $visEdges,
        ];
    }
}
