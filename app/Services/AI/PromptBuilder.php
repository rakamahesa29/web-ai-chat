<?php

namespace App\Services\AI;

use App\Models\Room;
use App\Services\RAG\RAGManager;
use App\Services\KnowledgeGraph\GraphManager;
use Illuminate\Support\Collection;

class PromptBuilder
{
    private ?RAGManager $ragManager = null;
    private ?QueryClassifier $queryClassifier = null;
    private ?GraphManager $graphManager = null;

    /**
     * Build payload for LLM based on Room data and message history.
     * Now with intelligent query classification for smarter RAG usage.
     */
    public function build(Room $room, Collection $recentMessages, string $modelName = null, array $options = []): array
    {
        $payload = [];

        // 1. Resolve Active Model Name
        $modelMapping = [
            'ollama'    => 'services.ollama.model',
            'ollama_cloud'    => 'services.ollama_cloud.model',
            'deepseek'  => 'services.deepseek.model',
        ];

        if (isset($modelMapping[$modelName])) {
            $activeModel = config($modelMapping[$modelName]);
        } else {
            $activeModel = $modelName ?? config('services.ollama.model', 'gemma4:12b-mlx');
        }
        
        // 2. Determine Persona & System Rules
        $systemPromptKey = in_array($modelName, ['ollama', 'ollama_cloud', 'deepseek']) ? $modelName : 'ollama';
        $coreRules = config("services.ai.system_prompts.{$systemPromptKey}", 'You are a helpful AI assistant.');

        \Log::info("PromptBuilder is loading rules for model: {$activeModel}");
        
        $personaKey = !empty($options['persona_override']) 
            ? $options['persona_override'] 
            : ($room->persona ?? 'general');
        $personaStyle = config("services.ai.personas.{$personaKey}", config('services.ai.personas.general', ''));

        $latestUserMsg = $recentMessages->last() ? $recentMessages->last()->content : '';
        
        // =============================================================
        // JIT RAG CONTEXT: If provided from ChatProcessor, inject directly
        // and skip the normal RAG pipeline for this query
        // =============================================================
        $jitContext = $options['jit_context'] ?? '';
        if (!empty($jitContext)) {
            \Log::info("PromptBuilder: Using JIT RAG context (" . strlen($jitContext) . " chars)");
            return $this->buildJitRagPayload(
                $room, $recentMessages, $coreRules, $personaStyle, $modelName, $jitContext
            );
        }

        // =============================================================
        // SMART QUERY CLASSIFICATION (New Feature)
        // Determines if we should use RAG, AI knowledge, or suggest web search
        // =============================================================
        if (!$this->queryClassifier) {
            $this->queryClassifier = new QueryClassifier();
        }
        
        $classification = $this->queryClassifier->classify($latestUserMsg, $personaKey);
        
        \Log::info("QueryClassifier Result", [
            'type' => $classification['type'],
            'use_rag' => $classification['use_rag'],
            'suggest_web_search' => $classification['suggest_web_search'],
            'reason' => $classification['reason'],
        ]);
        
        // Handle GENERAL queries - use AI model knowledge directly
        if ($classification['type'] === QueryClassifier::TYPE_GENERAL && !$classification['use_rag']) {
            \Log::info("Query classified as GENERAL - using AI model knowledge directly");
            
            return $this->buildGeneralKnowledgePayload(
                $room, 
                $recentMessages, 
                $coreRules, 
                $personaStyle, 
                $modelName,
                $classification
            );
        }
        
        // Handle LATEST_DATA queries - may suggest web search
        if ($classification['type'] === QueryClassifier::TYPE_LATEST_DATA) {
            \Log::info("Query classified as LATEST_DATA - will check internal data then suggest web search if needed");
        }
        
        // =============================================================
        // TRY VECTOR RAG FIRST (O'Reilly Pattern)
        // Falls back to keyword search if RAG not available/indexed
        // =============================================================
        $ragResult = $this->tryVectorRAG($latestUserMsg, $personaKey);
        
        if ($ragResult['success']) {
            $brainKnowledge = $ragResult['context'];
            $usedInternalData = true;
            $matchedKeywords = $ragResult['keywords'];
            
            \Log::info("RAG Search: Used vector search, found {$ragResult['chunk_count']} chunks");
            
            goto assembleSystemPrompt;
        }
        
        \Log::info("RAG Search: Falling back to keyword search");
        
        // --- RAG OPTIMIZATION: KEYWORD EXTRACTION & SEMANTIC APPROXIMATION ---
        // 1. Bersihkan teks dan ubah ke huruf kecil
        $cleanText = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', strtolower($latestUserMsg));
        $words = array_unique(explode(' ', $cleanText));
        // 2. Buang Stopwords (Indonesian + English)
        $stopwords = [
            // Indonesian
            'yang', 'dan', 'di', 'ke', 'dari', 'ini', 'itu', 'untuk', 'dengan', 'dalam', 'pada', 'adalah', 'sebagai', 'apakah', 'bagaimana', 'tolong', 'saya', 'kamu', 'bisa', 'tidak', 'ada', 'apa', 'contoh', 'tentang', 'ingin', 'dulu', 'juga', 'atau', 'jika', 'maka', 'akan', 'sudah', 'belum', 'masih', 'hanya', 'semua', 'setiap', 'lain',
            // English
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'can', 'you', 'your', 'yours', 'me', 'my', 'mine', 'we', 'our', 'ours', 'they', 'their', 'theirs', 'he', 'she', 'it', 'his', 'her', 'its', 'this', 'that', 'these', 'those', 'what', 'which', 'who', 'whom', 'when', 'where', 'why', 'how', 'all', 'each', 'every', 'both', 'few', 'more', 'most', 'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very', 'just', 'but', 'and', 'or', 'if', 'because', 'as', 'until', 'while', 'of', 'at', 'by', 'for', 'with', 'about', 'against', 'between', 'into', 'through', 'during', 'before', 'after', 'above', 'below', 'to', 'from', 'up', 'down', 'in', 'out', 'on', 'off', 'over', 'under', 'again', 'further', 'then', 'once', 'here', 'there', 'any', 'please', 'using', 'use'
        ];
        $keywords = array_filter($words, function($word) use ($stopwords) {
            return strlen($word) > 2 && !in_array($word, $stopwords); // Ubah dari > 3 menjadi > 2 agar kata "acf" bisa masuk
        });
        // 3. SYNONYM MAPPING + ENGLISH-INDONESIAN TRANSLATION (Critical for bilingual RAG)
        $synonyms = [
            // Folder/Directory
            'folder' => 'direktori',
            'direktori' => 'folder',
            'directory' => 'direktori',
            'struktur' => 'structure',
            'structure' => 'struktur',
            
            // Create/Build/Make
            'bikin' => 'pembuatan',
            'buat' => 'pembuatan',
            'buatkan' => 'pembuatan',
            'create' => 'pembuatan',
            'build' => 'pembuatan',
            'make' => 'pembuatan',
            'pembuatan' => 'create',
            
            // Guide/Tutorial
            'jelaskan' => 'panduan',
            'berikan' => 'panduan',
            'guide' => 'panduan',
            'tutorial' => 'panduan',
            'panduan' => 'guide',
            
            // Theme
            'theme' => 'tema',
            'tema' => 'theme',
            'themes' => 'tema',
            
            // Plugin
            'plugin' => 'plugins',
            'plugins' => 'plugin',
            
            // WordPress specific
            'wordpress' => 'wp',
            'wp' => 'wordpress',
            'woocommerce' => 'woo',
            'woo' => 'woocommerce',
            
            // Custom
            'custom' => 'kustom',
            'kustom' => 'custom',
            
            // Block
            'block' => 'blok',
            'blok' => 'block',
            'blocks' => 'blok',
            
            // Integration/API
            'integration' => 'integrasi',
            'integrasi' => 'integration',
            'api' => 'endpoint',
            
            // Email
            'email' => 'emails',
            'emails' => 'email',
        ];
        $expandedKeywords = [];
        foreach ($keywords as $kw) {
            $expandedKeywords[] = $kw;
            // Add direct synonym
            if (isset($synonyms[$kw])) {
                $expandedKeywords[] = $synonyms[$kw];
            }
            // Also check for partial matches in synonym keys (for compound words)
            foreach ($synonyms as $synKey => $synValue) {
                if (str_contains($kw, $synKey) || str_contains($synKey, $kw)) {
                    $expandedKeywords[] = $synValue;
                }
            }
        }
        
        // Add some hardcoded key terms that are always relevant for certain topics
        $topicBoosts = [
            'acf' => ['acf', 'blocks', 'blok', 'custom', 'kustom', 'fields'],
            'tailwind' => ['tailwind', 'css', 'utility', 'styling'],
            'wordpress' => ['wordpress', 'wp', 'theme', 'tema', 'plugin'],
            'woocommerce' => ['woocommerce', 'woo', 'cart', 'checkout', 'order', 'email'],
        ];
        
        foreach ($topicBoosts as $trigger => $boostWords) {
            if (in_array($trigger, array_map('strtolower', $expandedKeywords))) {
                $expandedKeywords = array_merge($expandedKeywords, $boostWords);
            }
        }
        
        // Gunakan expandedKeywords untuk pencarian
        $keywords = array_unique($expandedKeywords);
        
        \Log::info("Extracted Keywords for RAG: " . implode(', ', $keywords));
        
        // Query database with flexible tag matching (persona + custom tags)
        $brainQuery = \App\Models\Brain::where(function($query) use ($personaKey, $keywords) {
            // Always include 'general' tag and current persona
            $query->whereJsonContains('tag', 'general')
                ->orWhereJsonContains('tag', $personaKey);
            
            // Also search by custom tags that match keywords (e.g. wordpress_70, collaborative_editing)
            foreach ($keywords as $keyword) {
                // Convert keyword to tag format (e.g. "wordpress" -> "wordpress", "real time" -> "real_time")
                $tagFormat = strtolower(str_replace(' ', '_', $keyword));
                $query->orWhereJsonContains('tag', $tagFormat);
            }
        });
        
        // Pencarian awal (Ambil agak banyak untuk di-score)
        $potentialBrains = $brainQuery->where(function($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                $q->orWhere('title', 'LIKE', "%{$keyword}%")
                ->orWhere('content', 'LIKE', "%{$keyword}%");
            }
        })->latest()->take(20)->get();
        // IMPROVED SCORING with better title weighting, tag matching, and bilingual support
        $scoredBrains = $potentialBrains->map(function ($brain) use ($keywords) {
            $score = 0;
            $contentLower = strtolower($brain->content);
            $titleLower = strtolower($brain->title);
            $matchedKeywordsCount = 0;
            $titleMatchCount = 0;
            $contentMatchCount = 0;
            $tagMatchCount = 0;
            
            // Get brain's tags as array
            $brainTags = is_array($brain->tag) ? array_map('strtolower', $brain->tag) : [];
            
            foreach ($keywords as $keyword) {
                $kwLower = strtolower($keyword);
                if (strlen($kwLower) < 2) continue; // Skip very short keywords
                
                $isMatched = false;
                
                // TAG match - very high priority (20 points) for custom tags
                $tagFormat = str_replace(' ', '_', $kwLower);
                foreach ($brainTags as $brainTag) {
                    if (str_contains($brainTag, $tagFormat) || str_contains($tagFormat, $brainTag)) {
                        $score += 20; // High score for tag match
                        $tagMatchCount++;
                        $isMatched = true;
                        break;
                    }
                }
                
                // Title match - highest priority (15 points)
                if (str_contains($titleLower, $kwLower)) {
                    $score += 15;
                    $titleMatchCount++;
                    $isMatched = true;
                }
                
                // Content match (2 points, but count occurrences for density)
                if (str_contains($contentLower, $kwLower)) {
                    $occurrences = substr_count($contentLower, $kwLower);
                    $score += min($occurrences, 5) * 2; // Max 10 points per keyword from content
                    $contentMatchCount++;
                    $isMatched = true;
                }
                
                if ($isMatched) {
                    $matchedKeywordsCount++;
                }
            }
            
            // BONUS: Multiple title matches (indicates high relevance)
            if ($titleMatchCount >= 3) {
                $score += 20; // Bonus for 3+ title keyword matches
            } elseif ($titleMatchCount >= 2) {
                $score += 10; // Bonus for 2 title keyword matches
            }
            
            // BONUS: Tag matches (custom tags are highly relevant)
            if ($tagMatchCount >= 2) {
                $score += 15; // Bonus for multiple tag matches
            }
            
            // PENALTY: Only apply if very few matches AND no title/tag matches
            if ($titleMatchCount == 0 && $tagMatchCount == 0 && $contentMatchCount < 3) {
                $score = intval($score * 0.3); // Reduce score significantly but don't zero it
            }
            
            $brain->relevance_score = $score;
            $brain->title_matches = $titleMatchCount;
            $brain->content_matches = $contentMatchCount;
            $brain->tag_matches = $tagMatchCount;
            return $brain;
        });
        // =============================================================
        // SMART DOCUMENT SELECTION (Optimized for Local LLM with 24GB RAM)
        // =============================================================
        $topScore = $scoredBrains->max('relevance_score');
        $sortedBrains = $scoredBrains->sortByDesc('relevance_score');
        
