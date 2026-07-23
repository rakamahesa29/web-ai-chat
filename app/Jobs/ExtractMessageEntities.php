<?php

namespace App\Jobs;

use App\Models\Room;
use App\Models\Message;
use App\Services\KnowledgeGraph\GraphManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ExtractMessageEntities Job
 * 
 * Asynchronously extracts entities and relationships from chat messages
 * and builds the knowledge graph. Runs after each bot response.
 */
class ExtractMessageEntities implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 30;

    protected int $roomId;
    protected string $userMessage;
    protected string $botResponse;
    protected ?int $messageId;
    protected ?string $modelName;

    public function __construct(
        int $roomId, 
        string $userMessage, 
        string $botResponse, 
        ?int $messageId = null,
        ?string $modelName = null
    ) {
        $this->roomId = $roomId;
        $this->userMessage = $userMessage;
        $this->botResponse = $botResponse;
        $this->messageId = $messageId;
        $this->modelName = $modelName;
    }

    public function handle(): void
    {
        Log::info("ExtractMessageEntities: Starting extraction for room {$this->roomId}");

        try {
            $graphManager = new GraphManager();

            if (!$graphManager->isEnabled()) {
                Log::info("ExtractMessageEntities: Knowledge graph is disabled, skipping");
                return;
            }

            $result = $graphManager->processMessage(
                $this->roomId,
                $this->userMessage,
                $this->botResponse,
                $this->messageId,
                $this->modelName
            );

            if ($result['success']) {
                Log::info("ExtractMessageEntities: Completed", [
                    'room_id' => $this->roomId,
                    'entities' => $result['entities_extracted'] ?? 0,
                    'relationships' => $result['relationships_extracted'] ?? 0,
                    'nodes_created' => $result['nodes_created'] ?? 0,
                    'edges_created' => $result['edges_created'] ?? 0,
                ]);

                if ($this->messageId && isset($result['nodes_created'])) {
                    $this->calculateAndSaveTokenSavings($graphManager);
                }
            } else {
                Log::warning("ExtractMessageEntities: Extraction failed", [
                    'room_id' => $this->roomId,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }

        } catch (\Exception $e) {
            Log::error("ExtractMessageEntities Error: " . $e->getMessage(), [
                'room_id' => $this->roomId,
                'message_id' => $this->messageId,
            ]);
            throw $e;
        }
    }

    /**
     * Calculate token savings and store in message.
     */
    private function calculateAndSaveTokenSavings(GraphManager $graphManager): void
    {
        try {
            $contextResult = $graphManager->getContext($this->roomId, $this->userMessage);

            if ($contextResult['success']) {
                $savings = $graphManager->calculateTokenSavings(
                    $this->roomId, 
                    $contextResult['context']
                );

                Message::where('id', $this->messageId)
                    ->update(['graph_tokens_saved' => $savings['tokens_saved']]);

                Log::info("ExtractMessageEntities: Token savings calculated", [
                    'message_id' => $this->messageId,
                    'tokens_saved' => $savings['tokens_saved'],
                    'savings_percentage' => $savings['savings_percentage'] . '%',
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("ExtractMessageEntities: Failed to calculate savings: " . $e->getMessage());
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ExtractMessageEntities Job Failed: " . $exception->getMessage(), [
            'room_id' => $this->roomId,
            'message_id' => $this->messageId,
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['knowledge-graph', 'room:' . $this->roomId];
    }
}
