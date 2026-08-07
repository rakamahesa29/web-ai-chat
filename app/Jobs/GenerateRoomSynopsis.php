<?php

namespace App\Jobs;

use App\Models\Room;
use App\Models\RoomSynopsis;
use App\Services\AI\ProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generates a meta-synopsis by condensing multiple session summaries
 * into a single compact paragraph. This forms the top tier of the
 * 3-tier memory hierarchy: Synopsis > Summaries > Recent Messages.
 */
class GenerateRoomSynopsis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Room $room) {}

    public function handle(): void
    {
        $latestSynopsis = $this->room->synopses()->latest()->first();
        $consumedCount = $latestSynopsis ? $latestSynopsis->summaries_consumed : 0;

        // Get summaries that haven't been consumed by a synopsis yet
        $unconsumedSummaries = $this->room->summaries()
            ->latest()
            ->skip(0)
            ->get()
            ->slice($consumedCount > 0 ? 0 : 0);

        // Only generate if we have at least 5 unconsumed summaries
        $allSummaries = $this->room->summaries()->oldest()->get();
        $totalSummaries = $allSummaries->count();
        
        if ($totalSummaries < 5) {
            Log::info("Room {$this->room->id}: Not enough summaries for synopsis ({$totalSummaries})");
            return;
        }

        // Take all summaries and condense them
        $summaryTexts = $allSummaries->pluck('content')->implode("\n\n---\n\n");

        try {
            $activeModel = $this->room->model_name ?? 'ollama';
            $adapter = ProviderFactory::make($activeModel);
        } catch (\Exception $e) {
            Log::warning("Synopsis generation skipped (Adapter Error): " . $e->getMessage());
            return;
        }

        $systemPrompt = "You are an AI Memory Architect. Condense multiple conversation session summaries into ONE cohesive synopsis.
RULES:
1. Write in Indonesian, max 300 words.
2. Organize by THEMES, not chronologically: [USER PROFILE], [ACTIVE PROJECTS], [TECHNICAL STACK], [KEY DECISIONS], [ONGOING TASKS].
3. Preserve exact technical terms, file paths, and version numbers.
4. This synopsis is the AI's long-term memory — it must enable context continuity across future sessions.
5. Drop redundant or outdated information; keep only the latest state of each topic.";

        $generator = $adapter->generateResponse([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Condense these session summaries into one synopsis:\n\n" . $summaryTexts]
        ]);

        $synopsis = '';
        foreach ($generator as $chunk) {
            if (isset($chunk['content'])) {
                $synopsis .= $chunk['content'];
            }
        }

        if (!empty(trim($synopsis))) {
            RoomSynopsis::create([
                'room_id' => $this->room->id,
                'content' => $synopsis,
                'summaries_consumed' => $totalSummaries,
            ]);

            Log::info("Room {$this->room->id}: Synopsis generated from {$totalSummaries} summaries.");
        }
    }
}