        // Calculate score gap to determine confidence level
        $topBrain = $sortedBrains->first();
        $secondBrain = $sortedBrains->skip(1)->first();
        $scoreGap = $secondBrain ? ($topBrain->relevance_score - $secondBrain->relevance_score) : 999;
        $scoreRatio = $secondBrain && $secondBrain->relevance_score > 0 
            ? $topBrain->relevance_score / $secondBrain->relevance_score 
            : 999;
        
        // SMART DOCUMENT COUNT (Increased for complex queries):
        // - If top doc has 3x+ score of second doc, use ONLY the top doc (very high confidence)
        // - If gap is large, use top 2 docs
        // - Otherwise allow up to 5 docs for comprehensive answers
        $docLimit = 5; // Default: max 5 documents for better coverage
        if ($scoreRatio >= 3.0 || $scoreGap >= 50) {
            $docLimit = 1; // Very high confidence: single document is sufficient
        } elseif ($scoreRatio >= 2.0 || $scoreGap >= 30) {
            $docLimit = 2; // High confidence: top 2 documents
        } elseif ($scoreRatio >= 1.5 || $scoreGap >= 15) {
            $docLimit = 3; // Moderate confidence: top 3 documents
        }
        
        $minThreshold = max(15, $topScore * 0.3); // Stricter threshold
        
