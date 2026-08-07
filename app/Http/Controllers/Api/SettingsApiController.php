<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsApiController extends Controller
{
    /**
     * GET /api/settings
     */
    public function index()
    {
        $all = DB::table('settings')->pluck('value', 'key')->toArray();

        return response()->json([
            'status' => 'ok',
            'data'   => [
                /* Providers */
                'ollama_enabled'           => $this->bool($all, 'ollama_enabled', true),
                'ollama_local_url'         => $all['ollama_local_url'] ?? 'http://127.0.0.1:11434',
                'ollama_local_model'       => $all['ollama_local_model'] ?? 'gemma4:12b-mlx',

                'ollama_cloud_enabled'     => $this->bool($all, 'ollama_cloud_enabled', false),
                'ollama_cloud_url'         => $all['ollama_cloud_url'] ?? '',
                'ollama_cloud_model'       => $all['ollama_cloud_model'] ?? 'gemma4:31b-cloud',

                'deepseek_enabled'         => $this->bool($all, 'deepseek_enabled', true),
                'deepseek_api_key'         => $all['deepseek_api_key'] ?? '',
                'deepseek_flash_model'     => $all['deepseek_flash_model'] ?? 'deepseek-v4-flash',
                'deepseek_pro_model'       => $all['deepseek_pro_model'] ?? 'deepseek-v4-pro',
                'deepseek_reasoning'       => $all['deepseek_reasoning'] ?? 'high',

                /* Embeddings & RAG */
                'embedding_model'          => $all['embedding_model'] ?? 'bge-m3:567m',
                'embedding_dimensions'     => (int) ($all['embedding_dimensions'] ?? 1024),
                
                'rag_enabled'              => $this->bool($all, 'rag_enabled', true),
                'rag_chunk_size'           => (int) ($all['rag_chunk_size'] ?? 1500),
                'rag_chunk_overlap'        => (int) ($all['rag_chunk_overlap'] ?? 300),
                'rag_similarity_threshold' => (float) ($all['rag_similarity_threshold'] ?? 0.65),
                'rag_top_k'                => (int) ($all['rag_top_k'] ?? 8),
                'rag_hybrid_search'        => $this->bool($all, 'rag_hybrid_search', true),
                'rag_use_mmr'              => $this->bool($all, 'rag_use_mmr', true),

                /* Knowledge Graph */
                'knowledge_graph_enabled'  => $this->bool($all, 'knowledge_graph_enabled', true),

                /* Web Search */
                'web_search_enabled'       => $this->bool($all, 'web_search_enabled', true),
                'tavily_api_key'           => $all['tavily_api_key'] ?? '',

                /* Appearance */
                'dark_mode'                => $this->bool($all, 'dark_mode', false),
            ],
        ]);
    }

    /**
     * PUT /api/settings
     */
    public function update(Request $request)
    {
        $allowed = [
            'ollama_enabled', 'ollama_local_url', 'ollama_local_model',
            'ollama_cloud_enabled', 'ollama_cloud_url', 'ollama_cloud_model',
            'deepseek_enabled', 'deepseek_api_key', 'deepseek_flash_model',
            'deepseek_pro_model', 'deepseek_reasoning',
            'embedding_model', 'embedding_dimensions',
            'rag_enabled', 'rag_chunk_size', 'rag_chunk_overlap',
            'rag_similarity_threshold', 'rag_top_k', 'rag_hybrid_search', 'rag_use_mmr',
            'knowledge_graph_enabled', 'web_search_enabled', 'tavily_api_key',
            'dark_mode',
        ];

        foreach ($request->only($allowed) as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value]
            );
        }

        return response()->json(['status' => 'ok', 'message' => 'Settings saved.']);
    }

    private function bool(array $all, string $key, bool $default): bool
    {
        return filter_var($all[$key] ?? $default, FILTER_VALIDATE_BOOLEAN);
    }
}