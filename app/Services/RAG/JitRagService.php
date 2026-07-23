<?php

namespace App\Services\RAG;

use App\Models\Brain;
use App\Models\BrainChunk;
use App\Services\KnowledgeGraph\EntityExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Just-In-Time Agentic RAG Service.
 *
 * Processes a local directory on-demand during a chat session instead of
 * embedding everything upfront. Yields SSE status events as a Generator
 * so the UI can show real-time progress.
 *
 * Pipeline:
 *   1. Detect & validate folder path
 *   2. Fast pre-filter (grep phase) — top 7 keyword-matched files
 *   3. Smart cache check via file_hash — skip unchanged files
 *   4. JIT chunk + embed for new/modified files
 *   5. Scoped vector search on those brain_ids only
 */
class JitRagService
{
    private EmbeddingService $embedder;
    private ChunkingService $chunker;
    private VectorSearchService $searcher;
    private EntityExtractor $entityExtractor;

    private const ALLOWED_EXTENSIONS = ['txt', 'md', 'pdf'];
    private const MAX_PRE_FILTER_FILES = 7;
    private const MAX_RETRIEVAL_CHUNKS = 5;

    public function __construct()
    {
        $this->embedder = new EmbeddingService();
        $this->chunker = new ChunkingService();
        $this->searcher = new VectorSearchService($this->embedder);
        $this->entityExtractor = new EntityExtractor();
    }

    /**
     * Detect a macOS directory path inside a user message.
     * Returns the path string or null if none found.
     */
    public static function detectFolderPath(string $message): ?string
    {
        // Match absolute macOS paths like /Users/..., /Volumes/..., /tmp/..., /var/...
        // or home-relative ~/...
        if (preg_match('#(?:^|\s)((?:/(?:Users|Volumes|tmp|var|opt|etc|Applications|Library|home)[^\s"\'<>|*?]+)|(?:~/[^\s"\'<>|*?]+))#', $message, $matches)) {
            $path = trim($matches[1]);

            // Expand tilde
            if (str_starts_with($path, '~/')) {
                $home = getenv('HOME') ?: posix_getpwuid(posix_getuid())['dir'] ?? '/tmp';
                $path = $home . substr($path, 1);
            }

            // Must actually be a directory
            if (is_dir($path)) {
                return rtrim($path, '/');
            }
        }

        return null;
    }

    /**
     * Execute the full JIT RAG pipeline.
     *
     * Yields associative arrays as SSE events:
     *   ['type' => 'meta', 'status' => '...', ...]
     *
     * Returns (via the final yield) the assembled context string
     * and the list of brain_ids used.
     *
     * @return \Generator yields SSE meta events; final return value is the result array
     */
    public function process(string $folderPath, string $userQuestion): \Generator
    {
        Log::info("JIT RAG: Starting pipeline", ['folder' => $folderPath, 'question' => substr($userQuestion, 0, 120)]);

        // --- PHASE 1: Scan folder ---
        yield ['type' => 'meta', 'status' => 'jit_scanning', 'message' => 'Scanning folder...'];

        $files = $this->scanDirectory($folderPath);

        if (empty($files)) {
            yield ['type' => 'meta', 'status' => 'jit_error', 'message' => 'No supported files (.txt, .md, .pdf) found in the folder.'];
            return ['context' => '', 'brain_ids' => [], 'success' => false];
        }

        yield ['type' => 'meta', 'status' => 'jit_scan_complete', 'message' => "Found " . count($files) . " files. Analyzing relevance..."];

        Log::info("JIT RAG: Scanned folder, found " . count($files) . " files");

        // --- PHASE 2: Fast pre-filter (grep phase) ---
        yield ['type' => 'meta', 'status' => 'jit_filtering', 'message' => 'Extracting keywords & filtering files...'];

        $keywords = $this->entityExtractor->extractKeywords($userQuestion);
        Log::info("JIT RAG: Extracted keywords", ['keywords' => $keywords]);

        if (empty($keywords)) {
            // Fallback: use all words > 3 chars from the question
            $keywords = array_values(array_filter(
                explode(' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', strtolower($userQuestion))),
                fn($w) => strlen($w) > 3
            ));
        }