        $brains = $sortedBrains->filter(function($brain) use ($minThreshold) {
            return $brain->relevance_score >= $minThreshold;
        })->take($docLimit)->values();
        
        \Log::info("RAG Selection: TopScore={$topScore}, Gap={$scoreGap}, Ratio=" . round($scoreRatio, 2) . ", DocLimit={$docLimit}");
        foreach ($sortedBrains->take(5) as $b) {
            $status = $b->relevance_score >= $minThreshold ? '✓' : '✗';
            \Log::info("  {$status} [{$b->relevance_score}] {$b->title}");
        }
        
        // Log retrieved document titles array with scores (for easy debugging)
        if ($brains->isNotEmpty()) {
            $retrievedDocs = $brains->map(fn($b) => [
                'id' => $b->id,
                'title' => $b->title,
                'score' => $b->relevance_score,
            ])->toArray();
            \Log::info('Keyword Search Retrieved Documents:', $retrievedDocs);
        } else {
            \Log::info('Keyword Search Retrieved Documents: (none found)');
        }
        
        $brainKnowledge = "";
        $usedInternalData = false;
        $matchedKeywords = [];

        if ($brains->isNotEmpty()) {
            $usedInternalData = true;
            $matchedKeywords = array_values($keywords);
            
            if ($brains->count() === 1) {
                $brainKnowledge .= "=== INTERNAL KNOWLEDGE BASE ===\n";
                $brainKnowledge .= "ATURAN: Jawab HANYA berdasarkan dokumen referensi di bawah. JANGAN menambahkan informasi di luar dokumen.\n\n";
            } else {
                $brainKnowledge .= "=== INTERNAL KNOWLEDGE BASE ===\n";
                $brainKnowledge .= "ATURAN: Pilih SATU dokumen yang paling relevan. JANGAN mencampur konteks antar dokumen.\n\n";
            }
            
            $maxContextChars = 50000;
            $currentContextChars = 0;

            foreach ($brains as $brain) {
                $cleanContent = $this->cleanHtmlContent($brain->content);
                $extractedContent = $this->extractRelevantSections($cleanContent, $keywords);
                $contentLength = strlen($extractedContent);
                
                if ($currentContextChars + $contentLength > $maxContextChars) {
                    $remainingChars = $maxContextChars - $currentContextChars;
                    if ($remainingChars > 500) {
                        $brainKnowledge .= "--- [DOKUMEN: {$brain->title}] ---\n";
                        $brainKnowledge .= substr($extractedContent, 0, $remainingChars) . "\n[...TRUNCATED...]\n\n";
                        $currentContextChars += $remainingChars;
                    }
                    break;
                } else {
                    $brainKnowledge .= "--- [DOKUMEN: {$brain->title}] ---\n";
                    $brainKnowledge .= $extractedContent . "\n\n";
                    $currentContextChars += $contentLength;
                }
            }
            
            $brainKnowledge .= "=================================\n\n";
            \Log::info("RAG Context Size: {$currentContextChars} chars (~" . intval($currentContextChars/4) . " tokens)");
        } else {
            // No documents found - check if this is a domain-specific query
            if ($classification['type'] === QueryClassifier::TYPE_DOMAIN_SPECIFIC) {
                // Domain query but no internal data - suggest adding to knowledge base
                $brainKnowledge = "=== INTERNAL KNOWLEDGE BASE ===\n";
                $brainKnowledge .= "TIDAK ADA DOKUMEN REFERENSI YANG DITEMUKAN UNTUK PERTANYAAN INI.\n";
                $brainKnowledge .= "ATURAN: Jika user bertanya tentang arsitektur, struktur folder, atau standar kode spesifik proyek, Anda WAJIB menjawab: 'Maaf, saya tidak menemukan panduan tersebut di dalam dokumen referensi saya. Bisakah Anda memberikan kata kunci yang lebih spesifik atau menambahkan panduan ini ke Knowledge Base?' JANGAN MENGARANG STRUKTUR FOLDER ATAU KODE SENDIRI.\n\n";
            } elseif ($classification['type'] === QueryClassifier::TYPE_LATEST_DATA) {
                // Latest data query but no internal data - will suggest web search
                $brainKnowledge = "";
                $usedInternalData = false;
                $matchedKeywords = [];
            } else {
                // General query - let AI use its knowledge
                $brainKnowledge = "";
                $usedInternalData = false;
                $matchedKeywords = [];
            }
        }
        
