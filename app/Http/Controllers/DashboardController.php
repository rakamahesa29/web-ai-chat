<?php
namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\AiConfig;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeEdge;
use App\Models\UserAnalysis;
use App\Jobs\AnalyzeUserBehaviorJob;
use App\Services\KnowledgeGraph\GraphManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with analytics.
     */
    public function index()
    {
        // 1. Ambil SEMUA settings dalam SATU query (Sangat Efisien)
        // Hasilnya adalah array: ['ollama_enabled' => '1', 'ollama_cloud_enabled' => '1', ...]
        $allSettings = DB::table('settings')->pluck('value', 'key')->toArray();

        // 2. Ambil data asli dari Database untuk Chart
        $pluckedScores = Message::selectRaw('satisfaction_score, count(*) as count')
            ->whereNotNull('satisfaction_score')
            ->groupBy('satisfaction_score')
            ->pluck('count', 'satisfaction_score')
            ->toArray();

        // Olah Data untuk Chart & Hitung Success Rate
        $satisfactionData = [];
        $totalFeedback = 0;
        $happyFeedback = 0;

        for ($i = 1; $i <= 3; $i++) {
            $count = $pluckedScores[$i] ?? 0;
            $satisfactionData[$i] = $count;
            $totalFeedback += $count;
            if ($i === 3) { 
                $happyFeedback = $count;
            }
        }

        $successRate = $totalFeedback > 0 ? round(($happyFeedback / $totalFeedback) * 100) : 0;
        
        // 3. Isi stats menggunakan $allSettings yang sudah di-pluck tadi
        $stats = [
            'avg_memory' => $this->calculateAvgMemory(),
            'avg_bot_tokens' => round(Message::where('sender_type', 'bot')->avg('tokens_used') ?? 0),
            'avg_user_tokens' => round(Message::where('sender_type', 'user')->where('tokens_used', '>', 0)->avg('tokens_used') ?? 0),
            'success_rate' => $successRate, 
            
            // Sekarang $allSettings sudah ada isinya, filter_var akan bekerja dengan benar
            'is_ollama_mode'    => filter_var($allSettings['ollama_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'is_ollama_cloud_mode'    => filter_var($allSettings['ollama_cloud_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'is_deepseek_mode'  => filter_var($allSettings['deepseek_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        // Ambil data Radar Chart
        $topicData = Message::select('category', DB::raw('count(*) as total'))
            ->whereNotNull('category')
            ->groupBy('category')
            ->pluck('total', 'category');

        // Data untuk Chart Baru: Aktivitas Chat per Hari (7 Hari Terakhir)
        $chatActivity = Message::select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as total'))
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy('date')
            ->orderBy('date', 'ASC')
            ->pluck('total', 'date')
            ->toArray();

        // Format data agar selalu ada 7 hari meskipun kosong
        $activityLabels = [];
        $activityData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $activityLabels[] = now()->subDays($i)->format('d M');
            $activityData[] = $chatActivity[$date] ?? 0;
        }

        // Knowledge Graph Statistics
        $graphStats = $this->getKnowledgeGraphStats();

        // Latest user behavior analysis
        $latestAnalysis = UserAnalysis::where('user_id', Auth::id())
            ->latest()
            ->first();

        return view('dashboard', compact('stats', 'satisfactionData', 'topicData', 'activityLabels', 'activityData', 'graphStats', 'latestAnalysis'));
    }

    /**
     * Get Knowledge Graph statistics.
     */
    private function getKnowledgeGraphStats(): array
    {
        $totalNodes = KnowledgeNode::count();
        $totalEdges = KnowledgeEdge::count();
        $roomsWithGraphs = KnowledgeNode::distinct('room_id')->count('room_id');
        
        $totalTokensSaved = Message::whereNotNull('graph_tokens_saved')
            ->where('graph_tokens_saved', '>', 0)
            ->sum('graph_tokens_saved');
        
        $avgDegree = $totalNodes > 0 
            ? round(($totalEdges * 2) / $totalNodes, 2) 
            : 0;
        
        $nodesByType = KnowledgeNode::select('node_type', DB::raw('COUNT(*) as count'))
            ->groupBy('node_type')
            ->pluck('count', 'node_type')
            ->toArray();

        $topNodes = KnowledgeNode::select('content', DB::raw('SUM(frequency) as total_freq'))
            ->groupBy('content')
            ->orderByDesc('total_freq')
            ->limit(5)
            ->pluck('total_freq', 'content')
            ->toArray();

        $indexedMessages = Message::where('graph_indexed', true)->count();
        $totalMessages = Message::count();
        $indexProgress = $totalMessages > 0 
            ? round(($indexedMessages / $totalMessages) * 100, 1) 
            : 0;

        return [
            'total_nodes' => $totalNodes,
            'total_edges' => $totalEdges,
            'rooms_with_graphs' => $roomsWithGraphs,
            'total_tokens_saved' => $totalTokensSaved,
            'avg_degree' => $avgDegree,
            'nodes_by_type' => $nodesByType,
            'top_nodes' => $topNodes,
            'indexed_messages' => $indexedMessages,
            'index_progress' => $indexProgress,
            'is_enabled' => config('services.knowledge_graph.enabled', true),
        ];
    }

    /**
     * Get Knowledge Graph visualization data for a specific room.
     */
    public function getGraphData(Request $request)
    {
        $roomId = $request->input('room_id');
        $limit = $request->input('limit', 100);

        $graphManager = new GraphManager();

        if ($roomId) {
            $data = $graphManager->getVisualizationData((int) $roomId, $limit);
        } else {
            // Get global visualization (across all rooms)
            $nodes = KnowledgeNode::orderByDesc('frequency')
                ->limit($limit)
                ->get();

            $nodeIds = $nodes->pluck('id')->toArray();

            $edges = KnowledgeEdge::whereIn('source_node_id', $nodeIds)
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
                    'title' => "Type: {$node->node_type}\nFrequency: {$node->frequency}\nRoom: {$node->room_id}",
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
                    'dashes' => $edge->edge_type === 'INFERRED',
                ];
            })->values();

            $data = [
                'nodes' => $visNodes,
                'edges' => $visEdges,
            ];
        }

        return response()->json($data);
    }

    /**
     * Get list of rooms with knowledge graphs for dropdown.
     */
    public function getGraphRooms()
    {
        $rooms = DB::table('knowledge_nodes')
            ->join('rooms', 'knowledge_nodes.room_id', '=', 'rooms.id')
            ->select('rooms.id', 'rooms.title', DB::raw('COUNT(knowledge_nodes.id) as node_count'))
            ->groupBy('rooms.id', 'rooms.title')
            ->orderByDesc('node_count')
            ->limit(50)
            ->get();

        return response()->json($rooms);
    }

    public function updateProviderMode(Request $request)
    {
        try {
            $provider = $request->input('provider');
            $enabled = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN);

            // 3. Mapping Provider to Database Keys
            // Pastikan mapping ini persis sama dengan key di file Seeder
            $dbKeyPrefix = match($provider) {
                'ollama'    => 'ollama',
                'ollama_cloud'    => 'ollama_cloud',
                'deepseek'  => 'deepseek',
                default => $provider
            };
            
            $settingKey = $dbKeyPrefix . '_enabled'; 

            DB::table('settings')->updateOrInsert(
                ['key' => $settingKey],
                [
                    'value' => $enabled ? 1 : 0,
                    'description' => "Toggle to use {$provider} provider",
                    'updated_at' => now(), 
                ]
            );

            return response()->json(['message' => 'Success', 'provider' => $provider, 'enabled' => $enabled]);
        } catch (\Exception $e) {
            \Log::error("Provider Toggle Error: " . $e->getMessage());
            return response()->json(['message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Dispatch the user behavior analysis job.
     */
    public function analyzeUser(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'model' => 'nullable|in:deepseek,ollama,ollama_cloud',
        ]);

        $modelProvider = $request->input('model', 'deepseek');

        $existing = UserAnalysis::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'An analysis is already in progress.',
                'analysis_id' => $existing->id,
                'status' => $existing->status,
            ], 409);
        }

        $previousResult = null;
        $previousAnalysis = UserAnalysis::where('user_id', $user->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        if ($previousAnalysis) {
            $previousResult = $previousAnalysis->analysis_result;
        }

        $analysis = UserAnalysis::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'model_used' => $modelProvider,
        ]);

        AnalyzeUserBehaviorJob::dispatch($analysis->id, $user->id, $modelProvider, $previousResult);

        $isRenewal = !empty($previousResult);

        return response()->json([
            'message' => $isRenewal ? 'Renewal analysis started.' : 'Analysis started.',
            'analysis_id' => $analysis->id,
            'is_renewal' => $isRenewal,
            'model' => $modelProvider,
        ]);
    }

    /**
     * Poll the status of a user behavior analysis.
     */
    public function getAnalysisStatus(UserAnalysis $analysis)
    {
        if ($analysis->user_id !== Auth::id()) {
            abort(403);
        }

        return response()->json([
            'id' => $analysis->id,
            'status' => $analysis->status,
            'analysis_result' => $analysis->analysis_result,
            'error_message' => $analysis->error_message,
            'model_used' => $analysis->model_used,
            'created_at' => $analysis->created_at->diffForHumans(),
        ]);
    }

    /**
     * Cancel a pending/processing analysis so the user can retry.
     */
    public function cancelAnalysis(UserAnalysis $analysis)
    {
        if ($analysis->user_id !== Auth::id()) {
            abort(403);
        }

        if (!in_array($analysis->status, ['pending', 'processing'])) {
            return response()->json(['message' => 'This analysis is not cancellable.'], 422);
        }

        $analysis->update([
            'status' => 'failed',
            'error_message' => 'Cancelled by user.',
        ]);

        return response()->json(['message' => 'Analysis cancelled.']);
    }

    private function calculateAvgMemory()
    {
        // Hitung rata-rata panjang karakter dari semua pesan (user + bot)
        $avgLength = Message::selectRaw('AVG(LENGTH(content)) as avg_length')->value('avg_length');
        
        if (!$avgLength) {
            return "0";
        }

        // Asumsi kasar: 1 karakter = 1 byte. 
        // Konversi ke Kilobyte (KB)
        $kb = $avgLength / 1024;
        
        return round($kb, 2); 
    }
}