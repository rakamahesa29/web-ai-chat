<?php

namespace App\Services\KnowledgeGraph;

use App\Services\AI\ProviderFactory;
use Illuminate\Support\Facades\Log;

/**
 * EntityExtractor - Uses LLM to extract entities and relationships from messages.
 * 
 * Extracts structured data for knowledge graph construction:
 * - Entities: topics, concepts, persons, actions
 * - Relationships: connections between entities with labels
 */
class EntityExtractor
{
    private const EXTRACTION_PROMPT = <<<'PROMPT'
You are a Knowledge Graph Entity Extractor. Extract key entities and their relationships from the conversation below.

RULES:
1. Extract ONLY meaningful entities (topics, concepts, technical terms, names).
2. Skip generic words like "question", "answer", "help", "please".
3. Identify relationships between entities.
4. Keep entity names concise (1-4 words max).
5. Return VALID JSON only, no markdown or explanation.

ENTITY TYPES:
- topic: Main subjects discussed (e.g., "WordPress", "API integration")
- concept: Technical concepts or ideas (e.g., "caching", "authentication")
- person: Names of people or roles (e.g., "user", "admin")
- action: Key actions discussed (e.g., "deploy", "optimize")
- entity: Other important nouns (e.g., "database", "server")

OUTPUT FORMAT (JSON):
{
  "entities": [
    {"text": "entity name", "type": "topic|concept|person|action|entity"}
  ],
  "relationships": [
    {"from": "entity1", "to": "entity2", "relation": "relationship label"}
  ]
}

CONVERSATION:
PROMPT;

    /**
     * Extract entities and relationships from a conversation.
     */
    public function extract(string $userMessage, string $botResponse, ?string $modelName = null): array
    {
        $conversation = "User: {$userMessage}\nAssistant: {$botResponse}";
        
        $prompt = self::EXTRACTION_PROMPT . "\n" . $conversation;
        
        try {
            $adapter = ProviderFactory::make($modelName ?? 'ollama');
            
            $payload = [
                ['role' => 'system', 'content' => 'You are a JSON-only response bot. Output only valid JSON, nothing else.'],
                ['role' => 'user', 'content' => $prompt]
            ];
            
            $fullResponse = '';
            $stream = $adapter->generateResponse($payload);
            
            foreach ($stream as $chunk) {
                if (isset($chunk['content'])) {
                    $fullResponse .= $chunk['content'];
                }
            }
            
            return $this->parseResponse($fullResponse);
            
        } catch (\Exception $e) {
            Log::error("EntityExtractor Error: " . $e->getMessage());
            return $this->fallbackExtraction($userMessage, $botResponse);
        }
    }

    /**
     * Parse LLM response into structured data.
     */
    private function parseResponse(string $response): array
    {
        $response = trim($response);
        
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $response, $matches)) {
            $response = $matches[1];
        }
        
        $startBrace = strpos($response, '{');
        $endBrace = strrpos($response, '}');
        
        if ($startBrace !== false && $endBrace !== false) {
            $response = substr($response, $startBrace, $endBrace - $startBrace + 1);
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("EntityExtractor: Failed to parse JSON response", [
                'error' => json_last_error_msg(),
                'response' => substr($response, 0, 500)
            ]);
            return ['entities' => [], 'relationships' => []];
        }
        
        return $this->validateAndClean($data);
    }

    /**
     * Validate and clean extracted data.
     */
    private function validateAndClean(array $data): array
    {
        $validTypes = ['topic', 'concept', 'person', 'action', 'entity'];
        $entities = [];
        $relationships = [];
        
        if (isset($data['entities']) && is_array($data['entities'])) {
            foreach ($data['entities'] as $entity) {
                if (!isset($entity['text']) || empty(trim($entity['text']))) {
                    continue;
                }
                
                $text = $this->normalizeText($entity['text']);
                $type = in_array($entity['type'] ?? '', $validTypes) 
                    ? $entity['type'] 
                    : 'concept';
                
                if (strlen($text) >= 2 && strlen($text) <= 100) {
                    $entities[] = [
                        'text' => $text,
                        'type' => $type
                    ];
                }
            }
        }
        
        if (isset($data['relationships']) && is_array($data['relationships'])) {
            foreach ($data['relationships'] as $rel) {
                if (!isset($rel['from']) || !isset($rel['to'])) {
                    continue;
                }
                
                $from = $this->normalizeText($rel['from']);
                $to = $this->normalizeText($rel['to']);
                $relation = $this->normalizeText($rel['relation'] ?? 'related_to');
                
                if (!empty($from) && !empty($to) && $from !== $to) {
                    $relationships[] = [
                        'from' => $from,
                        'to' => $to,
                        'relation' => $relation
                    ];
                }
            }
        }
        
        return [
            'entities' => $entities,
            'relationships' => $relationships
        ];
    }

    /**
     * Normalize entity text.
     */
    private function normalizeText(string $text): string
    {
        $text = trim(strtolower($text));
        $text = preg_replace('/\s+/', ' ', $text);
        return $text;
    }

    /**
     * Fallback extraction using regex patterns when LLM fails.
     */
    private function fallbackExtraction(string $userMessage, string $botResponse): array
    {
        $combined = $userMessage . ' ' . $botResponse;
        $entities = [];
        
        $patterns = [
            'topic' => '/\b(wordpress|laravel|php|javascript|python|api|database|server|docker|aws|github)\b/i',
            'concept' => '/\b(authentication|authorization|caching|optimization|integration|deployment|migration)\b/i',
            'action' => '/\b(create|update|delete|deploy|install|configure|implement|optimize)\b/i',
        ];
        
        foreach ($patterns as $type => $pattern) {
            if (preg_match_all($pattern, $combined, $matches)) {
                foreach (array_unique($matches[0]) as $match) {
                    $entities[] = [
                        'text' => strtolower($match),
                        'type' => $type
                    ];
                }
            }
        }
        
        if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\b/', $combined, $matches)) {
            foreach (array_unique($matches[0]) as $match) {
                if (strlen($match) <= 50) {
                    $entities[] = [
                        'text' => strtolower($match),
                        'type' => 'entity'
                    ];
                }
            }
        }
        
        return [
            'entities' => array_slice($entities, 0, 10),
            'relationships' => []
        ];
    }

    /**
     * Extract keywords from a single message (for query matching).
     */
    public function extractKeywords(string $text): array
    {
        $cleanText = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', strtolower($text));
        $words = array_unique(explode(' ', $cleanText));
        
        $stopwords = [
            'yang', 'dan', 'di', 'ke', 'dari', 'ini', 'itu', 'untuk', 'dengan', 'dalam',
            'pada', 'adalah', 'sebagai', 'apakah', 'bagaimana', 'tolong', 'saya', 'kamu',
            'bisa', 'tidak', 'ada', 'apa', 'contoh', 'tentang', 'ingin', 'dulu', 'juga',
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should',
            'can', 'you', 'your', 'me', 'my', 'we', 'our', 'they', 'their', 'he', 'she', 'it',
            'this', 'that', 'what', 'which', 'who', 'how', 'please', 'using', 'use',
        ];
        
        $keywords = array_filter($words, function($word) use ($stopwords) {
            return strlen($word) > 2 && !in_array($word, $stopwords);
        });
        
        return array_values($keywords);
    }
}