        // Label for RAG goto (when vector search succeeds, it jumps here)
        assembleSystemPrompt:
        
        // STRICT ANTI-HALLUCINATION INSTRUCTION (Critical for RAG accuracy)
        $antiHallucinationRule = "

CRITICAL INSTRUCTION (ANTI-HALLUCINATION RULE - HIGHEST PRIORITY):
You are answering based on the provided CONTEXT above. Follow these rules STRICTLY:
1. If the CONTEXT contains specific code snippets, CLI commands, function names, or array parameters, you MUST extract and use them EXACTLY as written. DO NOT assume, guess, or invent syntax.
2. Example: If the context says 'wp core update-db --network', do NOT reply with 'wp db update' or any variation. Use the EXACT command from the context.
3. If the answer requires multiple steps or features, ensure you have read ALL provided context chunks before answering. Do not answer based on partial information.
4. If the CONTEXT provides specific version numbers, function names like `wp_register_block_template()`, or API methods, you MUST use those exact names - do NOT substitute with similar-sounding alternatives.
5. When the context shows [USANG] (deprecated) vs [WP X.X+] (current), ALWAYS recommend the current approach unless user specifically asks about legacy code.
6. If the information is NOT in the provided CONTEXT, say: 'Informasi tersebut tidak ada dalam dokumen referensi saya.' DO NOT INVENT OR GUESS.
";
        
        $systemContent = $coreRules . "\n\n" . $brainKnowledge . $antiHallucinationRule . "\nPERHATIAN KHUSUS UNTUK SESI INI:\n" . $personaStyle;

        $payload[] = [
            'role' => 'system',
            'content' => $systemContent
        ];

        // 4. Inject Long-term Memory (Ambil maksimal 5 summary terbaru)
        $summaries = $room->summaries()->latest()->take(5)->get()->reverse();
        if ($summaries->isNotEmpty()) {
            $summaryText = $summaries->pluck('content')->implode("\n\n---\n\n");
            $payload[] = [
                'role' => 'system',
                'content' => "=== LONG-TERM MEMORY (5 Ringkasan Terakhir) ===\n" . $summaryText
            ];
        }

        // 4.5 Inject Knowledge Graph Context (if available and enabled)
        $graphContext = $this->tryKnowledgeGraphContext($room->id, $latestUserMsg);
        if ($graphContext['success'] && !empty($graphContext['context'])) {
            $payload[] = [
                'role' => 'system',
                'content' => $graphContext['context']
            ];
            \Log::info("PromptBuilder: Injected knowledge graph context", [
                'room_id' => $room->id,
                'nodes_used' => $graphContext['nodes_used'],
                'token_estimate' => $graphContext['token_estimate'],
            ]);
        }

