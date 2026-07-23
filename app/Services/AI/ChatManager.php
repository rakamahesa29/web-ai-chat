<?php

namespace App\Services\AI;

use App\Models\Room;
use App\Models\Message;

class ChatManager
{
    /**
     * Mengambil konteks lengkap dari sebuah room untuk dikirim ke AI.
     * Ini memastikan isolasi antar room (Point 3 di permintaan Anda).
     * 
     * @param int $roomId
     * @return array
     */
    public function getContext(int $roomId): array
    {
        $room = Room::findOrFail($roomId);
        
        // Ini agar AI "ingat" konteks pembicaraan saat ini.
        $history = Message::where('room_id', $roomId)
            ->latest()
            ->take(20)
            ->get()
            ->reverse()
            ->map(function ($msg) {
                return [
                    'role' => $msg->sender_type === 'user' ? 'user' : 'assistant',
                    'content' => $msg->content,
                ];
            })
            ->toArray();

        // Menggabungkan System Prompt (Identitas Room) dengan Riwayat Chat
        return [
            'system_prompt' => $room->system_prompt ?? 'You are a helpful assistant.',
            'history' => $history,
            'room_id' => $roomId
        ];
    }

    /**
     * Menyiapkan payload untuk dikirim ke AI.
     * Di sini kita akan menggabungkan instruksi sistem dan riwayat.
     */
    public function preparePayload(int $roomId): array
    {
        $context = $this->getContext($roomId);
        
        // Struktur ini disiapkan agar kompatibel dengan hampir semua provider (OpenAI, Gemini, Anthropic)
        return [
            'system' => $context['system_prompt'],
            'messages' => array_merge(
                [['role' => 'system', 'content' => $context['system_prompt']]],
                $context['history']
            ),
        ];
    }
}