        $rankedFiles = $this->grepRankFiles($files, $keywords);
        $topFiles = array_slice($rankedFiles, 0, self::MAX_PRE_FILTER_FILES);

        $fileNames = array_map(fn($f) => basename($f['path']), $topFiles);
        yield [
            'type' => 'meta',
            'status' => 'jit_filtered',
            'message' => 'Selected top ' . count($topFiles) . ' files: ' . implode(', ', $fileNames),
            'keywords' => $keywords,
        ];

        Log::info("JIT RAG: Pre-filtered to " . count($topFiles) . " files", ['files' => $fileNames]);

        // --- PHASE 3: Smart cache check & JIT embedding ---
        yield ['type' => 'meta', 'status' => 'jit_hashing', 'message' => 'Checking file cache...'];

        $brainIds = [];
        $newFileCount = 0;
        $cachedFileCount = 0;

        foreach ($topFiles as $index => $fileInfo) {
            $filePath = $fileInfo['path'];
            $fileHash = md5_file($filePath);
            $fileName = basename($filePath);

            // Check if this exact file version is already in the brain
            $existingBrain = Brain::where('file_path', $filePath)
                ->where('file_hash', $fileHash)
                ->where('is_indexed', true)
                ->first();

            if ($existingBrain) {
                $brainIds[] = $existingBrain->id;
                $cachedFileCount++;
                Log::info("JIT RAG: Cache HIT for {$fileName}");
                continue;
            }

            // File is new or modified — needs embedding
            $newFileCount++;
            $stepNum = $index + 1;
            yield [
                'type' => 'meta',
                'status' => 'jit_embedding',
                'message' => "Embedding document ({$stepNum}/" . count($topFiles) . "): {$fileName}",
            ];

            $brainId = $this->jitIndexFile($filePath, $fileHash, $fileName);

            if ($brainId) {
                $brainIds[] = $brainId;
                Log::info("JIT RAG: Indexed {$fileName} as brain_id={$brainId}");
            } else {
                Log::warning("JIT RAG: Failed to index {$fileName}");
            }
        }

        $cacheMsg = $cachedFileCount > 0
            ? "{$cachedFileCount} cached, {$newFileCount} newly embedded."
            : "{$newFileCount} documents embedded.";
        yield ['type' => 'meta', 'status' => 'jit_embed_complete', 'message' => $cacheMsg];

        if (empty($brainIds)) {
            yield ['type' => 'meta', 'status' => 'jit_error', 'message' => 'Failed to process any files.'];
            return ['context' => '', 'brain_ids' => [], 'success' => false];
        }

        // --- PHASE 4: Scoped vector retrieval ---
        yield ['type' => 'meta', 'status' => 'jit_searching', 'message' => 'Performing precision vector search...'];

        $context = $this->scopedRetrieval($userQuestion, $brainIds);

        if (empty($context)) {
            yield ['type' => 'meta', 'status' => 'jit_search_complete', 'message' => 'No highly relevant chunks found. Using full document context.'];
            $context = $this->fallbackDocumentContext($brainIds);
        } else {
            yield ['type' => 'meta', 'status' => 'jit_search_complete', 'message' => 'Found relevant document sections.'];
        }

        Log::info("JIT RAG: Pipeline complete", ['brain_ids' => $brainIds, 'context_length' => strlen($context)]);

