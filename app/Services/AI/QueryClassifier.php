<?php

namespace App\Services\AI;

use App\Models\Brain;
use Illuminate\Support\Facades\Log;

/**
 * QueryClassifier - Intelligently classifies user queries to determine the best response strategy.
 * 
 * Classification Types:
 * - GENERAL: Generic questions → Use AI model knowledge directly
 * - DOMAIN_SPECIFIC: Project/work-related → Use internal RAG data
 * - LATEST_DATA: Time-sensitive queries → May need web search
 */
class QueryClassifier
{
    const TYPE_GENERAL = 'general';
    const TYPE_DOMAIN_SPECIFIC = 'domain_specific';
    const TYPE_LATEST_DATA = 'latest_data';

    private array $latestDataIndicators = [
        // Indonesian - phrases and temporal indicators for real-time / latest data
        'terbaru', 'terkini', 'berita terbaru', 'kabar terbaru',
        'update terbaru', 'versi terbaru', 'rilis terbaru', 
        'tren saat ini', 'trending sekarang',
        'info terkini', 'isu terkini', 'viral sekarang',
        'hari ini', 'kemarin', 'minggu ini', 'bulan ini', 'tahun ini', 'saat ini',
        'apa yang terjadi', 'ada kejadian apa', 'ada apa di', 'terjadi apa',
        'cuaca hari ini', 'kurs hari ini', 'skor pertandingan', 'jadwal pertandingan',
        
        // Web search explicitly requested in Indonesian
        'web search', 'cari di web', 'cari di internet', 'cari di google', 
        'cari lewat web', 'cari menggunakan web', 'googling', 'search web', 'browsing',
        
        // English - phrases that clearly indicate need for latest data
        'latest news', 'latest version', 'latest release', 'latest update',
        'newest version', 'most recent', 'current news', 'current trends',
        'trending now', 'what\'s new in',
        'today', 'yesterday', 'this week', 'this month', 'this year', 'right now',
        'current events', 'what happened', 'breaking news',
        
        // Year-specific queries asking about current state
        'in 2024', 'in 2025', 'in 2026', 'in 2027',
        'tahun 2024', 'tahun 2025', 'tahun 2026', 'tahun 2027',
    ];
    
    private array $taskInstructionPatterns = [
        // User is asking AI to modify/change something
        '/\b(ubah|ganti|modifikasi|perbaiki|fix|change|modify|edit|replace|transform)\b/i',
        // User is sharing code and asking about it
        '/\b(tolong|please|bisa|could you|can you)\s+(ubah|ganti|lihat|check|review|analisis|analyze)/i',
        // Starts with acknowledgment + instruction
        '/^(oke?|okay?|baik|ya|yes|sure|alright)\s*[,.]?\s*(pertama|first|lalu|then|sekarang|now)?/i',
        // Contains code blocks or HTML-like content
        '/<[a-z]+[\s>]/i',
        '/```/i',
        '/function\s*\(/i',
        '/\bconst\s+\w+\s*=/i',
        '/\blet\s+\w+\s*=/i',
        '/\bvar\s+\w+\s*=/i',
    ];

    private array $domainKeywords = [
        // WordPress/WooCommerce
        'wordpress', 'woocommerce', 'wp', 'woo', 'theme', 'tema', 'plugin', 'plugins',
        'hook', 'filter', 'action', 'shortcode', 'gutenberg', 'block', 'acf',
        'elementor', 'divi', 'wp-admin', 'wp-content', 'wp-config',
        
        // HubSpot
        'hubspot', 'hubl', 'hubdb', 'crm', 'cms hub', 'marketing hub',
        
        // Web Development
        'laravel', 'php', 'javascript', 'typescript', 'react', 'vue', 'angular',
        'tailwind', 'css', 'html', 'api', 'rest', 'graphql', 'mysql', 'database',
        
        // DevOps
        'aws', 'cloudflare', 'docker', 'kubernetes', 'nginx', 'apache', 'linux', 'ubuntu',
        'server', 'deployment', 'ci/cd', 'pipeline',
        
        // Project-specific (can be extended based on brain content)
        'hubexo', 'projekt', 'proyek', 'struktur folder', 'folder structure',
        'arsitektur', 'architecture', 'standar', 'standard', 'guideline', 'panduan',
    ];

