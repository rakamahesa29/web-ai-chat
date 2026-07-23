<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserAnalysis;
use App\Models\KnowledgeNode;
use App\Services\AI\Adapters\BaseAdapter;
use App\Services\AI\Adapters\DeepseekAdapter;
use App\Services\AI\Adapters\OllamaAdapter;
use App\Services\AI\Adapters\OllamaCloudAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeUserBehaviorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(
        protected int $analysisId,
        protected int $userId,
        protected string $modelProvider = 'deepseek',
        protected ?string $previousResult = null
    ) {}

    public function handle(): void
    {
        $analysis = UserAnalysis::find($this->analysisId);
        if (!$analysis) {
            return;
        }

        $analysis->update(['status' => 'processing']);

        try {
            $userData = $this->gatherUserData();
            $prompt = $this->buildPrompt($userData);
            $result = $this->callAI($prompt);

            $analysis->update([
                'status' => 'completed',
                'analysis_result' => $result,
                'model_used' => $this->modelProvider,
            ]);
        } catch (\Exception $e) {
            Log::error("AnalyzeUserBehaviorJob failed: " . $e->getMessage(), [
                'analysis_id' => $this->analysisId,
                'user_id' => $this->userId,
                'model_provider' => $this->modelProvider,
            ]);

            $analysis->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function gatherUserData(): array
    {
        $user = User::with(['rooms' => function ($query) {
            $query->withCount('messages');
        }])->findOrFail($this->userId);

        $rooms = $user->rooms->map(fn ($room) => [
            'title' => $room->title,
            'category' => $room->category,
            'message_count' => $room->messages_count,
        ])->toArray();

        $knowledgeNodes = KnowledgeNode::whereIn('room_id', $user->rooms->pluck('id'))
            ->select('content', 'node_type', 'frequency')
            ->orderByDesc('frequency')
            ->limit(50)
            ->get()
            ->groupBy('node_type')
            ->map(fn ($group) => $group->map(fn ($node) => [
                'content' => $node->content,
                'frequency' => $node->frequency,
            ])->toArray())
            ->toArray();

        $totalMessages = $user->rooms->sum('messages_count');
        $totalRooms = $user->rooms->count();

        return [
            'user_name' => $user->name,
            'total_rooms' => $totalRooms,
            'total_messages' => $totalMessages,
            'rooms' => $rooms,
            'knowledge_nodes' => $knowledgeNodes,
        ];
    }

    private function buildPrompt(array $data): array
    {
        $roomsSummary = collect($data['rooms'])->map(function ($room) {
            $category = $room['category'] ? " (Category: {$room['category']})" : '';
            return "- \"{$room['title']}\"{$category}: {$room['message_count']} messages";
        })->implode("\n");

        $nodesSummary = collect($data['knowledge_nodes'])->map(function ($nodes, $type) {
            $items = collect($nodes)->map(fn ($n) => "  - {$n['content']} (freq: {$n['frequency']})")->implode("\n");
            return "[$type]\n$items";
        })->implode("\n\n");

        $isRenewal = !empty($this->previousResult);

        $systemPrompt = $isRenewal
            ? "You are an expert behavioral analyst AI. You have been asked to UPDATE an existing user profiling report with fresh data. Compare the new data with the previous analysis, highlight what changed, identify new trends, and produce an updated comprehensive report. Be insightful, specific, and data-driven. Write your analysis in well-structured markdown."
            : "You are an expert behavioral analyst AI. Your task is to analyze a user's chat history data and produce a comprehensive profiling report. Be insightful, specific, and data-driven. Write your analysis in well-structured markdown.";

        $previousSection = '';
        if ($isRenewal) {
            $previousSection = <<<PREV

## Previous Analysis Report
The following is the user's previous analysis report. Use it as a baseline to compare against the current data. Highlight new developments, changes in behavior, and emerging patterns.

---
{$this->previousResult}
---

PREV;
        }

        $renewalInstruction = $isRenewal
            ? "\n6. **Changes Since Last Analysis**: What has changed since the previous report? New interests, shifts in engagement, or evolving patterns."
            : '';

        $userPrompt = <<<PROMPT
Analyze the following user data and generate a detailed behavioral & interest profiling report.
{$previousSection}
## User Overview (Current Data)
- Name: {$data['user_name']}
- Total Chat Rooms: {$data['total_rooms']}
- Total Messages: {$data['total_messages']}

## Chat Rooms (with message intensity)
{$roomsSummary}

## Knowledge Nodes Extracted from Conversations
{$nodesSummary}

## Required Analysis Sections
Please produce the following sections in your report:

1. **Interest Profile**: What are this user's primary interests and topics based on room titles and knowledge nodes?
2. **Engagement Level**: Estimate the user's engagement/satisfaction level based on message intensity (high message count = deep engagement, low = surface-level). Categorize as: Very High, High, Moderate, Low.
3. **Knowledge Map**: What domains of knowledge does this user frequently explore? Identify patterns.
4. **Behavioral Patterns**: Any notable patterns in how the user interacts (e.g., focused deep-dives vs. broad exploration)?
5. **Recommendations**: Based on the analysis, suggest how the AI assistant can better serve this user.{$renewalInstruction}
PROMPT;

        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    /**
     * Route the prompt to the appropriate AI adapter based on the selected provider.
     */
    private function callAI(array $prompt): string
    {
        $adapter = $this->resolveAdapter();
        $response = $adapter->generateResponse($prompt);

        $fullText = '';
        foreach ($response as $chunk) {
            if (!empty($chunk['content'])) {
                $fullText .= $chunk['content'];
            }
        }

        if (empty(trim($fullText))) {
            throw new \RuntimeException("AI model ({$this->modelProvider}) returned an empty response.");
        }

        return $fullText;
    }

    private function resolveAdapter(): BaseAdapter
    {
        return match ($this->modelProvider) {
            'ollama' => new OllamaAdapter(
                config('services.ollama.model', 'gemma4:12b-mlx')
            ),
            'ollama_cloud' => new OllamaCloudAdapter(
                config('services.ollama_cloud.model', 'gemma4:31b-cloud')
            ),
            'deepseek' => $this->makeDeepseekAdapter(),
            default => throw new \RuntimeException("Unknown model provider: {$this->modelProvider}"),
        };
    }

    private function makeDeepseekAdapter(): DeepseekAdapter
    {
        $apiKey = config('services.deepseek.api_key');
        $model = config('services.deepseek.model', 'deepseek-v4-flash');

        if (!$apiKey) {
            throw new \RuntimeException('DeepSeek API key is not configured.');
        }

        return new DeepseekAdapter($apiKey, $model);
    }
}
