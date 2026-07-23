<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeEdge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardApiController extends Controller
{
    /**
     * GET /api/dashboard/stats
     */
    public function stats()
    {
        $allSettings = DB::table('settings')->pluck('value', 'key')->toArray();

        // Satisfaction
        $plucked = Message::selectRaw('satisfaction_score, count(*) as count')
            ->whereNotNull('satisfaction_score')
            ->groupBy('satisfaction_score')
            ->pluck('count', 'satisfaction_score')
            ->toArray();

        $happy = $plucked[3] ?? 0;
        $total = array_sum($plucked);
        $successRate = $total > 0 ? round(($happy / $total) * 100) : 0;

        // Knowledge Graph
        $totalNodes = KnowledgeNode::count();
        $totalEdges = KnowledgeEdge::count();

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'avg_memory_kb'       => round(Message::count() * 2.1), // approx
                'avg_bot_tokens'      => (int) round(Message::where('sender_type', 'bot')->avg('tokens_used') ?? 0),
                'avg_user_tokens'     => (int) round(Message::where('sender_type', 'user')->where('tokens_used', '>', 0)->avg('tokens_used') ?? 0),
                'success_rate'        => $successRate,
                'total_messages'      => Message::count(),
                'graph_nodes'         => $totalNodes,
                'graph_edges'         => $totalEdges,
                'providers'           => [
                    'ollama_enabled'       => filter_var($allSettings['ollama_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'ollama_cloud_enabled' => filter_var($allSettings['ollama_cloud_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'deepseek_enabled'     => filter_var($allSettings['deepseek_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ],
            ],
        ]);
    }

    /**
     * GET /api/dashboard/activity
     */
    public function activity()
    {
        $activity = Message::selectRaw('DATE(created_at) as day, count(*) as count')
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn($r) => ['day' => $r->day, 'count' => (int) $r->count]);

        return response()->json(['status' => 'ok', 'data' => $activity]);
    }

    /**
     * POST /api/dashboard/provider-mode
     */
    public function updateProviderMode(Request $request)
    {
        $request->validate([
            'provider' => 'required|string|in:ollama,ollama_cloud,deepseek',
            'enabled'  => 'required|boolean',
        ]);

        DB::table('settings')->updateOrInsert(
            ['key' => $request->provider . '_enabled'],
            ['value' => $request->enabled ? '1' : '0']
        );

        return response()->json(['status' => 'ok', 'message' => 'Provider updated.']);
    }

    /**
     * GET /api/dashboard/graph-data?room_id=&limit=100
     * Returns rich node/edge data for visualization (matching web version).
     */
    public function graphData(Request $request)
    {
        $roomId = $request->input('room_id');
        $limit = (int) $request->input('limit', 100);

        $typeColors = [
            'topic'   => '#6366f1',
            'concept' => '#22c55e',
            'person'  => '#f59e0b',
            'action'  => '#ef4444',
            'entity'  => '#8b5cf6',
        ];

        $query = KnowledgeNode::orderByDesc('frequency');
        if ($roomId) {
            $query->where('room_id', $roomId);
        }
        $nodes = $query->limit($limit)->get();
        $nodeIds = $nodes->pluck('id')->toArray();

        $edgeQuery = KnowledgeEdge::whereIn('source_node_id', $nodeIds)
            ->whereIn('target_node_id', $nodeIds);
        $edges = $edgeQuery->limit(500)->get();

        $visNodes = $nodes->map(fn($n) => [
            'id'         => $n->id,
            'label'      => $n->content,
            'title'      => "Type: {$n->node_type}\nFrequency: {$n->frequency}\nRoom: {$n->room_id}",
            'value'      => $n->frequency,
            'color'      => $typeColors[$n->node_type] ?? '#888888',
            'group'      => $n->node_type,
            'room_id'    => $n->room_id,
        ])->values();

        $visEdges = $edges->map(fn($e) => [
            'from'  => $e->source_node_id,
            'to'    => $e->target_node_id,
            'label' => $e->relation,
            'title' => "Weight: {$e->weight}\nType: {$e->edge_type}",
            'value' => $e->weight,
            'dashes' => $e->edge_type === 'INFERRED',
        ])->values();

        return response()->json([
            'status' => 'ok',
            'data'   => ['nodes' => $visNodes, 'edges' => $visEdges],
        ]);
    }

    /**
     * GET /api/dashboard/graph-rooms — rooms with knowledge graph data (for filter dropdown).
     */
    public function graphRooms()
    {
        $rooms = DB::table('knowledge_nodes')
            ->join('rooms', 'knowledge_nodes.room_id', '=', 'rooms.id')
            ->select('rooms.id', 'rooms.title', DB::raw('COUNT(knowledge_nodes.id) as node_count'))
            ->groupBy('rooms.id', 'rooms.title')
            ->orderByDesc('node_count')
            ->limit(50)
            ->get();

        return response()->json(['status' => 'ok', 'data' => $rooms]);
    }

    /**
     * GET /api/dashboard/graph-stats — aggregate graph statistics.
     */
    public function graphStats()
    {
        $totalNodes  = KnowledgeNode::count();
        $totalEdges  = KnowledgeEdge::count();
        $roomsWithGraphs = KnowledgeNode::distinct('room_id')->count('room_id');

        $tokensSaved = Message::whereNotNull('graph_tokens_saved')
            ->where('graph_tokens_saved', '>', 0)
            ->sum('graph_tokens_saved');

        $avgDegree = $totalNodes > 0
            ? round(($totalEdges * 2) / $totalNodes, 2)
            : 0;

        $nodesByType = KnowledgeNode::select('node_type', DB::raw('COUNT(*) as count'))
            ->groupBy('node_type')
            ->pluck('count', 'node_type')
            ->toArray();

        $indexedMessages = Message::where('graph_indexed', true)->count();
        $totalMessages = Message::count();
        $indexProgress = $totalMessages > 0
            ? round(($indexedMessages / $totalMessages) * 100, 1)
            : 0;

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'total_nodes'       => $totalNodes,
                'total_edges'       => $totalEdges,
                'rooms_with_graphs' => $roomsWithGraphs,
                'total_tokens_saved'=> (int) $tokensSaved,
                'avg_degree'        => $avgDegree,
                'nodes_by_type'     => $nodesByType,
                'index_progress'    => $indexProgress,
            ],
        ]);
    }
}
