<?php

namespace App\Services\RAG;

use App\Models\Brain;
use App\Models\BrainChunk;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * RAG Manager - Orchestrates the full RAG pipeline.
 * 
 * Implements O'Reilly RAG patterns:
 * 1. Document Indexing (chunk + embed)
 * 2. Query Processing (embed query)
 * 3. Retrieval (hybrid search)
 * 4. Context Assembly (format for LLM)
 */
class RAGManager
{
    private EmbeddingService $embedder;
    private ChunkingService $chunker;
    private VectorSearchService $searcher;

    public function __construct()
    {
        $this->embedder = new EmbeddingService();
        $this->chunker = new ChunkingService();
        $this->searcher = new VectorSearchService($this->embedder);
    }

    /**
     * Index a single brain document.
     * Chunks the document and generates embeddings.
     */
    public function indexDocument(Brain $brain): bool
    {
        Log::info("Indexing document: {$brain->title} (ID: {$brain->id})");

        try {
            DB::beginTransaction();

            // 1. Delete existing chunks
            $brain->chunks()->delete();

            // 2. Chunk the document
            $chunks = $this->chunker->chunk($brain->content, $brain->title);

            if (empty($chunks)) {
                Log::warning("No chunks generated for document {$brain->id}");
                DB::rollBack();
                return false;
            }

            // 3. Generate embeddings for each chunk
            $createdChunks = [];
            foreach ($chunks as $chunk) {
                $embedding = $this->embedder->embed($chunk['content']);
                
                if (!$embedding) {
                    Log::warning("Failed to generate embedding for chunk {$chunk['index']} of document {$brain->id}");
                    continue;
                }

                $createdChunks[] = BrainChunk::create([
                    'brain_id' => $brain->id,
                    'chunk_index' => $chunk['index'],
                    'content' => $chunk['content'],
                    'embedding' => $embedding,
                    'token_count' => $chunk['token_count'],
                    'chunk_type' => $chunk['type'],
                ]);
            }

            // 4. Generate document-level embedding (from title + first chunk)
            $docText = $brain->title . "\n\n" . ($createdChunks[0]->content ?? '');
            $docEmbedding = $this->embedder->embed($docText);

            // 5. Update brain document
            $brain->update([
                'embedding' => $docEmbedding,
                'is_indexed' => true,
                'indexed_at' => now(),
            ]);

            DB::commit();

            Log::info("Successfully indexed document {$brain->id} with " . count($createdChunks) . " chunks");
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to index document {$brain->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Index all unindexed documents.
     */
    public function indexAll(bool $force = false): array
    {
        $query = Brain::query();
        
        if (!$force) {
            $query->where('is_indexed', false)->orWhereNull('is_indexed');
        }

        $brains = $query->get();
        $results = ['success' => 0, 'failed' => 0, 'total' => $brains->count()];

        foreach ($brains as $brain) {
            if ($this->indexDocument($brain)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Retrieve relevant context for a query.
     * This is the main RAG retrieval method.
     */
    public function retrieve(string $query, array $options = []): array
    {
        $tags = $options['tags'] ?? ['general'];
        $topK = $options['top_k'] ?? 5;
        $useHybrid = $options['hybrid'] ?? true;
        $keywords = $options['keywords'] ?? [];

        // Check if we have indexed documents
        $indexedCount = BrainChunk::whereNotNull('embedding')->count();
        
        if ($indexedCount === 0) {
            Log::warning("No indexed documents found for RAG search");
            return [
                'chunks' => collect([]),
                'documents' => collect([]),
                'method' => 'none',
                'query_embedding' => null,
            ];
        }

        $this->searcher->setTopK($topK);

        // Perform search
        if ($useHybrid && !empty($keywords)) {
            $chunks = $this->searcher->hybridSearch($query, $keywords, ['tags' => $tags]);
            $method = 'hybrid';
        } else {
            $chunks = $this->searcher->search($query, ['tags' => $tags]);
            $method = 'vector';
        }

        // Apply MMR for diversity if we have many results
        if ($chunks->count() > 3) {
            $chunks = $this->searcher->applyMMR($chunks, 0.7);
        }

        // Group by document
        $documents = $this->searcher->groupByDocument($chunks);

        return [
            'chunks' => $chunks,
            'documents' => $documents,
            'method' => $method,
            'total_chunks_searched' => $indexedCount,
        ];
    }

    /**
     * Format retrieved chunks into context for the LLM.
     */
    public function formatContext(array $retrievalResult, int $maxTokens = 3000): string
    {
        $chunks = $retrievalResult['chunks'];
        
        if ($chunks->isEmpty()) {
            return "";
        }

        $context = "=== RETRIEVED KNOWLEDGE (RAG) ===\n\n";
        $currentTokens = 0;
        $addedChunks = 0;

        foreach ($chunks as $chunk) {
            $chunkTokens = $chunk->token_count ?? intval(strlen($chunk->content) / 4);
            
            if ($currentTokens + $chunkTokens > $maxTokens && $addedChunks > 0) {
                break;
            }

            $score = round($chunk->similarity_score ?? $chunk->rrf_score ?? 0, 3);
            $docTitle = $chunk->brain->title ?? 'Unknown';
            
            $context .= "--- [Relevance: {$score}] {$docTitle} ---\n";
            $context .= $chunk->content . "\n\n";
            
            $currentTokens += $chunkTokens;
            $addedChunks++;
        }

        $context .= "=================================\n";
        $context .= "[Retrieved {$addedChunks} chunks, ~{$currentTokens} tokens]\n\n";

        return $context;
    }

    /**
     * Check if the RAG system is ready (embeddings available).
     */
    public function isReady(): bool
    {
        return $this->embedder->isAvailable();
    }

    /**
     * Get indexing statistics.
     */
    public function getStats(): array
    {
        return [
            'total_documents' => Brain::count(),
            'indexed_documents' => Brain::where('is_indexed', true)->count(),
            'total_chunks' => BrainChunk::count(),
            'chunks_with_embeddings' => BrainChunk::whereNotNull('embedding')->count(),
            'embedding_model' => $this->embedder->getModel(),
            'embedding_dimensions' => $this->embedder->getDimensions(),
        ];
    }
}
