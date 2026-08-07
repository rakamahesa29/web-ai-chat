<?php

namespace App\Services\RAG;

use App\Models\Brain;
use App\Models\BrainChunk;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * Vector Search Service implementing O'Reilly RAG retrieval patterns.
 * 
 * Features:
 * 1. Semantic Search - Cosine similarity on embeddings
 * 2. Hybrid Search - Combine vector + keyword scores
 * 3. Re-ranking - Optional cross-encoder re-ranking
 * 4. MMR (Maximal Marginal Relevance) - Diversity in results
 */
class VectorSearchService
{
    private EmbeddingService $embedder;
    private float $similarityThreshold;
    private int $topK;

    public function __construct(EmbeddingService $embedder)
    {
        $this->embedder = $embedder;
        $this->similarityThreshold = config('services.rag.similarity_threshold', 0.45);
        $this->topK = config('services.rag.top_k', 6);
    }

    /**
     * Perform semantic search on chunks.
     * Returns top-k most similar chunks to the query.
     */
    public function search(string $query, array $filters = []): Collection
    {
        $queryEmbedding = $this->embedder->embed($query, useCache: true);
        
        if (!$queryEmbedding) {
            Log::warning("Failed to generate query embedding, falling back to keyword search");
            return collect([]);
        }

        // Pre-filter: get only the brain_ids that match tags (avoids loading all chunks)
        $brainIdsQuery = Brain::query();
        if (!empty($filters['tags'])) {
            $brainIdsQuery->where(function ($q) use ($filters) {
                foreach ($filters['tags'] as $tag) {
                    $q->orWhereJsonContains('tag', $tag);
                }
            });
        }
        $brainIds = $brainIdsQuery->where('is_indexed', true)->pluck('id')->toArray();

        if (empty($brainIds)) {
            return collect([]);
        }

        // Process chunks in batches to reduce peak memory
        $topResults = collect();
        
        BrainChunk::whereIn('brain_id', $brainIds)
            ->whereNotNull('embedding')
            ->with('brain:id,title,tag')
            ->chunk(200, function ($chunks) use ($queryEmbedding, &$topResults) {
                foreach ($chunks as $chunk) {
                    $similarity = $this->embedder->cosineSimilarity(
                        $queryEmbedding,
                        $chunk->embedding
                    );
                    
                    if ($similarity >= $this->similarityThreshold) {
                        $chunk->similarity_score = $similarity;
                        $topResults->push($chunk);
                    }
                }
            });

        $results = $topResults
            ->sortByDesc('similarity_score')
            ->take($this->topK)
            ->values();

        Log::info("Vector search found {$results->count()} chunks above threshold {$this->similarityThreshold}");
        
        if ($results->isNotEmpty()) {
            $retrievedDocs = $results->map(fn($chunk) => [
                'chunk_id' => $chunk->id,
                'brain_id' => $chunk->brain_id,
                'title' => $chunk->brain->title ?? 'Unknown',
                'score' => round($chunk->similarity_score, 4),
            ])->unique('brain_id')->values();
            
            Log::info('Retrieved Documents:', $retrievedDocs->toArray());
        }
        
        return $results;
    }

    /**
     * Hybrid search combining vector similarity and keyword matching.
     * Uses RRF (Reciprocal Rank Fusion) to combine rankings.
     */
    public function hybridSearch(string $query, array $keywords = [], array $filters = []): Collection
    {
        // 1. Vector search
        $vectorResults = $this->search($query, $filters);
        
        // 2. Keyword search (fallback/boost)
        $keywordResults = $this->keywordSearch($keywords, $filters);
        
        // 3. Combine using RRF
        $combined = $this->reciprocalRankFusion($vectorResults, $keywordResults);
        
        $finalResults = $combined->take($this->topK);
        
        // Log hybrid search results with document titles
        if ($finalResults->isNotEmpty()) {
            $hybridDocs = $finalResults->map(function ($chunk) {
                return [
                    'title' => $chunk->brain->title ?? 'Unknown',
                    'rrf_score' => round($chunk->rrf_score ?? 0, 4),
                ];
            })->unique('title')->values();
            
            Log::info('Hybrid Search Retrieved Documents:', $hybridDocs->toArray());
        }
        
        return $finalResults;
    }

    /**
     * Keyword-based search on chunks (BM25-like scoring).
     */
    private function keywordSearch(array $keywords, array $filters = []): Collection
    {
        if (empty($keywords)) {
            return collect([]);
        }

        $chunksQuery = BrainChunk::with('brain:id,title,tag');
        
        // Apply tag filters
        if (!empty($filters['tags'])) {
            $chunksQuery->whereHas('brain', function ($q) use ($filters) {
                foreach ($filters['tags'] as $tag) {
                    $q->orWhereJsonContains('tag', $tag);
                }
            });
        }

        // Keyword matching
        $chunksQuery->where(function ($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                $q->orWhere('content', 'LIKE', "%{$keyword}%");
            }
        });

        $chunks = $chunksQuery->get();

