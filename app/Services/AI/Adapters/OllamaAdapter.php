<?php

namespace App\Services\AI\Adapters;

use Illuminate\Support\Facades\Log;

class OllamaAdapter implements BaseAdapter
{
    use StreamsHttpResponse;

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
            'keep_alive' => '1h',
            'options' => [
                'temperature' => config('services.ollama.temperature', 0.60),
                'num_ctx' => config('services.ollama.context_length', 32768),
            ],
        ];

        $finishReason = null;
        $promptTokens = 0;
        $completionTokens = 0;
        $isThinking = false;
        $thinkingEmitted = false;
        $contentEmitted = false;

        $timeout = (int) config('services.ollama.timeout', 300);

        foreach ($this->streamLines($this->baseUrl . '/api/chat', $requestPayload, [], $timeout, 'Ollama API') as $line) {
            $data = json_decode($line, true);
            if ($data === null) {
                continue;
            }

            foreach ($this->processChunkData($data, $isThinking, $thinkingEmitted, $contentEmitted, $finishReason, $promptTokens, $completionTokens) as $yieldData) {
                if (isset($yieldData['type']) && $yieldData['type'] === 'done_signal') {
                    continue 2; // sentinel — final chunk processed, keep draining stream until socket closes
                }
                yield $yieldData;
            }
        }

        // Close thinking block if still open
        if ($isThinking) {
            yield [
                'content' => "\n\n</details>\n\n",
                'done' => false,
            ];
        }

        yield [
            'content' => '',
            'done' => true,
            'finish_reason' => $finishReason,
            'tokens' => $completionTokens,
            'prompt_tokens' => $promptTokens
        ];
    }

    /**
     * Process a single JSON chunk from Ollama and yield the formatted data.
     */
    private function processChunkData(array $data, &$isThinking, &$thinkingEmitted, &$contentEmitted, &$finishReason, &$promptTokens, &$completionTokens): \Generator
    {
        // Handle reasoning/thinking content
        if (isset($data['message']['thinking']) && $data['message']['thinking'] !== '') {
            if (!$thinkingEmitted) {
                $thinkingEmitted = true;
                $isThinking = true;
                yield [
                    'content' => "<details class=\"ds-thinking\">\n<summary>💭 AI Thinking...</summary>\n\n",
                    'done' => false
                ];
            }
            yield [
                'content' => $data['message']['thinking'],
                'done' => false
            ];
        }

        // Transition from thinking to actual response
        if ($isThinking && isset($data['message']['content']) && $data['message']['content'] !== '') {
            $isThinking = false;
            yield [
                'content' => "\n\n</details>\n\n",
                'done' => false
            ];
        }

        // Handle main content
        if (isset($data['message']['content']) && $data['message']['content'] !== '') {
            $contentStr = $data['message']['content'];
            $contentEmitted = true;

            // Handle <think> tags if model outputs them in content instead of thinking field
            if (strpos($contentStr, '<think>') !== false) {
                $isThinking = true;
                $contentStr = str_replace('<think>', "<details class=\"ds-thinking\">\n<summary>💭 AI Thinking...</summary>\n\n", $contentStr);
            }
            if (strpos($contentStr, '</think>') !== false) {
                $isThinking = false;
                $contentStr = str_replace('</think>', "\n\n</details>\n\n", $contentStr);
            }

            yield [
                'content' => $contentStr,
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
                'truncated' => $finishReason === 'length',
            ]);

            yield [
                'type' => 'done_signal'
            ];
        }
    }
}