        return [
            'context' => $context,
            'brain_ids' => $brainIds,
            'success' => true,
            'file_count' => count($topFiles),
            'cached_count' => $cachedFileCount,
            'embedded_count' => $newFileCount,
        ];
    }

    /**
     * Recursively scan a directory for allowed file types.
     */
    private function scanDirectory(string $path): array
    {
        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;

                $ext = strtolower($file->getExtension());
                if (!in_array($ext, self::ALLOWED_EXTENSIONS)) continue;

                // Skip hidden files/dirs and common junk
                $relativePath = $file->getPathname();
                if (preg_match('#/\.[^/]#', $relativePath)) continue;
                if ($file->getSize() === 0 || $file->getSize() > 10 * 1024 * 1024) continue; // Skip empty or >10MB

                $files[] = [
                    'path' => $file->getRealPath(),
                    'size' => $file->getSize(),
                    'ext'  => $ext,
                    'mtime' => $file->getMTime(),
                ];
            }
        } catch (\Exception $e) {
            Log::error("JIT RAG: Directory scan error: " . $e->getMessage());
        }

        return $files;
    }

    /**
     * Rank files by keyword match density (the "grep" phase).
     * Reads a sample of each file and counts keyword hits.
     */
    private function grepRankFiles(array $files, array $keywords): array
    {
        foreach ($files as &$file) {
            $score = 0;
            $sampleText = $this->readFileSample($file['path'], $file['ext']);
            $sampleLower = strtolower($sampleText);

            // Also score the filename
            $fileNameLower = strtolower(basename($file['path']));

            foreach ($keywords as $kw) {
                $kwLower = strtolower($kw);
                // Filename match = high weight
                if (str_contains($fileNameLower, $kwLower)) {
                    $score += 10;
                }
                // Content match
                $hits = substr_count($sampleLower, $kwLower);
                $score += min($hits, 10) * 2;
            }

            $file['relevance_score'] = $score;
        }
        unset($file);

        // Sort descending by score, then by mtime (newer first) as tiebreaker
        usort($files, function ($a, $b) {
            if ($a['relevance_score'] === $b['relevance_score']) {
                return $b['mtime'] <=> $a['mtime'];
            }
            return $b['relevance_score'] <=> $a['relevance_score'];
        });

        return $files;
    }

    /**
     * Read a text sample from a file for keyword matching.
     * For PDFs, extracts text via smalot/pdfparser.
     */
    private function readFileSample(string $path, string $ext, int $maxChars = 8000): string
    {
        try {
            if ($ext === 'pdf') {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($path);
                $text = $pdf->getText();
                return substr($text, 0, $maxChars);
            }

            // txt, md — just read the file
            $handle = fopen($path, 'r');
            if (!$handle) return '';
            $content = fread($handle, $maxChars);
            fclose($handle);
            return $content ?: '';
        } catch (\Exception $e) {
            Log::warning("JIT RAG: Could not read {$path}: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Read the full content of a file for indexing.
     */
    private function readFileContent(string $path, string $ext): string
    {
        try {
            if ($ext === 'pdf') {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($path);
                $text = $pdf->getText();
                return preg_replace("/\n\s*\n/", "\n\n", trim($text));
            }

            return file_get_contents($path) ?: '';
        } catch (\Exception $e) {
            Log::error("JIT RAG: Full read error for {$path}: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Index a single file into the brains/brain_chunks tables.
     * If a Brain with the same file_path already exists (but different hash),
     * update it in-place. Otherwise create a new record.
     */
    private function jitIndexFile(string $filePath, string $fileHash, string $fileName): ?int
    {
        try {
            DB::beginTransaction();

            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $content = $this->readFileContent($filePath, $ext);

            if (empty(trim($content))) {
                DB::rollBack();
                return null;
            }

            // Upsert brain record
            $brain = Brain::where('file_path', $filePath)->first();

            if ($brain) {
                $brain->chunks()->delete();
                $brain->update([
                    'content'    => $content,
                    'file_hash'  => $fileHash,
                    'is_indexed' => false,
                ]);
            } else {
                $brain = Brain::create([
                    'title'      => $fileName,
                    'content'    => $content,
                    'tag'        => json_encode(['jit']),
                    'type'       => $ext === 'pdf' ? 'pdf' : 'text',
                    'file_path'  => $filePath,
                    'file_hash'  => $fileHash,
                    'is_indexed' => false,
                ]);
            }

            // Chunk the document
            $chunks = $this->chunker->chunk($content, $fileName);

            if (empty($chunks)) {
                DB::rollBack();
                return null;
            }

            // Batch-embed all chunks
            $chunkTexts = array_map(fn($c) => $c['content'], $chunks);
            $embeddings = $this->embedder->embedBatch($chunkTexts);

            $insertData = [];
            $now = now();
            foreach ($chunks as $i => $chunk) {
                if (empty($embeddings[$i])) continue;

                $insertData[] = [
                    'brain_id'    => $brain->id,
                    'chunk_index' => $chunk['index'],
                    'content'     => $chunk['content'],
                    'embedding'   => json_encode($embeddings[$i]),
                    'token_count' => $chunk['token_count'],
                    'chunk_type'  => $chunk['type'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }

            if (!empty($insertData)) {
                // Bulk insert in batches of 50
                foreach (array_chunk($insertData, 50) as $batch) {
                    BrainChunk::insert($batch);
                }
            }

            // Generate document-level embedding
            $docText = $brain->title . "\n\n" . ($chunks[0]['content'] ?? '');
            $docEmbedding = $this->embedder->embed($docText);

            $brain->update([
                'embedding'  => $docEmbedding,
                'is_indexed' => true,
                'indexed_at' => now(),
            ]);

            DB::commit();

            return $brain->id;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("JIT RAG: Index error for {$fileName}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Perform vector search scoped to specific brain_ids only.
     */
    private function scopedRetrieval(string $query, array $brainIds): string
    {
        $queryEmbedding = $this->embedder->embed($query);

        if (!$queryEmbedding) {
            Log::warning("JIT RAG: Failed to embed query for scoped search");
            return '';
        }

        $chunks = BrainChunk::whereIn('brain_id', $brainIds)
            ->whereNotNull('embedding')
            ->with('brain:id,title')
            ->get();

        if ($chunks->isEmpty()) {
            return '';
        }

        // Calculate cosine similarity for each chunk
        $scored = $chunks->map(function ($chunk) use ($queryEmbedding) {
            $chunk->similarity_score = $this->embedder->cosineSimilarity(
                $queryEmbedding,
                $chunk->embedding
            );
            return $chunk;
        });

        $threshold = config('services.rag.similarity_threshold', 0.45);
        $results = $scored
            ->filter(fn($c) => $c->similarity_score >= $threshold)
            ->sortByDesc('similarity_score')
            ->take(self::MAX_RETRIEVAL_CHUNKS)
            ->values();

        if ($results->isEmpty()) {
            return '';
        }

        Log::info("JIT RAG: Scoped search found {$results->count()} chunks above threshold");

        // Format context
        $context = "=== JIT RETRIEVED KNOWLEDGE (Local Folder RAG) ===\n\n";
        $totalTokens = 0;

        foreach ($results as $chunk) {
            $score = round($chunk->similarity_score, 3);
            $docTitle = $chunk->brain->title ?? 'Unknown';

            $context .= "--- [Relevance: {$score}] {$docTitle} ---\n";
            $context .= $chunk->content . "\n\n";
            $totalTokens += $chunk->token_count;
        }

        $context .= "=================================\n";
        $context .= "[JIT Retrieved {$results->count()} chunks, ~{$totalTokens} tokens from local folder]\n\n";

        return $context;
    }

    /**
     * Fallback: build context from the first chunks of each brain document
     * when vector search finds nothing above threshold.
     */
    private function fallbackDocumentContext(array $brainIds): string
    {
        $brains = Brain::whereIn('id', $brainIds)->get();

        if ($brains->isEmpty()) {
            return '';
        }

        $context = "=== JIT DOCUMENT CONTEXT (Local Folder) ===\n\n";
        $maxCharsPerDoc = 3000;

        foreach ($brains as $brain) {
            $chunks = $brain->chunks()->orderBy('chunk_index')->take(3)->get();

            $context .= "--- [Document: {$brain->title}] ---\n";
            $charCount = 0;

            foreach ($chunks as $chunk) {
                if ($charCount + strlen($chunk->content) > $maxCharsPerDoc) {
                    $remaining = $maxCharsPerDoc - $charCount;
                    if ($remaining > 200) {
                        $context .= substr($chunk->content, 0, $remaining) . "\n[...TRUNCATED...]\n";
                    }
                    break;
                }
                $context .= $chunk->content . "\n\n";
                $charCount += strlen($chunk->content);
            }
        }

        $context .= "=================================\n\n";
        return $context;
    }
}
