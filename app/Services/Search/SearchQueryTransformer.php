<?php

namespace App\Services\Search;

use App\Models\Room;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SearchQueryTransformer
 * 
 * Mentransformasi pesan percakapan bebas (natural language chat)
 * menjadi query pencarian web yang padat, presisi, dan kontekstual,
 * terinspirasi dari arsitektur Search Grounding Google Gemini Pro & Flash.
 */
class SearchQueryTransformer
{
    /**
     * Transform pesan pengguna menjadi query pencarian optimal.
     */
    public function transform(string $message, ?Room $room = null): string
    {
        $raw = trim($message);
        if (empty($raw)) {
            return '';
        }

        // 1. Eksekusi Fast LLM Query Rewriter jika API tersedia & cepat (< 1.5 detik)
        $llmQuery = $this->tryLlmTransformation($raw, $room);
        if (!empty($llmQuery)) {
            Log::info("SearchQueryTransformer: LLM transformed query", [
                'original' => $raw,
                'transformed' => $llmQuery
            ]);
            return $llmQuery;
        }

        // 2. Eksekusi Gemini-grade Heuristic NLP Transformer (Zero Latency Fallback)
        $nlpQuery = $this->heuristicTransform($raw, $room);
        Log::info("SearchQueryTransformer: NLP transformed query", [
            'original' => $raw,
            'transformed' => $nlpQuery
        ]);

        return $nlpQuery;
    }

    /**
     * Heuristic NLP Transformer (Multi-Stage Semantic Cleaning & Context Resolution)
     */
    public function heuristicTransform(string $message, ?Room $room = null): string
    {
        $q = trim($message);

        // Tahap 1: Normalisasi tanda baca, kutip, dan whitespace
        $q = preg_replace('/[\x{2018}\x{2019}\x{201C}\x{201D}]/u', '"', $q);
        $q = preg_replace('/\s+/u', ' ', $q);

        // Tahap 2: Eliminasi sapaan di awal (Indonesian, English, Arabic, Slang, Daerah)
        $greetingPattern = '/^(
            (hai|halo|helo|hi|hello|hey|hei|hoy|oy|oi)\b |
            (selamat\s+(pagi|siang|sore|malam|datang|hari|sejahtera))\b |
            (assalamu\s*[\'\w]*\s*(alaikum(\s*wr\s*wb)?)?|sampurasun|kulonuwun|shalom|om\s*swastiastu)\b |
            (permisi|maaf|punten|tabe|nyuwun\s*sewu)\b |
            (woi|bro|sis|gan|min|admin|bang|mas|kak|pak|bu|om|tante|bot|ai)\b
        )[\s,.:!?-]*/iux';

        while (preg_match($greetingPattern, $q)) {
            $q = preg_replace($greetingPattern, '', $q);
        }

        // Tahap 3: Eliminasi kata permohonan, kata kerja perintah, & modal auxiliaries
        $requestPattern = '/^(
            (tolong(\s+dong)?|coba(\s+dong)?|mohon|minta\s*tolong|silakan|please|pls)\s+ |
            (bisa(\s*kah)?|bisakah|dapat(\s*kah)?|boleh(\s*kah)?|mau\s*tanya|ingin\s*tahu|nanya\s*dong)(\s+(kamu|anda|lu|lo|loe|ente|min|bot|ai))?\s+ |
            (tolong\s+bantu\s+(kamu\s+)?(cari|carikan|cariin|cek|cekin)|bantu\s+(cek|cekin|cari|cariin)|bantu\s+dong)\s+ |
            (carikan(\s+dong)?|cari(kan|in)?|search(kan)?|googling(\s+dong)?|browsing|cek(in)?|periksa)\s+ |
            (kira[\s-]*kira|menurut\s*(kamu|anda|sumber))\s+ |
            (info(rmasi)?(\s+tentang|\s+mengenai|\s+soal)?|kabar(\s+tentang|\s+mengenai)?|tentang|mengenai|soal)\s+
        )/iux';

        while (preg_match($requestPattern, $q)) {
            $q = preg_replace($requestPattern, '', $q);
        }

        // Tahap 4: Eliminasi kata tanya percakapan & framing pembuka
        $questionPattern = '/^(
            (bagaimana(\s+sih|\s+ya|\s+nih)?|gimana(\s+sih|\s+ya|\s+nih)?|seperti\s+apa)\s+ |
            (apa(\s*kah)?(\s+ada|\s+betul|\s+benar|\s+sudah|\s+bisa|\s+saja)?)\s+ |
            (ada(\s+gak|\s+nggak|\s+tidak|\s+update|\s+kabar)?)\s+ |
            (kapan|dimana|siapa(\s*kah)?|kenapa|mengapa)\s+(ya|sih|kira[\s-]*kira)?\s* |
            (what(\s+is|\s+are)?|how(\s+about|\s+is|\s+are)?|tell\s+me\s+about|can\s+you\s+(find|check|search))\s+
        )/iux';

