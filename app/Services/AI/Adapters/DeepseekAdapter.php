<?php

namespace App\Services\AI\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepseekAdapter implements BaseAdapter
{
    protected string $apiKey;
    protected string $modelName;
    protected string $baseUrl;
    protected bool $proMode;

    public function __construct(string $apiKey, string $modelName, bool $proMode = false)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = config('services.deepseek.base_url', 'https://api.deepseek.com/v1');
        $this->proMode = $proMode;

        // Pro mode uses deepseek-v4-pro with reasoning/thinking enabled
        $this->modelName = $proMode
            ? config('services.deepseek.pro_model', 'deepseek-v4-pro')
            : $modelName;
    }

    public function generateResponse(array $payload): \Generator|array
    {
        $requestPayload = [
            'model' => $this->modelName,
            'messages' => $this->prepareMessages($payload),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        if ($this->proMode) {
            $requestPayload['thinking'] = ['type' => 'enabled'];
            $requestPayload['reasoning_effort'] = config('services.deepseek.pro_reasoning_effort', 'high');
        } else {
            $requestPayload['temperature'] = 0.7;
        }

        Log::info("DeepSeek Request", [
            'model' => $this->modelName,
            'pro_mode' => $this->proMode,
        ]);

        $response = Http::withToken($this->apiKey)
            ->withHeaders([
                'Accept' => 'text/event-stream'
            ])
            ->withOptions([
                'stream' => true,
                'verify' => false,
            ])
            ->timeout(600)
            ->post($this->baseUrl . '/chat/completions', $requestPayload);

        if (!$response->successful()) {
            Log::error("DeepSeek API Error [{$this->modelName}]: " . $response->body());
            throw new \Exception("Failed to connect to DeepSeek API.");
        }

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $finishReason = null;
        $promptTokens = 0;
        $completionTokens = 0;
        $isThinking = false;
        $thinkingEmitted = false;

        while (!$stream->eof()) {
            $buffer .= $stream->read(1024);
            
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

                    // Handle reasoning/thinking content (Pro mode)
                    if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                        if (!$thinkingEmitted) {
                            $thinkingEmitted = true;
                            $isThinking = true;
                            yield [
                                'content' => "<details class=\"ds-thinking\">\n<summary>💭 DeepSeek Thinking...</summary>\n\n",
                                'done' => false,
                            ];
                        }
                        yield [
                            'content' => $delta['reasoning_content'],
                            'done' => false,
                        ];
                        continue;
                    }

                    // Transition from thinking to actual response
                    if ($isThinking && isset($delta['content']) && $delta['content'] !== '') {
                        $isThinking = false;
                        yield [
                            'content' => "\n\n</details>\n\n",
                            'done' => false,
                        ];
                    }

                    // Handle main content
                    if (isset($delta['content']) && $delta['content'] !== '') {
                        yield [
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
     * Prepare messages for Pro mode — remove system message temperature constraints
     * since thinking mode doesn't support temperature parameter.
     */
    private function prepareMessages(array $payload): array
    {
        if (!$this->proMode) {
            return $payload;
        }

        // DeepSeek Pro (thinking mode) requires system message as first user message
        // if the API doesn't support system role with thinking enabled
        return $payload;
    }
}