    private array $generalConversationPatterns = [
        // Greetings
        '/^(hi|hello|halo|hai|hey|selamat\s+(pagi|siang|sore|malam)|good\s+(morning|afternoon|evening))[\s!?.,]*$/i',
        
        // Personal questions
        '/^(siapa\s+kamu|who\s+are\s+you|apa\s+kabar|how\s+are\s+you)/i',
        
        // General knowledge (philosophy, life, etc.)
        '/\b(filosofi|philosophy|arti\s+hidup|meaning\s+of\s+life|motivasi|motivation)\b/i',
        
        // Math/calculations
        '/^\d+[\s]*[\+\-\*\/\^][\s]*\d+/i',
        '/^(hitung|calculate|berapa|what\s+is)\s+\d+/i',
        
        // General explanations not related to specific projects
        '/^(apa\s+itu|what\s+is|jelaskan\s+tentang|explain)\s+(ai|artificial\s+intelligence|machine\s+learning|blockchain|cryptocurrency|nft|web3)/i',
        
        // Relationship/personal advice
        '/\b(relationship|hubungan|cinta|love|pacar|girlfriend|boyfriend|pasangan|partner)\b/i',
        
        // Health (general)
        '/\b(kesehatan|health|diet|olahraga|exercise|tidur|sleep|stress)\b/i',
        
        // Business/career (general)
        '/\b(karir|career|interview|wawancara|gaji|salary|resign|pekerjaan|job)\b/i',
        
        // Fun/entertainment
        '/\b(joke|lelucon|cerita|story|rekomendasi\s+(film|movie|buku|book|musik|music))\b/i',
    ];

    /**
     * Classify the query and determine the best response strategy.
     */
    public function classify(string $query, string $personaKey = 'general'): array
    {
        $queryLower = strtolower($query);
        
        // Explicit web search intent query (e.g. "bisakah kamu cari menggunakan web search ?")
        // Should immediately trigger web search recommendation/flow and not get hijacked by internal RAG
        if (preg_match('/\b(web\s*search|cari\s*(di|ke|lewat|menggunakan|pakai)?\s*(web|internet|google)|search\s*web|googling|browsing)\b/i', $queryLower)) {
            $hasBrainData = $this->checkBrainDataAvailability($queryLower, $personaKey);
            Log::info("QueryClassifier: Explicit web search intent detected", ['query' => substr($query, 0, 100)]);
            return [
                'type' => self::TYPE_LATEST_DATA,
                'use_rag' => false,
                'suggest_web_search' => true,
                'brain_data' => $hasBrainData,
                'reason' => 'Query explicitly requests web search or internet lookup',
            ];
        }

        // First check if this is a task/instruction query (user asking AI to do something)
        // These should NEVER trigger web search suggestion
        $isTaskInstruction = $this->detectTaskInstructionQuery($query);
        
        $isLatestData = !$isTaskInstruction && $this->detectLatestDataQuery($queryLower);
        $isDomainSpecific = $this->detectDomainSpecificQuery($queryLower, $personaKey);
        $isGeneralConversation = $this->detectGeneralConversation($query);
        
        $hasBrainData = $this->checkBrainDataAvailability($queryLower, $personaKey);
        
        Log::info("QueryClassifier Analysis", [
            'query' => substr($query, 0, 100),
            'isTaskInstruction' => $isTaskInstruction,
            'isLatestData' => $isLatestData,
            'isDomainSpecific' => $isDomainSpecific,
            'isGeneralConversation' => $isGeneralConversation,
            'hasBrainData' => $hasBrainData['found'],
            'brainRelevance' => $hasBrainData['relevance'] ?? 0,
        ]);

        // Task/instruction queries - user is asking AI to do something
        // Use AI knowledge directly, no web search needed
        if ($isTaskInstruction) {
            return [
                'type' => self::TYPE_GENERAL,
                'use_rag' => $hasBrainData['found'],
                'suggest_web_search' => false,
                'brain_data' => $hasBrainData,
                'reason' => 'Task/instruction query - use AI knowledge to complete the task',
            ];
        }

        if ($isLatestData) {
            $hasRelevantInternalData = $hasBrainData['found'] && ($hasBrainData['relevance'] >= 0.6);
            return [
                'type' => self::TYPE_LATEST_DATA,
                'use_rag' => $hasRelevantInternalData,
                'suggest_web_search' => true,
                'brain_data' => $hasBrainData,
                'reason' => $hasRelevantInternalData 
                    ? 'Found internal data but query asks for latest information'
                    : 'No internal data and query asks for latest information',
            ];
        }

        if ($isGeneralConversation && !$isDomainSpecific) {
            return [
                'type' => self::TYPE_GENERAL,
                'use_rag' => false,
                'suggest_web_search' => false,
                'brain_data' => ['found' => false, 'relevance' => 0],
                'reason' => 'General conversation/knowledge question - use AI model directly',
            ];
        }

        if ($isDomainSpecific) {
            return [
                'type' => self::TYPE_DOMAIN_SPECIFIC,
                'use_rag' => true,
                'suggest_web_search' => false,
                'brain_data' => $hasBrainData,
                'reason' => $hasBrainData['found']
                    ? 'Domain-specific query with relevant internal data'
                    : 'Domain-specific query but no matching internal data found',
            ];
        }

        if ($hasBrainData['found'] && $hasBrainData['relevance'] >= 0.6) {
            return [
                'type' => self::TYPE_DOMAIN_SPECIFIC,
                'use_rag' => true,
                'suggest_web_search' => false,
                'brain_data' => $hasBrainData,
                'reason' => 'Query has high relevance to internal knowledge base',
            ];
        }

        return [
            'type' => self::TYPE_GENERAL,
            'use_rag' => false,
            'suggest_web_search' => false,
            'brain_data' => $hasBrainData,
            'reason' => 'No specific domain detected - use AI model general knowledge',
        ];
    }

