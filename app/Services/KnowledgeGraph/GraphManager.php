<?php

namespace App\Services\KnowledgeGraph;

use App\Models\Room;
use App\Models\Message;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeEdge;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * GraphManager - Orchestrates the Knowledge Graph pipeline.
 * 
 * Main entry point for:
 * - Processing messages into graph
 * - Querying graph for context
 * - Managing graph lifecycle
 * - Calculating token savings
 */
class GraphManager
{
    private EntityExtractor $extractor;
    private GraphBuilder $builder;
    private GraphQueryService $queryService;

    public function __construct()
    {
        $this->extractor = new EntityExtractor();
        $this->builder = new GraphBuilder();
        $this->queryService = new GraphQueryService();
    }

    /**
     * Process a message pair (user + bot) into the knowledge graph.
     */
    public function processMessage(int $roomId, string $userMessage, string $botResponse, ?int $messageId = null, ?string $modelName = null): array
    {
        Log::info("GraphManager: Processing message for room {$roomId}");

        try {
            $extractionResult = $this->extractor->extract($userMessage, $botResponse, $modelName);

            if (empty($extractionResult['entities'])) {
                Log::info("GraphManager: No entities extracted from message");
                return [
                    'success' => true,
                    'entities_extracted' => 0,
                    'relationships_extracted' => 0,
                    'nodes_created' => 0,
                    'edges_created' => 0,
                ];
            }

            $buildStats = $this->builder->build($roomId, $extractionResult, $messageId);

            if ($messageId) {
                Message::where('id', $messageId)->update(['graph_indexed' => true]);
            }

            return [
                'success' => true,
                'entities_extracted' => count($extractionResult['entities']),
                'relationships_extracted' => count($extractionResult['relationships']),
                'nodes_created' => $buildStats['nodes_created'],
                'nodes_updated' => $buildStats['nodes_updated'],
                'edges_created' => $buildStats['edges_created'],
                'edges_updated' => $buildStats['edges_updated'],
            ];

        } catch (\Exception $e) {
            Log::error("GraphManager Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get relevant context from knowledge graph for a query.
     */
    public function getContext(int $roomId, string $query): array
    {
        return $this->queryService->query($roomId, $query);
    }

    /**
     * Get graph statistics for a room.
     */
    public function getStats(int $roomId): array
    {
        return $this->queryService->getGraphStats($roomId);
    }

    /**
     * Get visualization data for dashboard.
     */
    public function getVisualizationData(int $roomId, int $limit = 100): array
    {
        return $this->queryService->getVisualizationData($roomId, $limit);
    }

    /**
     * Calculate token savings from using graph vs full history.
     */
    public function calculateTokenSavings(int $roomId, string $graphContext, int $recentMessageCount = 12): array
    {
        $recentMessages = Message::where('room_id', $roomId)
            ->latest()
            ->take($recentMessageCount)
            ->get();

        $fullHistoryChars = $recentMessages->sum(function ($msg) {
            return strlen($msg->content) + 
                   strlen($msg->context_code ?? '') + 
                   strlen($msg->search_context ?? '');
        });

        $fullHistoryTokens = intval($fullHistoryChars / 4);
        $graphContextTokens = intval(strlen($graphContext) / 4);
        $tokensSaved = max(0, $fullHistoryTokens - $graphContextTokens);

        $savingsPercentage = $fullHistoryTokens > 0 
            ? round(($tokensSaved / $fullHistoryTokens) * 100, 1) 
            : 0;

        return [
            'full_history_tokens' => $fullHistoryTokens,
            'graph_context_tokens' => $graphContextTokens,
            'tokens_saved' => $tokensSaved,
            'savings_percentage' => $savingsPercentage,
            'efficiency_ratio' => $graphContextTokens > 0 
                ? round($fullHistoryTokens / $graphContextTokens, 1) 
                : 0,
        ];
    }

    /**
     * Get global statistics across all rooms.
     */
    public function getGlobalStats(): array
    {
        $totalNodes = KnowledgeNode::count();
        $totalEdges = KnowledgeEdge::count();
        $roomsWithGraphs = KnowledgeNode::distinct('room_id')->count('room_id');

        $totalTokensSaved = Message::whereNotNull('graph_tokens_saved')
            ->sum('graph_tokens_saved');

        $topNodes = KnowledgeNode::select('content', DB::raw('SUM(frequency) as total_freq'))
            ->groupBy('content')
            ->orderByDesc('total_freq')
            ->limit(10)
            ->get();

        $nodesByType = KnowledgeNode::select('node_type', DB::raw('COUNT(*) as count'))
            ->groupBy('node_type')
            ->pluck('count', 'node_type')
            ->toArray();

        $edgesByType = KnowledgeEdge::select('edge_type', DB::raw('COUNT(*) as count'))
            ->groupBy('edge_type')
            ->pluck('count', 'edge_type')
            ->toArray();

        $avgDegree = $totalNodes > 0 
            ? round(($totalEdges * 2) / $totalNodes, 2) 
            : 0;

        return [
            'total_nodes' => $totalNodes,
            'total_edges' => $totalEdges,
            'rooms_with_graphs' => $roomsWithGraphs,
            'total_tokens_saved' => $totalTokensSaved,
            'avg_degree' => $avgDegree,
            'top_nodes' => $topNodes,
            'nodes_by_type' => $nodesByType,
            'edges_by_type' => $edgesByType,
        ];
    }

    /**
     * Index existing messages for a room (backfill).
     */
    public function indexExistingMessages(int $roomId, ?string $modelName = null): array
    {
        $messages = Message::where('room_id', $roomId)
            ->where('graph_indexed', false)
            ->orderBy('created_at')
            ->get();

        $results = [
            'total_messages' => $messages->count(),
            'processed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $userMessage = null;

        foreach ($messages as $msg) {
            if ($msg->sender_type === 'user') {
                $userMessage = $msg;
                continue;
            }

            if ($msg->sender_type === 'bot' && $userMessage) {
                try {
                    $this->processMessage(
                        $roomId,
                        $userMessage->content,
                        $msg->content,
                        $msg->id,
                        $modelName
                    );
                    $results['processed']++;
                } catch (\Exception $e) {
                    Log::error("GraphManager: Failed to index message {$msg->id}: " . $e->getMessage());
                    $results['errors']++;
                }

                $userMessage = null;
            }
        }

        return $results;
    }

    /**
     * Clean up graph (remove weak connections, merge duplicates).
     */
    public function cleanup(int $roomId): array
    {
        $merged = $this->builder->mergeDuplicates($roomId);
        $pruned = $this->builder->pruneWeakNodes($roomId, 1, 30);

        return [
            'duplicates_merged' => $merged,
            'weak_nodes_pruned' => $pruned,
        ];
    }

    /**
     * Delete all graph data for a room.
     */
    public function deleteRoomGraph(int $roomId): bool
    {
        try {
            DB::beginTransaction();

            KnowledgeEdge::where('room_id', $roomId)->delete();
            KnowledgeNode::where('room_id', $roomId)->delete();

            Message::where('room_id', $roomId)
                ->update([
                    'graph_indexed' => false,
                    'graph_tokens_saved' => null,
                ]);

            DB::commit();

            Log::info("GraphManager: Deleted graph for room {$roomId}");
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("GraphManager: Failed to delete graph for room {$roomId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if knowledge graph feature is enabled.
     */
    public function isEnabled(): bool
    {
        return config('services.knowledge_graph.enabled', true);
    }

    /**
     * Check if a room has sufficient graph data.
     */
    public function hasGraphData(int $roomId): bool
    {
        return KnowledgeNode::where('room_id', $roomId)->count() >= 3;
    }
}
