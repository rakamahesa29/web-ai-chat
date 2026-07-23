<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebSearchAgent
{
    protected $apiKey;

    public function __construct()
    {
        // Mengambil kunci API Tavily dari config
        $this->apiKey = config('services.tavily.api_key');
    }

    /**
     * Mengeksekusi pencarian ke Tavily API dan mengembalikan ringkasan teks terformat untuk RAG.
     */
    public function search(string $query): string
    {
        if (empty($this->apiKey)) {
            Log::warning("WebSearchAgent: Kredensial TAVILY_API_KEY belum di-setup di .env");
            return "Fitur pencarian web tidak tersedia saat ini karena masalah konfigurasi server.";
        }

        $currentYear = date('Y');
        $searchQuery = $query;
        
        if (!preg_match('/\b20\d{2}\b/', $query)) {
            $searchQuery .= ' ' . $currentYear;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.tavily.com/search', [
                    'api_key' => $this->apiKey,
                    'query' => $searchQuery,
                    'search_depth' => 'basic',     
                    'include_answer' => 'basic',   
                    'max_results' => 4             
                ]);

            if (!$response->successful()) {
                Log::error("Tavily Search API Error: " . $response->body());
                return "=== LIVE WEB SEARCH RESULTS ===\nSYSTEM INFO: Pencarian web GAGAL dieksekusi.";
            }

            $results = $response->json();
            
            if (!isset($results['results']) || count($results['results']) === 0) {
                return "=== LIVE WEB SEARCH RESULTS ===\nSYSTEM INFO: Pencarian berhasil, namun tidak ditemukan artikel/website yang relevan.";
            }

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