    /**
     * Detect if query is a task/instruction (user asking AI to do something).
     * These queries should NOT trigger web search suggestion.
     */
    private function detectTaskInstructionQuery(string $query): bool
    {
        // If query asks for web search, it's not a code task instruction
        if (preg_match('/\b(web\s*search|cari\s*(di|ke|lewat|menggunakan|pakai)?\s*(web|internet|google)|search\s*web|googling|browsing)\b/i', $query)) {
            return false;
        }

        foreach ($this->taskInstructionPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }
        
        // If query contains code-like content (multiple lines with indentation, semicolons, brackets)
        if (preg_match('/[{}\[\];].*[{}\[\];]/s', $query)) {
            return true;
        }
        
        // If query has HTML tags inside it (user sharing code)
        if (preg_match('/<\/?[a-z]+[^>]*>/i', $query) && strlen($query) > 100) {
            return true;
        }
        
        return false;
    }

    /**
     * Detect if query asks for latest/current/recent data.
     */
    private function detectLatestDataQuery(string $queryLower): bool
    {
        // Don't treat simple greetings or personal questions as latest data
        if (preg_match('/^(hi|hello|halo|hai|hey|apa\s+kabar|how\s+are\s+you|siapa\s+kamu|who\s+are\s+you)/i', trim($queryLower))) {
            return false;
        }

        foreach ($this->latestDataIndicators as $indicator) {
            if (str_contains($queryLower, strtolower($indicator))) {
                return true;
            }
        }
        
        // Match month + year (e.g. "september 2026", "bulan september 2026")
        if (preg_match('/\b(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember|january|february|march|april|may|june|july|august|september|october|november|december)\s*20\d{2}\b/i', $queryLower)) {
            return true;
        }

        // Match current or future years (2024 to 2039)
        if (preg_match('/\b(202[4-9]|203\d)\b/', $queryLower)) {
            return true;
        }

        // Questions about real-world current events
        if (preg_match('/\b(ada\s+kejadian|ada\s+apa\s+di|apa\s+yang\s+terjadi|berita\s+tentang|kabar\s+tentang|siapa\s+juara|siapa\s+pemenang)\b/i', $queryLower)) {
            return true;
        }

        if (preg_match('/\b(versi|version)\s*(berapa|terbaru|latest|what)/i', $queryLower)) {
            return true;
        }
        
        if (preg_match('/\b(harga|price|biaya|cost)\s+(sekarang|saat\s+ini|current|now)/i', $queryLower)) {
            return true;
        }
        
        return false;
    }

    /**
     * Detect if query is about domain-specific topics (work/project related).
     */
    private function detectDomainSpecificQuery(string $queryLower, string $personaKey): bool
    {
        foreach ($this->domainKeywords as $keyword) {
            if (str_contains($queryLower, strtolower($keyword))) {
                return true;
            }
        }
        
        $personaKeywords = $this->getPersonaKeywords($personaKey);
        foreach ($personaKeywords as $keyword) {
            if (str_contains($queryLower, strtolower($keyword))) {
                return true;
            }
        }
        
        if (preg_match('/\b(error|bug|debug|fix|issue|masalah|tidak\s+bisa|can\'?t|doesn\'?t\s+work)\b/i', $queryLower)) {
            return true;
        }
        
        if (preg_match('/\b(cara|how\s+to|gimana|bagaimana)\s+(buat|bikin|create|membuat|install|setup|configure|konfigurasi)/i', $queryLower)) {
            return true;
        }
        
        return false;
    }

