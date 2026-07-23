<?php

namespace App\Services\AI;

use App\Models\Room;
use App\Services\RAG\JitRagService;
use Illuminate\Support\Facades\Log;

class ChatProcessor
{
    protected $memoryManager;
    protected $promptBuilder;

    public function __construct(
        MemoryManager $memoryManager, 
        PromptBuilder $promptBuilder
    ) {
        $this->memoryManager = $memoryManager;
        $this->promptBuilder = $promptBuilder;
    }

    public function process(int $roomId, string $modelName, array $options = []): \Generator
    {
        // ── Agent Mode: delegate to AgentProcessor ──
        $chatMode = $options['chat_mode'] ?? 'ask';
        $workspacePath = $options['workspace_path'] ?? null;

        if ($chatMode === 'agent' && $workspacePath) {
            $room = Room::findOrFail($roomId);
            
            // Get recent messages for conversation history
            $recentMessages = $room->messages()->latest()->take(12)->get()->reverse()->values();
            $latestUserMsg = $recentMessages->last() ? $recentMessages->last()->content : '';
            
            // Format conversation history (exclude the last message since it's the current one)
            $history = [];
            $msgs = $recentMessages->slice(0, -1); // all except last
            foreach ($msgs as $msg) {
                $history[] = [
                    'role' => $msg->sender_type === 'user' ? 'user' : 'assistant',
                    'content' => $msg->content,
                ];
            }

            $agentProcessor = new AgentProcessor();
            yield from $agentProcessor->process(
                $roomId,
                $modelName,
                $workspacePath,
                $latestUserMsg,
                $history,
                $options
            );
            return;
        }

        $config = ConfigurationManager::getConfiguration($modelName);

        $room = Room::findOrFail($roomId);
        
        // 1. Handle Memory
        $this->memoryManager->handleAutoCompression($room);

        // 2. Prepare Data with Query Classification
        $recentMessages = $room->messages()->latest()->take(12)->get()->reverse()->values();
        $latestUserMsg = $recentMessages->last() ? $recentMessages->last()->content : '';

        // =====================================================
        // JIT AGENTIC RAG: Detect folder path in user message
        // =====================================================
        $folderPath = JitRagService::detectFolderPath($latestUserMsg);
        $jitContext = '';

        if ($folderPath) {
            Log::info("JIT RAG: Folder path detected in message", ['path' => $folderPath]);

            yield ['type' => 'meta', 'status' => 'jit_start', 'message' => 'Local folder detected. Starting JIT RAG pipeline...'];

            $jitService = new JitRagService();
            $jitGenerator = $jitService->process($folderPath, $latestUserMsg);

            // Forward all JIT status events to the SSE stream
            $jitResult = null;
            while ($jitGenerator->valid()) {
                $event = $jitGenerator->current();
                yield $event;
                $jitGenerator->next();
            }
            $jitResult = $jitGenerator->getReturn();

            if (!empty($jitResult['success']) && !empty($jitResult['context'])) {
                $jitContext = $jitResult['context'];
                Log::info("JIT RAG: Context ready", [
                    'brain_ids' => $jitResult['brain_ids'],
                    'context_chars' => strlen($jitContext),
                ]);
            }
        }

        // 3. Build prompt (with optional JIT context injection)
        $options['jit_context'] = $jitContext;
        
        $buildResult = $this->promptBuilder->build($room, collect($recentMessages), $modelName, $options);
        $payload = $buildResult['payload'];
        $usedInternalData = $buildResult['used_internal_data'];
        $matchedKeywords = $buildResult['matched_keywords'];
        $classification = $buildResult['classification'] ?? null;
        $suggestWebSearch = $buildResult['suggest_web_search'] ?? false;

        // If JIT RAG provided context, override usedInternalData
        if (!empty($jitContext)) {
            $usedInternalData = true;
        }

        // 4. Execution
        $adapter = ProviderFactory::make($modelName, $options);

        try {
            // Emit classification info to UI
            if ($classification) {
                yield [
                    'type' => 'meta',
                    'status' => 'classified',
                    'query_type' => $classification['type'],
                    'reason' => $classification['reason'] ?? '',
                ];
            }
            
            // Emit internal search status if using RAG (but not if JIT already showed its own statuses)
            if ($usedInternalData && empty($jitContext)) {
                yield [
                    'type' => 'meta',
                    'status' => 'searching_internal',
                    'keywords' => $matchedKeywords
                ];
                usleep(500000);
                
                yield [
                    'type' => 'meta',
                    'status' => 'thinking'
                ];
            } elseif (!empty($jitContext)) {
                yield [
                    'type' => 'meta',
                    'status' => 'thinking',
                    'message' => 'Generating response from folder documents...'
                ];
            } elseif ($classification && $classification['type'] === QueryClassifier::TYPE_GENERAL) {
                yield [
                    'type' => 'meta',
                    'status' => 'using_model_knowledge'
                ];
                usleep(300000);
            }
            
            // Check if we should suggest web search for latest data queries
            // Skip this check if user explicitly enabled web search or if JIT RAG is active
            $userEnabledWebSearch = $options['use_web_search'] ?? false;
            
            if ($suggestWebSearch && empty($jitContext) && $classification['type'] === QueryClassifier::TYPE_LATEST_DATA && !$userEnabledWebSearch) {
                $hasInternalData = $classification['brain_data']['found'] ?? false;
                
                if (!$hasInternalData) {
                    yield [
                        'type' => 'meta',
                        'status' => 'suggest_web_search',
                        'reason' => 'latest_data_no_internal',
                        'message' => 'Pertanyaan ini memerlukan data terbaru yang mungkin tidak ada dalam database internal. Apakah Anda ingin melakukan pencarian web?'
                    ];
                    return;
                }
            }

            $stream = $adapter->generateResponse($payload);
            $fullContent = '';
            
            foreach ($stream as $chunk) {
                if (is_array($chunk)) {
                    $chunk['type'] = 'chunk';
                    
                    if (isset($chunk['content'])) {
                        $fullContent .= $chunk['content'];
                    }
                    
                    yield $chunk;
                }
            }
            
            // After streaming completes, check if we should suggest web search for latest data
            if ($suggestWebSearch && empty($jitContext) && $classification['type'] === QueryClassifier::TYPE_LATEST_DATA && !$userEnabledWebSearch) {
                $hasInternalData = $classification['brain_data']['found'] ?? false;
                
                if ($hasInternalData) {
                    yield [
                        'type' => 'meta',
                        'status' => 'recommend_web_search',
                        'reason' => 'latest_data_may_be_outdated',
                        'message' => 'Jawaban di atas berdasarkan data internal. Untuk informasi terbaru, Anda bisa melakukan pencarian web.',
                        'internal_sources' => $classification['brain_data']['titles'] ?? []
                    ];
                }
            }
            
        } catch (\Exception $e) {
            Log::error("ChatProcessor Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Resume agent loop after client executed tool calls.
     */
    public function processAgentContinue(string $stateToken, array $toolResults): \Generator
    {
        $agentProcessor = new AgentProcessor();
        yield from $agentProcessor->continue($stateToken, $toolResults);
    }
}