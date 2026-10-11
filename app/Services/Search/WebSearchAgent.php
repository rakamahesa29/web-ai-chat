<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebSearchAgent
{
    protected $apiKey;

    protected SearchQueryTransformer $transformer;

    public function __construct(?SearchQueryTransformer $transformer = null)
    {
        // Mengambil kunci API Tavily dari config
        $this->apiKey = config('services.tavily.api_key');
        $this->transformer = $transformer ?? new SearchQueryTransformer();
    }

    /**
     * Membersihkan dan mentransformasikan query percakapan secara cerdas (Gemini-grade)
     * agar mesin pencari menerima kata kunci yang presisi, relevan, dan kontekstual.
     */
    public function sanitizeQuery(string $query, ?\App\Models\Room $room = null): string
    {
        return $this->transformer->transform($query, $room);
    }

    /**
     * Mengeksekusi pencarian ke Tavily API dan mengembalikan ringkasan teks terformat untuk RAG.
     */
    public function search(string $query, ?\App\Models\Room $room = null): string
    {
        if (empty($this->apiKey)) {
            Log::warning("WebSearchAgent: Kredensial TAVILY_API_KEY belum di-setup di .env");
            return "Fitur pencarian web tidak tersedia saat ini karena masalah konfigurasi server.";
        }

        $searchQuery = $this->sanitizeQuery($query, $room);

        Log::info("WebSearchAgent: Starting search", [
            'original_query' => $query,
            'search_query' => $searchQuery,
        ]);

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.tavily.com/search', [
                    'api_key' => $this->apiKey,
                    'query' => $searchQuery,
                    'search_depth' => 'advanced',     
                    'include_answer' => 'advanced',   
                    'max_results' => 4             
                ]);

            if (!$response->successful()) {
                Log::error("Tavily Search API Error: " . $response->body());
                return "=== LIVE WEB SEARCH RESULTS ===\nSYSTEM INFO: Pencarian web GAGAL dieksekusi.";
            }

            $results = $response->json();
            
            if (!isset($results['results']) || count($results['results']) === 0) {
                Log::info("WebSearchAgent: Search succeeded with 0 results", ['query' => $searchQuery]);
                return "=== LIVE WEB SEARCH RESULTS ===\nSYSTEM INFO: Pencarian berhasil, namun tidak ditemukan artikel/website yang relevan.";
            }

            Log::info("WebSearchAgent: Search succeeded", [
                'query' => $searchQuery,
                'results_count' => count($results['results']),
                'has_answer' => !empty($results['answer']),
            ]);

            $formattedContext = "=== LIVE WEB SEARCH RESULTS ===\n";
            $formattedContext .= "Gunakan referensi data terkini dari internet ini untuk menjawab pertanyaan:\n\n";

            if (!empty($results['answer'])) {
                $formattedContext .= ">> RANGKUMAN INSTAN PENCARIAN (Dari Tavily AI):\n";
                $formattedContext .= $results['answer'] . "\n\n";
            }

            $formattedContext .= ">> SUMBER REFERENSI DETAIL:\n";

            foreach ($results['results'] as $index => $item) {
                $rank = $index + 1;
                $title = $item['title'] ?? 'No Title';
                $link = $item['url'] ?? '#';
                $content = $item['content'] ?? '';

                $formattedContext .= "[Sumber {$rank}]: {$title}\n";
                $formattedContext .= "URL: {$link}\n";
                $formattedContext .= "Informasi: {$content}\n\n";
            }

            $formattedContext .= "=== AKHIR DATA INTERNET ===\n";

            return $formattedContext;

        } catch (\Exception $e) {
            Log::error("WebSearchAgent Exception (Tavily): " . $e->getMessage());
            return "Terjadi kegagalan sistem saat mencoba melakukan pencarian web via Tavily.";
        }
    }
}