        // 4.6 Inject Thesis Progress Record (for education persona rooms)
        if (str_starts_with($personaKey, 'education')) {
            $thesisContext = $this->buildThesisProgressContext($room->id);
            if (!empty($thesisContext)) {
                $payload[] = [
                    'role' => 'system',
                    'content' => $thesisContext,
                ];
                \Log::info("PromptBuilder: Injected thesis progress record for room {$room->id}");
            }
        }

        // BIARKAN INI JIKA ANDA MASIH MAU MENDUKUNG "GLOBAL ROOM PROMPT"
        if (!empty($room->system_prompt)) {
            $payload[] = [  
                'role' => 'system',
                'content' => "=== CODE KNOWLEDGE BASE (REFERENSI MUTLAK) ===\nBerikut adalah referensi kode proyek saat ini. Gunakan ini sebagai acuan utama jika user bertanya tentang struktur file atau UI.\n\n" . $room->system_prompt
            ];
        }

        // 5. BUILD HISTORY: Include as much context as the model can handle.
        // Agent mode needs full conversation history — no arbitrary message/chars limits.
        $isCloudModel = in_array($modelName, ['deepseek','ollama_cloud']);
        
        // Cloud models (DeepSeek, Gemma 31B) — virtually unlimited context
        // Local models — generous limit that fits 32K context windows comfortably
        $maxHistoryChars = $isCloudModel ? 500000 : 200000;
        
        $currentChars = 0;
        $historyPayload = [];

        // Looping mundur: dari pesan paling baru (index terakhir) ke paling lama
        for ($i = $recentMessages->count() - 1; $i >= 0; $i--) {

            $msg = $recentMessages[$i];
            
            // --- LOGIKA PENGHITUNGAN KARAKTER (WAJIB UNTUK CEGAH OOM LLM LOKAL) ---
            $contentLength = strlen($msg->content);
            $codeLength = !empty($msg->context_code) ? strlen($msg->context_code) : 0;
            $searchLength = !empty($msg->search_context) ? strlen($msg->search_context) : 0;
            
            $totalMessageLength = $contentLength + $codeLength + $searchLength;

            // Jika menambah pesan ini akan melebihi batas RAM/Karakter (dan ini bukan pesan pertama)
            if ($currentChars + $totalMessageLength > $maxHistoryChars && count($historyPayload) > 0) {
                break;
            }

            $finalContent = $msg->content;

            // --- INJEKSI SEARCH CONTEXT ---
            if (!empty($msg->search_context)) {
                $finalContent .= "\n\n" . $msg->search_context;
            }

            // Gabungkan context_code ke dalam pesan User
            if (!empty($msg->context_code) && $msg->sender_type !== 'bot') {
                $finalContent .= "\n\n=== ATTACHED CODE ===\n" . $msg->context_code;
            }

            $historyPayload[] = [
                'role' => $msg->sender_type === 'bot' ? 'assistant' : 'user',
                'content' => $finalContent,
            ];

            // Update counter limit
            $currentChars += $totalMessageLength;
        }

        // Balikkan urutan array agar kembali kronologis (Lama -> Baru)
        $historyPayload = array_reverse($historyPayload);

        // Gabungkan riwayat ke dalam payload utama
        $payload = array_merge($payload, $historyPayload);

