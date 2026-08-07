<?php

namespace App\Services\RAG;

use Illuminate\Support\Facades\Log;

/**
 * Document Chunking Service implementing O'Reilly RAG patterns.
 * 
 * Strategies:
 * 1. Semantic Chunking - Split by headers/sections
 * 2. Fixed-size with Overlap - Configurable chunk size with overlap
 * 3. Code-aware Chunking - Preserve code blocks as single units
 */
class ChunkingService
{
    private int $chunkSize;
    private int $chunkOverlap;
    private int $minChunkSize;

    public function __construct()
    {
        $this->chunkSize = config('services.rag.chunk_size', 1000);      // ~250 tokens
        $this->chunkOverlap = config('services.rag.chunk_overlap', 200);  // ~50 tokens overlap
        $this->minChunkSize = config('services.rag.min_chunk_size', 100); // Min chars for a chunk
    }

    /**
     * Chunk a document using semantic + fixed-size hybrid approach.
     * Returns array of chunks with metadata.
     */
    public function chunk(string $content, string $title = ''): array
    {
        // First, clean the HTML content
        $cleanContent = $this->cleanHtml($content);
        
        // Split into semantic sections first (by headers)
        $sections = $this->splitBySections($cleanContent);
        
        $chunks = [];
        $chunkIndex = 0;
        
        foreach ($sections as $section) {
            // If section is small enough, keep as single chunk
            if (strlen($section['content']) <= $this->chunkSize) {
                if (strlen($section['content']) >= $this->minChunkSize) {
                    $chunks[] = [
                        'index' => $chunkIndex++,
                        'content' => $this->formatChunk($section['content'], $section['header'], $title),
                        'type' => $section['type'],
                        'token_count' => $this->estimateTokens($section['content']),
                    ];
                }
            } else {
                // Large section: split with overlap, preserving code blocks
                $subChunks = $this->splitWithOverlap($section['content'], $section['header'], $title);
                foreach ($subChunks as $subChunk) {
                    $subChunk['index'] = $chunkIndex++;
                    $subChunk['type'] = $section['type'];
                    $chunks[] = $subChunk;
                }
            }
        }
        
        Log::info("Chunked document '{$title}' into " . count($chunks) . " chunks");
        
        return $chunks;
    }

    /**
     * Clean HTML content and convert to plain text with preserved structure.
     */
    private function cleanHtml(string $html): string
    {
        // Preserve code blocks with special markers
        $codeBlocks = [];
        $html = preg_replace_callback('/<pre><code[^>]*>(.*?)<\/code><\/pre>/s', function($m) use (&$codeBlocks) {
            $idx = count($codeBlocks);
            $codeBlocks[$idx] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return "\n[[CODE_BLOCK_{$idx}]]\n";
        }, $html);

        // Convert headers to markdown
        $html = preg_replace('/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/i', "\n" . str_repeat('#', 2) . " $2\n", $html);
        
        // Convert lists
        $html = preg_replace('/<li[^>]*>/i', "\n• ", $html);
        
        // Convert paragraphs and breaks
        $html = preg_replace('/<\/p>|<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<p[^>]*>/i', "\n", $html);
        
        // Strip remaining tags
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Restore code blocks
        foreach ($codeBlocks as $idx => $code) {
            $text = str_replace("[[CODE_BLOCK_{$idx}]]", "\n```\n" . trim($code) . "\n```\n", $text);
        }
        
        // Clean up whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        
        return trim($text);
    }

