<?php

namespace App\Services\RAG;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Embedding Service using Ollama's /api/embeddings endpoint.
 * 
 * Implements the embedding layer from O'Reilly RAG patterns.
 * Uses bge-m3 by default (1024 dimensions, fast, good quality).
 */
class EmbeddingService
{
    private string $baseUrl;
    private string $model;
    private int $dimensions;

    public function __construct()
    {
        $this->baseUrl = config('services.ollama.base_url', 'http://127.0.0.1:11434');
        $this->model = config('services.ollama.embedding_model', 'bge-m3:567m');
        $this->dimensions = config('services.ollama.embedding_dimensions', 1024);
    }

    /**
     * Generate embedding vector for a single text.
     * Uses cache for query embeddings to avoid redundant API calls.
     */
    public function embed(string $text, bool $useCache = false): ?array
    {
        $processed = $this->preprocessText($text);
        
        if ($useCache) {
            $cacheKey = 'embed:' . md5($this->model . ':' . $processed);
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $response = Http::timeout(60)->post("{$this->baseUrl}/api/embeddings", [
                'model' => $this->model,
                'prompt' => $processed,
            ]);

            if (!$response->successful()) {
                Log::error("Embedding API Error: " . $response->body());
                return null;
            }

            $data = $response->json();
            $embedding = $data['embedding'] ?? null;

            if ($useCache && $embedding) {
                Cache::put($cacheKey, $embedding, 3600);
            }

            return $embedding;

        } catch (\Exception $e) {
            Log::error("Embedding Service Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate embeddings for multiple texts (batch processing).
     */
    public function embedBatch(array $texts): array
    {
        $embeddings = [];
        
        foreach ($texts as $index => $text) {
            $embedding = $this->embed($text);
            $embeddings[$index] = $embedding;
            
            // Small delay to avoid overwhelming Ollama
            if ($index < count($texts) - 1) {
                usleep(50000); // 50ms delay
            }
        }
        
        return $embeddings;
    }

    /**
     * Calculate cosine similarity between two vectors.
     * Returns value between -1 and 1 (1 = identical, 0 = orthogonal, -1 = opposite).
     */
    public function cosineSimilarity(array $vecA, array $vecB): float
    {
        if (count($vecA) !== count($vecB)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($vecA); $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * Preprocess text before embedding.
     * Cleans and normalizes text for better embedding quality.
     */
    private function preprocessText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Trim to reasonable length (most embedding models have limits)
        $maxChars = 8000; // ~2000 tokens
        if (strlen($text) > $maxChars) {
            $text = substr($text, 0, $maxChars);
        }
        
        return trim($text);
    }

    /**
     * Check if Ollama embedding model is available.
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");
            
            if (!$response->successful()) {
                return false;
            }
            
            $models = $response->json()['models'] ?? [];
            foreach ($models as $model) {
                if (str_contains($model['name'], $this->model)) {
                    return true;
                }
            }
            
            Log::warning("Embedding model '{$this->model}' not found. Run: ollama pull {$this->model}");
            return false;
            
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }
}