    /**
     * Detect if query is general conversation or common knowledge.
     */
    private function detectGeneralConversation(string $query): bool
    {
        foreach ($this->generalConversationPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }
        
        $wordCount = str_word_count($query);
        if ($wordCount <= 3 && !$this->detectDomainSpecificQuery(strtolower($query), 'general')) {
            return true;
        }
        
        return false;
    }

    /**
     * Quick check if brain database has relevant content for this query.
     */
    private function checkBrainDataAvailability(string $queryLower, string $personaKey): array
    {
        $keywords = $this->extractKeywords($queryLower);
        
        if (empty($keywords)) {
            return ['found' => false, 'relevance' => 0, 'matches' => 0];
        }
        
        $matchCount = 0;
        $totalKeywords = count($keywords);
        
        $brainQuery = Brain::where(function($q) use ($personaKey) {
            $q->whereJsonContains('tag', 'general')
              ->orWhereJsonContains('tag', $personaKey);
        });
        
        $potentialMatches = $brainQuery->where(function($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                $q->orWhere('title', 'LIKE', "%{$keyword}%")
                  ->orWhere('content', 'LIKE', "%{$keyword}%");
            }
        })->take(5)->get();
        
        if ($potentialMatches->isEmpty()) {
            return ['found' => false, 'relevance' => 0, 'matches' => 0];
        }
        
        foreach ($potentialMatches as $brain) {
            $contentLower = strtolower($brain->content . ' ' . $brain->title);
            foreach ($keywords as $keyword) {
                if (str_contains($contentLower, $keyword)) {
                    $matchCount++;
                }
            }
        }
        
        $relevance = min(1.0, $matchCount / max(1, $totalKeywords * 2));
        $isFound = $potentialMatches->isNotEmpty() && $relevance >= 0.5;
        
        return [
            'found' => $isFound,
            'relevance' => round($relevance, 2),
            'matches' => $potentialMatches->count(),
            'titles' => $potentialMatches->pluck('title')->take(3)->toArray(),
        ];
    }

    /**
     * Extract meaningful keywords from query.
     */
    private function extractKeywords(string $query): array
    {
        $cleanText = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query);
        $words = array_unique(explode(' ', $cleanText));
        
        $stopwords = [
            'yang', 'dan', 'di', 'ke', 'dari', 'ini', 'itu', 'untuk', 'dengan', 'dalam',
            'pada', 'adalah', 'sebagai', 'apakah', 'bagaimana', 'tolong', 'saya', 'kamu',
            'bisa', 'bisakah', 'tidak', 'ada', 'apa', 'contoh', 'tentang', 'ingin', 'dulu', 'juga',
            'coba', 'mohon', 'cari', 'carikan', 'mencari', 'pencarian', 'search', 'searching',
            'googling', 'web', 'internet', 'google', 'online', 'pakai', 'menggunakan', 'lewat', 'via',
            'tanya', 'tanyakan', 'bulan', 'tahun', 'hari',
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should',
            'can', 'you', 'your', 'me', 'my', 'we', 'our', 'they', 'their', 'he', 'she', 'it',
            'this', 'that', 'what', 'which', 'who', 'how', 'please', 'using', 'use',
        ];
        
        return array_values(array_filter($words, function($word) use ($stopwords) {
            return strlen($word) > 2 && !in_array($word, $stopwords);
        }));
    }

    /**
     * Get additional keywords based on persona context.
     */
    private function getPersonaKeywords(string $personaKey): array
    {
        $personaKeywords = [
            'wordpress' => ['wordpress', 'wp', 'theme', 'plugin', 'hook', 'woocommerce'],
            'hubspot' => ['hubspot', 'hubl', 'hubdb', 'crm', 'cms'],
            'devops' => ['server', 'aws', 'docker', 'nginx', 'deployment', 'linux'],
            'laravel' => ['laravel', 'php', 'artisan', 'eloquent', 'blade', 'migration'],
        ];
        
        return $personaKeywords[$personaKey] ?? [];
    }
}
