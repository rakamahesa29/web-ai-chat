<?php

namespace App\Services\AI;

use App\Models\Room;
use App\Jobs\CompressRoomMemory;
use Illuminate\Support\Facades\Log;

class MemoryManager
{
    private static array $dispatching = [];

    public function handleAutoCompression(Room $room, ?int $messageCount = null): void
    {
        if (isset(self::$dispatching[$room->id])) {
            return;
        }

        $messageCount ??= $room->messages()->count();
        
        if ($messageCount > 20) {
            self::$dispatching[$room->id] = true;
            try {
                CompressRoomMemory::dispatch($room);
            } finally {
                unset(self::$dispatching[$room->id]);
            }
        }
    }

    public function executeCompression(Room $room): void
    {
        $totalMessages = $room->messages()->count();
        
        // Keep the 6 most recent messages intact for immediate context continuity
        $keepRecent = 6;
        $compressibleCount = max(0, $totalMessages - $keepRecent);
        
        if ($compressibleCount < 4) return;
        
        // Compress in batches of up to 14 oldest messages
        $batchSize = min(14, $compressibleCount);
        $messagesToCompress = $room->messages()->oldest()->take($batchSize)->get();
        
        if ($messagesToCompress->isEmpty()) return;

        $textToCompress = "";
        $idsToDelete = []; 

        foreach ($messagesToCompress as $msg) {
            $totalLength = strlen($msg->content) + (!empty($msg->context_code) ? strlen($msg->context_code) : 0);

            if ($totalLength > 4000) {
                continue; 
            }

            $role = $msg->sender_type === 'bot' ? 'AI' : 'User';
            $textToCompress .= "{$role}: {$msg->content}\n";
            $idsToDelete[] = $msg->id; 
        }

        if (empty(trim($textToCompress))) return;

        try {
            $activeModel = $room->model ?? 'deepseek';
            $adapter = \App\Services\AI\ProviderFactory::make($activeModel);
        } catch (\Exception $e) {
            Log::warning("Auto-Compression Skipped (Adapter Error): " . $e->getMessage());
            return;
        }
        
        $systemPrompt = "You are an AI Memory Compressor. Extract critical facts from the conversation into STRUCTURED categories.
RULES:
1. Write in Indonesian using ultra-short bullet points.
2. Use these category labels: [DECISION], [PREFERENCE], [TECHNICAL], [CODE], [CONTEXT], [TASK].
3. NO FLUFF. NO introductory phrases. NO generic pleasantries.
4. Preserve exact technical terms, function names, file paths, and version numbers verbatim.
5. Focus on information the AI will need to maintain conversation continuity.";

        $summaryGenerator = $adapter->generateResponse([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Summarize this strictly:\n\n" . $textToCompress]
        ]);

        $summary = '';
        foreach ($summaryGenerator as $chunk) {
            if (isset($chunk['content'])) {
                $summary .= $chunk['content'];
            }
        }

        if (!empty(trim($summary))) {
            $room->summaries()->create([
                'content' => $summary
            ]);

            if (!empty($idsToDelete)) {
                $room->messages()->whereIn('id', $idsToDelete)->delete();
            }
            
            Log::info("Room ID {$room->id} memory compressed. Saved to room_summaries. Freed " . count($idsToDelete) . " messages.");
        }
    }
}