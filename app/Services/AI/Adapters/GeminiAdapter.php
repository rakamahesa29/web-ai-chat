<?php

namespace App\Services\AI\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiAdapter implements BaseAdapter
{
    protected string $modelName;
    protected string $baseUrl;
    protected ?string $systemPrompt;

    public function __construct(string $modelName = 'gemini-pro', ?string $systemPrompt = null)
    {
        $this->modelName = $modelName;
        $this->systemPrompt = $systemPrompt;
        $this->baseUrl = config('services.gemini.base_url', 'http://host.docker.internal:8095/v1');
    }

    public function generateResponse(array $payload): \Generator
    {
        $messages = [];

        if (!empty($this->systemPrompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->systemPrompt,
            ];
        }

        foreach ($payload as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        $requestPayload = [
            'model' => $this->modelName,
            'messages' => $messages,
            'stream' => true,
        ];

        Log::info("Gemini Subscription Request", [
            'model' => $this->modelName,
            'base_url' => $this->baseUrl,
            'messages_count' => count($messages),
        ]);

        $response = Http::withHeaders([
            'Accept' => 'text/event-stream',
            'Content-Type' => 'application/json',
        ])
        ->withOptions([
            'stream' => true,
            'verify' => false,
        ])
        ->connectTimeout(15)
        ->timeout(600)
        ->post($this->baseUrl . '/chat/completions', $requestPayload);

        if (!$response->successful()) {
            $errorBody = $response->json();
            $errorMessage = $errorBody['error']['message'] ?? $errorBody['error'] ?? 'Unknown error from Gemini Bridge.';
            Log::error("Gemini Bridge API Error [{$this->modelName}]: " . $response->body());
            throw new \Exception("Gemini Bridge Error: " . $errorMessage);
        }

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $finishReason = null;
        $promptTokens = 0;
        $completionTokens = 0;

        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);
                if (empty($line)) continue;
                if ($line === 'data: [DONE]') break 2;

                if (str_starts_with($line, 'data: ')) {
                    $json = substr($line, 6);
                    $data = json_decode($json, true);

                    if (!$data || !isset($data['choices'][0])) continue;

                    $delta = $data['choices'][0]['delta'] ?? [];

                    if (isset($delta['content']) && $delta['content'] !== '') {
                        yield [
                            'type' => 'chunk',
                            'content' => $delta['content'],
                            'done' => false
                        ];
                    }

                    if (isset($data['choices'][0]['finish_reason']) && $data['choices'][0]['finish_reason'] !== null) {
                        $finishReason = $data['choices'][0]['finish_reason'];
                    }

                    if (isset($data['usage']) && $data['usage'] !== null) {
                        $promptTokens = $data['usage']['prompt_tokens'] ?? 0;
                        $completionTokens = $data['usage']['completion_tokens'] ?? 0;
                    }
                }
            }
        }

        yield [
            'type' => 'done',
            'content' => '',
            'done' => true,
            'finish_reason' => $finishReason ?? 'stop',
            'tokens' => $completionTokens,
            'prompt_tokens' => $promptTokens
        ];
    }
}