        $q = preg_replace($questionPattern, '', $q);

        // Tahap 5: Eliminasi basa-basi & pertanyaan tambahan di akhir kalimat
        $trailingPattern = '/(
            [,;]?\s*(apakah\s+ada\s+(progress|kemajuan|perkembangan|korban)|ada\s+(info|update|kabar)\s+baru|gimana\s+(kelanjutannya|infonya|kabarnya))\s*[?!.]*$ |
            \s*\b(dong|ya|sih|deh|kah|gak|nggak|beneran|kira[\s-]*kira|please|pls)\b\s*[?!.]*$ |
            [?!.,;:\s]+$
        )/iux';

        while (preg_match($trailingPattern, $q)) {
            $q = preg_replace($trailingPattern, '', $q);
        }

        $q = trim($q);

        // Tahap 6: Resolusi Konteks Multi-turn (Contextual Anaphora Resolution)
        // Jika pertanyaan adalah follow-up pendek (contoh: "apakah ada korban jiwa?", "ada update?"),
        // kaitkan subjek/entitas dari percakapan sebelumnya seperti pada Gemini.
        if ($room) {
            $q = $this->resolveContextualAnaphora($q, $room);
        }

        return !empty($q) ? $q : trim($message);
    }

    /**
     * Resolusi konteks percakapan multi-turn untuk menangani pertanyaan lanjutan.
     */
    protected function resolveContextualAnaphora(string $currentQuery, Room $room): string
    {
        $wordCount = str_word_count($currentQuery);
        $isShortFollowUp = $wordCount <= 4 || preg_match('/^(korban|tersangka|penyebab|kerugian|update|perkembangan|jadwal|hasil|dampaknya|kronologi)\b/iu', $currentQuery);

        if (!$isShortFollowUp) {
            return $currentQuery;
        }

        // Ambil pesan pengguna sebelumnya dari ruangan ini
        $lastMessages = $room->messages()
            ->where('sender_type', 'user')
            ->latest()
            ->take(5)
            ->get();

        foreach ($lastMessages as $pastMsg) {
            $pastContent = trim($pastMsg->content);
            if (empty($pastContent) || $pastContent === $currentQuery) {
                continue;
            }

            // Bersihkan pesan masa lalu untuk mengambil topik inti
            $pastTopic = $this->heuristicTransform($pastContent, null);
            if (!empty($pastTopic) && str_word_count($pastTopic) >= 2) {
                // Hindari duplikasi kata kunci
                if (!str_contains(strtolower($currentQuery), strtolower($pastTopic))) {
                    return $currentQuery . ' ' . $pastTopic;
                }
            }
        }

        return $currentQuery;
    }

    /**
     * Fast LLM Transformation (Menggunakan DeepSeek API jika tersedia dengan timeout ketat)
     */
    protected function tryLlmTransformation(string $message, ?Room $room = null): ?string
    {
        $deepseekApiKey = config('services.deepseek.api_key');
        if (empty($deepseekApiKey)) {
            return null;
        }

        try {
            $contextHint = '';
            if ($room) {
                $lastMsg = $room->messages()->where('sender_type', 'user')->latest()->first();
                if ($lastMsg && $lastMsg->content !== $message) {
                    $contextHint = "Previous conversation topic: " . substr($lastMsg->content, 0, 100) . "\n";
                }
            }

            $prompt = "Task: Convert the following user chat message into 2-5 concise, high-relevance search engine keywords in the original language.\n"
                . "Rules:\n"
                . "1. Strip all conversational greetings, politeness, and question framing.\n"
                . "2. Keep core subject entities, locations, and recency intent (e.g. terbaru, 2026).\n"
                . "3. Output ONLY the search query keywords on a single line. Do not explain.\n"
                . $contextHint
                . "User message: \"{$message}\"\n"
                . "Search query:";

            $response = Http::timeout(2)
                ->withHeaders([
                    'Authorization' => "Bearer {$deepseekApiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.deepseek.com/v1/chat/completions', [
                    'model' => config('services.deepseek.model', 'deepseek-v4-flash'),
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => 30,
                    'temperature' => 0.0,
                ]);

            if ($response->successful()) {
                $text = trim($response->json('choices.0.message.content', ''));
                $text = preg_replace('/^Search query:\s*/i', '', $text);
                $text = trim(trim($text, '"\''));
                if (!empty($text) && strlen($text) < 120) {
                    return $text;
                }
            }
        } catch (\Throwable $e) {
            // Abaikan kegagalan LLM secara silent agar langsung fallback ke NLP
            Log::debug("SearchQueryTransformer: LLM skip/timeout, fallback to NLP: " . $e->getMessage());
        }

        return null;
    }
}