    /**
     * Split content by semantic sections (headers).
     */
    private function splitBySections(string $content): array
    {
        $sections = [];
        
        // Split by headers (## patterns)
        $parts = preg_split('/(\n##\s+[^\n]+)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        
        $currentHeader = '';
        $currentContent = '';
        
        foreach ($parts as $part) {
            if (preg_match('/^\n##\s+(.+)$/', $part, $match)) {
                // Save previous section
                if (!empty(trim($currentContent))) {
                    $sections[] = [
                        'header' => $currentHeader,
                        'content' => trim($currentContent),
                        'type' => $this->detectSectionType($currentContent),
                    ];
                }
                $currentHeader = trim($match[1]);
                $currentContent = '';
            } else {
                $currentContent .= $part;
            }
        }
        
        // Don't forget the last section
        if (!empty(trim($currentContent))) {
            $sections[] = [
                'header' => $currentHeader,
                'content' => trim($currentContent),
                'type' => $this->detectSectionType($currentContent),
            ];
        }
        
        // If no sections found, treat entire content as one section
        if (empty($sections)) {
            $sections[] = [
                'header' => '',
                'content' => trim($content),
                'type' => $this->detectSectionType($content),
            ];
        }
        
        return $sections;
    }

    /**
     * Split large text with overlap, preserving code blocks.
     */
    private function splitWithOverlap(string $text, string $header = '', string $title = ''): array
    {
        $chunks = [];
        
        // First, identify and protect code blocks
        $codeBlocks = [];
        $text = preg_replace_callback('/```[\s\S]*?```/', function($m) use (&$codeBlocks) {
            $idx = count($codeBlocks);
            $codeBlocks[$idx] = $m[0];
            return "[[PROTECTED_CODE_{$idx}]]";
        }, $text);
        
        // Split into sentences/paragraphs
        $paragraphs = preg_split('/\n\n+/', $text);
        
        $currentChunk = '';
        $previousOverlap = '';
        
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) continue;
            
            // Check if adding this paragraph exceeds chunk size
            $testChunk = $currentChunk . "\n\n" . $para;
            
            if (strlen($testChunk) > $this->chunkSize && !empty($currentChunk)) {
                // Save current chunk
                $chunkContent = $previousOverlap . $currentChunk;
                $chunkContent = $this->restoreCodeBlocks($chunkContent, $codeBlocks);
                
                $chunks[] = [
                    'content' => $this->formatChunk($chunkContent, $header, $title),
                    'token_count' => $this->estimateTokens($chunkContent),
                ];
                
                // Keep overlap for next chunk
                $previousOverlap = $this->getOverlapText($currentChunk);
                $currentChunk = $para;
            } else {
                $currentChunk = trim($testChunk);
            }
        }
        
        // Don't forget the last chunk
        if (!empty(trim($currentChunk))) {
            $chunkContent = $previousOverlap . $currentChunk;
            $chunkContent = $this->restoreCodeBlocks($chunkContent, $codeBlocks);
            
            if (strlen($chunkContent) >= $this->minChunkSize) {
                $chunks[] = [
                    'content' => $this->formatChunk($chunkContent, $header, $title),
                    'token_count' => $this->estimateTokens($chunkContent),
                ];
            }
        }
        
        return $chunks;
    }

    /**
     * Restore protected code blocks.
     */
    private function restoreCodeBlocks(string $text, array $codeBlocks): string
    {
        foreach ($codeBlocks as $idx => $code) {
            $text = str_replace("[[PROTECTED_CODE_{$idx}]]", $code, $text);
        }
        return $text;
    }

    /**
     * Get overlap text from the end of a chunk.
     */
    private function getOverlapText(string $text): string
    {
        if (strlen($text) <= $this->chunkOverlap) {
            return $text . "\n\n";
        }
        
        $overlap = substr($text, -$this->chunkOverlap);
        
        // Try to break at sentence boundary
        $lastPeriod = strrpos($overlap, '. ');
        if ($lastPeriod !== false && $lastPeriod > 20) {
            $overlap = substr($overlap, $lastPeriod + 2);
        }
        
        return "[...] " . trim($overlap) . "\n\n";
    }

    /**
     * Format chunk with context (header and document title).
     */
    private function formatChunk(string $content, string $header = '', string $title = ''): string
    {
        $formatted = '';
        
        if (!empty($title)) {
            $formatted .= "[Document: {$title}]\n";
        }
        
        if (!empty($header)) {
            $formatted .= "[Section: {$header}]\n\n";
        }
        
        $formatted .= trim($content);
        
        return $formatted;
    }

    /**
     * Detect the type of content in a section.
     */
    private function detectSectionType(string $content): string
    {
        if (preg_match('/```|<code>|<pre>/', $content)) {
            return 'code';
        }
        if (preg_match('/^##?\s+/', $content)) {
            return 'header';
        }
        return 'text';
    }

    /**
     * Estimate token count (rough: 1 token ≈ 4 chars for English).
     */
    private function estimateTokens(string $text): int
    {
        return intval(strlen($text) / 4);
    }
}