        // Score by keyword frequency
        return $chunks->map(function ($chunk) use ($keywords) {
            $score = 0;
            $contentLower = strtolower($chunk->content);
            
            foreach ($keywords as $keyword) {
                $count = substr_count($contentLower, strtolower($keyword));
                $score += $count * 2; // Weight per occurrence
            }
            
            $chunk->keyword_score = $score;
            return $chunk;
        })->filter(fn($c) => $c->keyword_score > 0)
          ->sortByDesc('keyword_score')
          ->values();
    }

    /**
     * Reciprocal Rank Fusion to combine multiple rankings.
     * RRF(d) = Σ 1/(k + rank(d))
     */
    private function reciprocalRankFusion(Collection $vectorResults, Collection $keywordResults, int $k = 60): Collection
    {
        $scores = [];
        $chunkMap = [];

        // Score from vector results
        foreach ($vectorResults->values() as $rank => $chunk) {
            $id = $chunk->id;
            $scores[$id] = ($scores[$id] ?? 0) + (1 / ($k + $rank + 1));
            $chunkMap[$id] = $chunk;
        }

        // Score from keyword results
        foreach ($keywordResults->values() as $rank => $chunk) {
            $id = $chunk->id;
            $scores[$id] = ($scores[$id] ?? 0) + (1 / ($k + $rank + 1));
            if (!isset($chunkMap[$id])) {
                $chunkMap[$id] = $chunk;
            }
        }

        // Sort by combined score
        arsort($scores);

        $combined = collect([]);
        foreach ($scores as $id => $score) {
            $chunk = $chunkMap[$id];
            $chunk->rrf_score = $score;
            $combined->push($chunk);
        }

        return $combined;
    }

    /**
     * Apply MMR (Maximal Marginal Relevance) for diversity.
     * Balances relevance with diversity to avoid redundant results.
     */
    public function applyMMR(Collection $results, float $lambda = 0.7): Collection
    {
        if ($results->count() <= 1) {
            return $results;
        }

        $selected = collect([$results->first()]);
        $remaining = $results->slice(1);

        while ($selected->count() < $this->topK && $remaining->isNotEmpty()) {
            $bestScore = -999;
            $bestChunk = null;
            $bestIndex = null;

            foreach ($remaining as $index => $candidate) {
                // Relevance score
                $relevance = $candidate->similarity_score ?? $candidate->rrf_score ?? 0;

                // Max similarity to already selected (diversity penalty)
                $maxSim = 0;
                foreach ($selected as $sel) {
                    if ($candidate->embedding && $sel->embedding) {
                        $sim = $this->embedder->cosineSimilarity($candidate->embedding, $sel->embedding);
                        $maxSim = max($maxSim, $sim);
                    }
                }

                // MMR score
                $mmrScore = $lambda * $relevance - (1 - $lambda) * $maxSim;

                if ($mmrScore > $bestScore) {
                    $bestScore = $mmrScore;
                    $bestChunk = $candidate;
                    $bestIndex = $index;
                }
            }

            if ($bestChunk) {
                $selected->push($bestChunk);
                $remaining = $remaining->except($bestIndex)->values();
            } else {
                break;
            }
        }

        return $selected;
    }

    /**
     * Group chunks by their parent document and return document-level results.
     */
    public function groupByDocument(Collection $chunks): Collection
    {
        return $chunks->groupBy('brain_id')->map(function ($docChunks) {
            $brain = $docChunks->first()->brain;
            
            return [
                'brain' => $brain,
                'chunks' => $docChunks->sortBy('chunk_index')->values(),
                'max_score' => $docChunks->max('similarity_score') ?? $docChunks->max('rrf_score') ?? 0,
                'avg_score' => $docChunks->avg('similarity_score') ?? $docChunks->avg('rrf_score') ?? 0,
            ];
        })->sortByDesc('max_score')->values();
    }

    /**
     * Perform cosine similarity search scoped to specific brain_ids.
     * Used by JIT RAG to restrict search to only the pre-filtered documents.
     */
    public function scopedSearch(string $query, array $brainIds, int $limit = 5): Collection
    {
        if (empty($brainIds)) {
            return collect([]);
        }

        $queryEmbedding = $this->embedder->embed($query, useCache: true);

        if (!$queryEmbedding) {
            Log::warning("Failed to generate query embedding for scoped search");
            return collect([]);
        }

        $chunks = BrainChunk::whereIn('brain_id', $brainIds)
            ->whereNotNull('embedding')
            ->with('brain:id,title,tag')
            ->get();

        if ($chunks->isEmpty()) {
            return collect([]);
        }

        $scored = $chunks->map(function ($chunk) use ($queryEmbedding) {
            $chunk->similarity_score = $this->embedder->cosineSimilarity(
                $queryEmbedding,
                $chunk->embedding
            );
            return $chunk;
        });

        $results = $scored
            ->filter(fn($c) => $c->similarity_score >= $this->similarityThreshold)
            ->sortByDesc('similarity_score')
            ->take($limit)
            ->values();

        Log::info("Scoped vector search: {$results->count()} chunks from " . count($brainIds) . " brains");

        return $results;
    }

    public function setTopK(int $k): self
    {
        $this->topK = $k;
        return $this;
    }

    public function setSimilarityThreshold(float $threshold): self
    {
        $this->similarityThreshold = $threshold;
        return $this;
    }
}