        return [
            'payload' => $payload,
            'used_internal_data' => $usedInternalData,
            'matched_keywords' => $matchedKeywords,
            'classification' => $classification,
            'suggest_web_search' => $classification['suggest_web_search'] ?? false,
        ];
    }

    /**
     * Build payload for GENERAL knowledge queries.
     * AI will use its built-in knowledge without strict RAG constraints.
     */
    private function buildGeneralKnowledgePayload(
        Room $room, 
        Collection $recentMessages, 
        string $coreRules, 
        string $personaStyle,
        ?string $modelName,
        array $classification
    ): array {
        $payload = [];
        
        // Modified system prompt for general knowledge - less restrictive
        $generalSystemPrompt = $coreRules . "\n\n";
        $generalSystemPrompt .= "CATATAN KHUSUS: Pertanyaan ini adalah pertanyaan UMUM yang tidak memerlukan referensi dokumen internal. ";
        $generalSystemPrompt .= "Anda DIIZINKAN menggunakan pengetahuan bawaan model AI Anda untuk menjawab. ";
        $generalSystemPrompt .= "Tetap berikan jawaban yang akurat, informatif, dan sesuai dengan persona yang dipilih.\n\n";
        $generalSystemPrompt .= "PERHATIAN KHUSUS UNTUK SESI INI:\n" . $personaStyle;
        
        $payload[] = [
            'role' => 'system',
            'content' => $generalSystemPrompt
        ];
        
        // Inject Long-term Memory
        $summaries = $room->summaries()->latest()->take(5)->get()->reverse();
        if ($summaries->isNotEmpty()) {
            $summaryText = $summaries->pluck('content')->implode("\n\n---\n\n");
            $payload[] = [
                'role' => 'system',
                'content' => "=== LONG-TERM MEMORY ===\n" . $summaryText
            ];
        }

        // Inject Knowledge Graph Context (if available)
        $latestUserMsg = $recentMessages->last() ? $recentMessages->last()->content : '';
        $graphContext = $this->tryKnowledgeGraphContext($room->id, $latestUserMsg);
        if ($graphContext['success'] && !empty($graphContext['context'])) {
            $payload[] = [
                'role' => 'system',
                'content' => $graphContext['context']
            ];
        }

        // Inject Thesis Progress Record (for education persona rooms)
        $personaKey = $room->persona ?? 'general';
        if (str_starts_with($personaKey, 'education')) {
            $thesisContext = $this->buildThesisProgressContext($room->id);
            if (!empty($thesisContext)) {
                $payload[] = [
                    'role' => 'system',
                    'content' => $thesisContext,
                ];
            }
        }
        
        // Build history payload — same generous limits as main build
        $isCloudModel = in_array($modelName, ['deepseek', 'ollama_cloud']);
        $maxHistoryChars = $isCloudModel ? 500000 : 200000;
        $currentChars = 0;
        $historyPayload = [];

        for ($i = $recentMessages->count() - 1; $i >= 0; $i--) {

            $msg = $recentMessages[$i];
            $contentLength = strlen($msg->content);
            $codeLength = !empty($msg->context_code) ? strlen($msg->context_code) : 0;
            $searchLength = !empty($msg->search_context) ? strlen($msg->search_context) : 0;
            $totalMessageLength = $contentLength + $codeLength + $searchLength;

            if ($currentChars + $totalMessageLength > $maxHistoryChars && count($historyPayload) > 0) {
                break;
            }

            $finalContent = $msg->content;

            if (!empty($msg->search_context)) {
                $finalContent .= "\n\n" . $msg->search_context;
            }

            if (!empty($msg->context_code) && $msg->sender_type !== 'bot') {
                $finalContent .= "\n\n=== ATTACHED CODE ===\n" . $msg->context_code;
            }

            $historyPayload[] = [
                'role' => $msg->sender_type === 'bot' ? 'assistant' : 'user',
                'content' => $finalContent,
            ];

            $currentChars += $totalMessageLength;
        }

        $historyPayload = array_reverse($historyPayload);
        $payload = array_merge($payload, $historyPayload);

        \Log::info("Built GENERAL knowledge payload - AI will use model knowledge directly");

        return [
            'payload' => $payload,
            'used_internal_data' => false,
            'matched_keywords' => [],
            'classification' => $classification,
            'suggest_web_search' => false,
        ];
    }

    /**
     * Build payload when JIT RAG context is available (local folder scan).
     * Uses the JIT-retrieved context as the primary knowledge source.
     */
    private function buildJitRagPayload(
        Room $room,
        Collection $recentMessages,
        string $coreRules,
        string $personaStyle,
        ?string $modelName,
        string $jitContext
    ): array {
        $payload = [];

        $systemContent = $coreRules . "\n\n" . $jitContext;
        $systemContent .= "\nCRITICAL INSTRUCTION: The context above was retrieved from local files on the user's machine via JIT RAG. ";
        $systemContent .= "Answer STRICTLY based on these documents. If the information is not in the context, say so clearly.\n\n";
        $systemContent .= "PERHATIAN KHUSUS UNTUK SESI INI:\n" . $personaStyle;

        $payload[] = ['role' => 'system', 'content' => $systemContent];

        // Inject Long-term Memory
        $summaries = $room->summaries()->latest()->take(5)->get()->reverse();
        if ($summaries->isNotEmpty()) {
            $summaryText = $summaries->pluck('content')->implode("\n\n---\n\n");
            $payload[] = ['role' => 'system', 'content' => "=== LONG-TERM MEMORY ===\n" . $summaryText];
        }

        // Inject Knowledge Graph Context
        $latestUserMsg = $recentMessages->last() ? $recentMessages->last()->content : '';
        $graphContext = $this->tryKnowledgeGraphContext($room->id, $latestUserMsg);
        if ($graphContext['success'] && !empty($graphContext['context'])) {
            $payload[] = ['role' => 'system', 'content' => $graphContext['context']];
        }

        // Build history payload — same generous limits
        $isCloudModel = in_array($modelName, ['deepseek', 'ollama_cloud']);
        $maxHistoryChars = $isCloudModel ? 500000 : 200000;
        $currentChars = 0;
        $historyPayload = [];

        for ($i = $recentMessages->count() - 1; $i >= 0; $i--) {

            $msg = $recentMessages[$i];
            $contentLength = strlen($msg->content);
            $codeLength = !empty($msg->context_code) ? strlen($msg->context_code) : 0;
            $searchLength = !empty($msg->search_context) ? strlen($msg->search_context) : 0;
            $totalMessageLength = $contentLength + $codeLength + $searchLength;

            if ($currentChars + $totalMessageLength > $maxHistoryChars && count($historyPayload) > 0) {
                break;
            }

            $finalContent = $msg->content;

            if (!empty($msg->search_context)) {
                $finalContent .= "\n\n" . $msg->search_context;
            }

            if (!empty($msg->context_code) && $msg->sender_type !== 'bot') {
                $finalContent .= "\n\n=== ATTACHED CODE ===\n" . $msg->context_code;
            }

            $historyPayload[] = [
                'role' => $msg->sender_type === 'bot' ? 'assistant' : 'user',
                'content' => $finalContent,
            ];

            $currentChars += $totalMessageLength;
        }

        $historyPayload = array_reverse($historyPayload);
        $payload = array_merge($payload, $historyPayload);

        \Log::info("Built JIT RAG payload with folder context");

        return [
            'payload' => $payload,
            'used_internal_data' => true,
            'matched_keywords' => [],
            'classification' => ['type' => 'jit_rag', 'reason' => 'JIT folder RAG active'],
            'suggest_web_search' => false,
        ];
    }

    /**
     * Strip HTML tags and clean up content for efficient token usage.
     * Reduces token count by ~40% by removing HTML markup.
     */
    private function cleanHtmlContent(string $html): string
    {
        // Preserve code blocks - extract them first
        $codeBlocks = [];
        $html = preg_replace_callback('/<pre><code[^>]*>(.*?)<\/code><\/pre>/s', function($matches) use (&$codeBlocks) {
            $placeholder = "[[CODE_BLOCK_" . count($codeBlocks) . "]]";
            $codeBlocks[$placeholder] = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return $placeholder;
        }, $html);
        
        // Also handle <code> without <pre>
        $html = preg_replace_callback('/<code[^>]*>(.*?)<\/code>/s', function($matches) use (&$codeBlocks) {
            $placeholder = "[[INLINE_CODE_" . count($codeBlocks) . "]]";
            $codeBlocks[$placeholder] = '`' . html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '`';
            return $placeholder;
        }, $html);
        
        // Convert headers to markdown-style
        $html = preg_replace('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/i', "\n## $1\n", $html);
        
        // Convert lists
        $html = preg_replace('/<li[^>]*>/i', "\n• ", $html);
        $html = preg_replace('/<\/li>/i', '', $html);
        
        // Convert paragraphs to newlines
        $html = preg_replace('/<p[^>]*>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n", $html);
        
        // Convert <br> to newlines
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        
        // Strip all remaining HTML tags
        $text = strip_tags($html);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Restore code blocks
        foreach ($codeBlocks as $placeholder => $code) {
            if (str_starts_with($placeholder, '[[CODE_BLOCK_')) {
                $text = str_replace($placeholder, "\n```\n" . trim($code) . "\n```\n", $text);
            } else {
                $text = str_replace($placeholder, $code, $text);
            }
        }
        
        // Clean up excessive whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        
        return trim($text);
    }

    /**
     * Extract only the most relevant sections from content based on keywords.
     * For queries about "folder structure", extracts code blocks with directory trees.
     */
    private function extractRelevantSections(string $content, array $keywords): string
    {
        // Check if user is asking for folder/directory structure
        $structureKeywords = ['folder', 'direktori', 'directory', 'struktur', 'structure', 'tree'];
        $isAskingForStructure = !empty(array_intersect($structureKeywords, array_map('strtolower', $keywords)));
        
        if (!$isAskingForStructure) {
            // If not asking for structure, return full content (already cleaned)
            return $content;
        }
        
        // Extract code blocks that look like folder structures
        $sections = [];
        
        // Find sections with folder-like patterns
        if (preg_match_all('/```[\s\S]*?```/m', $content, $codeMatches)) {
            foreach ($codeMatches[0] as $codeBlock) {
                // Check if code block contains folder structure indicators
                if (preg_match('/[├└│─]|\/[a-zA-Z_-]+\/|\.(php|js|css|json|md)/', $codeBlock)) {
                    $sections[] = $codeBlock;
                }
            }
        }
        
        // Also extract section headers and their following paragraphs about structure
        if (preg_match_all('/## .*?(struktur|folder|direktori|directory).*?\n[\s\S]*?(?=\n## |$)/i', $content, $sectionMatches)) {
            foreach ($sectionMatches[0] as $section) {
                if (!in_array($section, $sections)) {
                    $sections[] = trim($section);
                }
            }
        }
        
        // If we found relevant sections, return only those
        if (!empty($sections)) {
            $extracted = "=== EXTRACTED RELEVANT SECTIONS ===\n\n";
            $extracted .= implode("\n\n---\n\n", $sections);
            
            // But also include a brief intro from the document (first 500 chars)
            $intro = substr($content, 0, 500);
            $introEnd = strrpos($intro, "\n");
            if ($introEnd !== false) {
                $intro = substr($intro, 0, $introEnd);
            }
            
            return $intro . "\n\n" . $extracted;
        }
        
        // Fallback: return original content
        return $content;
    }

    /**
     * Try Vector RAG search (O'Reilly pattern).
     * Returns context if successful, or signals fallback to keyword search.
     */
    private function tryVectorRAG(string $query, string $personaKey): array
    {
        // Check if RAG is enabled and documents are indexed
        if (!config('services.rag.enabled', true)) {
            return ['success' => false, 'reason' => 'RAG disabled'];
        }

        try {
            // Lazy load RAG Manager
            if (!$this->ragManager) {
                $this->ragManager = new RAGManager();
            }

            // Check if RAG system is ready (embedding model available)
            if (!$this->ragManager->isReady()) {
                \Log::info("RAG: Embedding model not available, falling back to keyword search");
                return ['success' => false, 'reason' => 'Embedding model not available'];
            }

            // Check if we have indexed documents
            $stats = $this->ragManager->getStats();
            if ($stats['chunks_with_embeddings'] === 0) {
                \Log::info("RAG: No indexed documents, falling back to keyword search");
                return ['success' => false, 'reason' => 'No indexed documents'];
            }

            // Extract keywords for hybrid search
            $keywords = $this->extractKeywords($query);

            // Perform RAG retrieval (Top-K increased for complex queries)
            $result = $this->ragManager->retrieve($query, [
                'tags' => ['general', $personaKey],
                'top_k' => config('services.rag.top_k', 6),
                'hybrid' => config('services.rag.use_hybrid_search', true),
                'keywords' => $keywords,
            ]);

            // Check if we found relevant chunks
            if ($result['chunks']->isEmpty()) {
                \Log::info("RAG: No relevant chunks found via vector search");
                return ['success' => false, 'reason' => 'No relevant chunks found'];
            }

            // Format context for LLM (increased for complex queries)
            $maxTokens = config('services.rag.max_context_tokens', 4000);
            $context = $this->ragManager->formatContext($result, $maxTokens);

            return [
                'success' => true,
                'context' => $context,
                'chunk_count' => $result['chunks']->count(),
                'method' => $result['method'],
                'keywords' => $keywords,
            ];

        } catch (\Exception $e) {
            \Log::error("RAG Error: " . $e->getMessage());
            return ['success' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Extract keywords from query for hybrid search.
     */
    private function extractKeywords(string $query): array
    {
        $cleanText = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', strtolower($query));
        $words = array_unique(explode(' ', $cleanText));
        
        $stopwords = [
            'yang', 'dan', 'di', 'ke', 'dari', 'ini', 'itu', 'untuk', 'dengan', 'dalam',
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should',
            'can', 'you', 'your', 'me', 'my', 'we', 'our', 'they', 'their', 'he', 'she', 'it',
            'this', 'that', 'what', 'which', 'who', 'how', 'please', 'using', 'use',
            'create', 'make', 'show', 'give', 'tell', 'explain',
        ];
        
        $keywords = array_filter($words, function($word) use ($stopwords) {
            return strlen($word) > 2 && !in_array($word, $stopwords);
        });
        
        return array_values($keywords);
    }

    /**
     * Try to get context from Knowledge Graph for the query.
     * Returns graph context if available, otherwise empty array.
     */
    public function tryKnowledgeGraphContext(int $roomId, string $query): array
    {
        if (!config('services.knowledge_graph.enabled', true)) {
            return ['success' => false, 'reason' => 'Knowledge graph disabled'];
        }

        try {
            if (!$this->graphManager) {
                $this->graphManager = new GraphManager();
            }

            if (!$this->graphManager->hasGraphData($roomId)) {
                \Log::info("KnowledgeGraph: Room {$roomId} has insufficient graph data");
                return ['success' => false, 'reason' => 'Insufficient graph data'];
            }

            $result = $this->graphManager->getContext($roomId, $query);

            if ($result['success'] && !empty($result['context'])) {
                \Log::info("KnowledgeGraph: Retrieved context for room {$roomId}", [
                    'nodes_used' => $result['nodes_used'],
                    'token_estimate' => $result['token_estimate'],
                ]);

                return [
                    'success' => true,
                    'context' => $result['context'],
                    'nodes_used' => $result['nodes_used'],
                    'token_estimate' => $result['token_estimate'],
                    'keywords' => $result['keywords'],
                ];
            }

            return ['success' => false, 'reason' => 'No relevant nodes found'];

        } catch (\Exception $e) {
            \Log::error("KnowledgeGraph Error: " . $e->getMessage());
            return ['success' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Build thesis progress context from stored evaluations.
     * Gives the AI long-term memory of the student's thesis state.
     */
    private function buildThesisProgressContext(int $roomId): string
    {
        $evaluations = \App\Models\ThesisEvaluation::where('room_id', $roomId)
            ->latest()
            ->take(5)
            ->get();

        if ($evaluations->isEmpty()) {
            return '';
        }

        $latest = $evaluations->first();
        $context = "=== THESIS PROGRESS RECORD ===\n";
        $context .= "Evaluasi terakhir: " . $latest->created_at->format('d M Y H:i') . "\n";
        $context .= "Tipe: " . $latest->evaluation_type . "\n";
        $context .= "Skor Keseluruhan: " . $latest->overall_score . "/10\n\n";

        $chapterScores = $latest->chapter_scores ?? [];
        if (!empty($chapterScores)) {
            $context .= "SKOR PER BAB:\n";
            foreach ($chapterScores as $key => $chapter) {
                if (is_array($chapter)) {
                    $label = $chapter['label'] ?? ucfirst(str_replace('_', ' ', $key));
                    $score = $chapter['score'] ?? '-';
                    $notes = $chapter['notes'] ?? '';
                    $context .= "- {$label}: {$score}/10" . ($notes ? " — {$notes}" : '') . "\n";
                }
            }
            $context .= "\n";
        }

        $strengths = $latest->strengths ?? [];
        if (!empty($strengths)) {
            $context .= "KEKUATAN: " . implode('; ', $strengths) . "\n";
        }

        $weaknesses = $latest->weaknesses ?? [];
        if (!empty($weaknesses)) {
            $context .= "KELEMAHAN: " . implode('; ', $weaknesses) . "\n";
        }

        if (!empty($latest->recommendations)) {
            $context .= "REKOMENDASI: " . $latest->recommendations . "\n";
        }

        // Show score trend if multiple evaluations exist
        if ($evaluations->count() > 1) {
            $context .= "\nTREN SKOR (terbaru → terlama): ";
            $scores = $evaluations->pluck('overall_score')->map(fn($s) => "{$s}/10")->implode(' → ');
            $context .= $scores . "\n";
        }

        $context .= "=================================\n";
        $context .= "Gunakan data di atas sebagai baseline untuk mengukur perkembangan mahasiswa.\n";

        return $context;
    }

    /**
     * Get GraphManager instance for external use.
     */
    public function getGraphManager(): GraphManager
    {
        if (!$this->graphManager) {
            $this->graphManager = new GraphManager();
        }
        return $this->graphManager;
    }
}