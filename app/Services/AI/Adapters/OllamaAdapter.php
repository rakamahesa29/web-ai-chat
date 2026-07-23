<?php

namespace App\Services\AI\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaAdapter implements BaseAdapter
{
    protected string $modelName;
    protected string $baseUrl;

    public function __construct(string $modelName)
    {
        $this->modelName = $modelName;
        $this->baseUrl = config('services.ollama.base_url', 'http://127.0.0.1:11434');
    }

    /**
     * Generate streaming response from Ollama API.
     */
    public function generateResponse(array $payload): \Generator|array
    {
        $systemMessage = null;
        $otherMessages = [];

        foreach ($payload as $msg) {
            if ($msg['role'] === 'system') {
                $systemMessage = $msg;
            } else {
                $otherMessages[] = $msg;
            }
        }

        $finalPayload = [];
        if ($systemMessage) {
            $finalPayload[] = $systemMessage;
        }
        $finalPayload = array_merge($finalPayload, $otherMessages);

        $requestPayload = [
            'model' => $this->modelName,
            'messages' => $finalPayload,
            'stream' => true,
            'options' => [
                'temperature' => config('services.ollama.temperature', 0.60),
                'num_ctx' => config('services.ollama.context_length', 32768),
            ],
        ];

        $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->withOptions([
                'stream' => true,
                'verify' => false,
            ])
            ->timeout(config('services.ollama.timeout', 300))
            ->post($this->baseUrl . '/api/chat', $requestPayload);

        if (!$response->successful()) {
            Log::error("Ollama API Error [{$this->modelName}]: " . $response->body());
            throw new \Exception("Failed to connect to Ollama API.");
        }

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $finishReason = null;
        $promptTokens = 0;
        $completionTokens = 0;

        while (!$stream->eof()) {
            $buffer .= $stream->read(1024);
            
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);
                if (empty($line)) continue;

                $data = json_decode($line, true);

                if ($data === null) continue;

                if (isset($data['message']['content'])) {
                    yield [
                        'content' => $data['message']['content'],
                        'done' => false
                    ];
                }

                if (isset($data['done']) && $data['done'] === true) {
                    $finishReason = $data['done_reason'] ?? 'stop';
                    
                    if (isset($data['prompt_eval_count'])) {
                        $promptTokens = $data['prompt_eval_count'];
                    }
                    if (isset($data['eval_count'])) {
                        $completionTokens = $data['eval_count'];
                    }
                    
                    // Log completion stats for debugging
                    Log::info("Ollama Response Complete", [
                        'model' => $this->modelName,
                        'finish_reason' => $finishReason,
                        'prompt_tokens' => $promptTokens,
                        'completion_tokens' => $completionTokens,
                        'truncated' => $finishReason === 'length', // True if hit token limit
                    ]);
                    
                    break 2;
                }
            }
        }

        yield [
            'content' => '',
            'done' => true,
            'finish_reason' => $finishReason,
            'tokens' => $completionTokens,
            'prompt_tokens' => $promptTokens 
        ];
    }
}
