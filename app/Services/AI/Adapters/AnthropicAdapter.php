<?php

namespace App\Services\AI\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicAdapter implements BaseAdapter
{
    protected string $apiKey;
    protected string $modelName;
    protected string $baseUrl;
    protected string $systemPrompt;

    public function __construct(string $apiKey, string $modelName, string $systemPrompt = '')
    {
        $this->apiKey = $apiKey;
        $this->modelName = $modelName;
        $this->systemPrompt = $systemPrompt;
        $this->baseUrl = config('services.anthropic.base_url', 'https://api.anthropic.com/v1');
    }

    public function generateResponse(array $payload): \Generator
    {
        $anthropicMessages = [];
        $systemText = $this->systemPrompt;

        foreach ($payload as $msg) {
            if ($msg['role'] === 'system') {
                $systemText .= "\n\n" . $msg['content'];
                continue;
            }

            $contentBlocks = [];
            
            if (!empty($msg['images']) && $msg['role'] === 'user') {
                foreach ($msg['images'] as $imgBase64) {
                    if (preg_match('/^data:image\/(.*?);base64,(.*)$/', $imgBase64, $matches)) {
                        $mimeType = "image/" . $matches[1];
                        $base64Data = $matches[2];
                    } else {
                        $mimeType = "image/jpeg";
                        $base64Data = $imgBase64;
                    }

                    $contentBlocks[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mimeType,
                            'data' => $base64Data
                        ]
                    ];
                }
            }

            $contentBlocks[] = [
                'type' => 'text',
                'text' => $msg['content']
            ];

            // If there's only one text block, we can send it as a string to simplify unless it's multimodal
            if (count($contentBlocks) === 1 && $contentBlocks[0]['type'] === 'text') {
                $finalContent = $contentBlocks[0]['text'];
            } else {
                $finalContent = $contentBlocks;
            }

            $anthropicMessages[] = [
                'role' => $msg['role'],
                'content' => $finalContent
            ];
        }

        $requestPayload = [
            'model' => $this->modelName,
            'max_tokens' => 8192,
            'system' => trim($systemText),
            'messages' => $anthropicMessages,
            'stream' => true,
        ];

        Log::info("Anthropic Request", ['model' => $this->modelName]);

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
            'accept' => 'text/event-stream'
        ])
        ->withOptions(['stream' => true, 'verify' => false])
        ->connectTimeout(15)
        ->timeout(300)
        ->post($this->baseUrl . '/messages', $requestPayload);

        if (!$response->successful()) {
            $errorBody = $response->json();
            $errorMessage = $errorBody['error']['message'] ?? 'Unknown error from Anthropic API.';
            Log::error("Anthropic API Error: " . $response->body());
            throw new \Exception("Anthropic Error: " . $errorMessage);
        }

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);
            
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);
                if (empty($line)) continue;
                
                // Anthropic sends event types before data lines
                if (str_starts_with($line, 'event: ')) {
                    $eventType = substr($line, 7);
                    continue;
                }

                if (str_starts_with($line, 'data: ')) {
                    $json = substr($line, 6);
                    $data = json_decode($json, true);

                    if (!$data) continue;

                    if (($data['type'] ?? '') === 'content_block_delta') {
                        $delta = $data['delta'] ?? [];
                        if (($delta['type'] ?? '') === 'text_delta') {
                            yield ['content' => $delta['text']];
                        }
                    }
                }
            }
        }
    }
}
