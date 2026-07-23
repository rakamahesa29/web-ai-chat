<?php

namespace App\Services\AI;

use App\Models\Room;
use App\Jobs\CompressRoomMemory;
use Illuminate\Support\Facades\Log;

class MemoryManager
{
    public function handleAutoCompression(Room $room): void
    {
        $messageCount = $room->messages()->count();
        
        // Threshold: Jika sudah ada lebih dari 10 pesan (5 pasang percakapan)
        if ($messageCount > 10) {
            CompressRoomMemory::dispatch($room);
        }
    }

    public function executeCompression(Room $room): void
    {
        // Ambil 8 pesan terlama saja untuk dikompres, sisakan 2 terbaru untuk konteks nyambung
        $messagesToCompress = $room->messages()->oldest()->take(8)->get();
        
        if ($messagesToCompress->isEmpty()) return;

        $textToCompress = "";
        $idsToDelete = []; 

        foreach ($messagesToCompress as $msg) {
            // Hitung panjang pesan murni + lampiran KODE. 
            // PERHATIAN: search_context sengaja TIDAK DIHITUNG agar pesan hasil Googling 
            // tetap ikut dikompresi dan dihapus (menghemat space database).
            $totalLength = strlen($msg->content) + (!empty($msg->context_code) ? strlen($msg->context_code) : 0);

            // PENGAMAN: Jika user melampirkan kode yang panjang, abaikan pesan ini!
            // Pesan ini akan tetap hidup di database dan tidak akan dihapus.
            if ($totalLength > 2000) {
                continue; 
            }

            $role = $msg->sender_type === 'bot' ? 'AI' : 'User';
            
            // Masukkan HANYA konten inti percakapan ke kompresor LLM
            $textToCompress .= "{$role}: {$msg->content}\n";
            $idsToDelete[] = $msg->id; 
        }

        if (empty(trim($textToCompress))) return;

        try {
            // Ambil model yang sedang digunakan di room ini, jika tidak ada gunakan 'ollama'
            $activeModel = $room->model_name ?? 'ollama'; 
            $adapter = \App\Services\AI\ProviderFactory::make($activeModel);
        } catch (\Exception $e) {
            Log::warning("Auto-Compression Skipped (Adapter Error): " . $e->getMessage());
            return;
        }
        
        // PONYTAIL COMPRESSION PROMPT: Memaksa rangkuman super padat dan hemat token
        $systemPrompt = "You are an AI Memory Compressor. Extract ONLY the most critical facts, technical context, and user preferences from the conversation. 
RULES:
1. Write in Indonesian using ultra-short bullet points.
2. NO FLUFF. NO introductory phrases.
3. Ignore pleasantries or generic chat. Focus on context needed for future replies.";

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
            // Simpan ke tabel room_summaries yang baru
            $room->summaries()->create([
                'content' => $summary
            ]);

            // Hapus pesan lama yang sudah ter-rangkum, ketat sesuai array IDs
            if (!empty($idsToDelete)) {
                $room->messages()->whereIn('id', $idsToDelete)->delete();
            }
            
            Log::info("Room ID {$room->id} memory compressed. Saved to room_summaries. Freed " . count($idsToDelete) . " messages.");
        }
    }
}