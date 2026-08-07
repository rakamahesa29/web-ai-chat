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
    protected ?string $queryType;

    protected bool $rawReasoning;
    protected ?string $responseFormat;

    public function __construct(string $apiKey, string $modelName, bool $proMode = false, ?string $queryType = null, bool $rawReasoning = false, ?string $responseFormat = null)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = config('services.deepseek.base_url', 'https://api.deepseek.com/v1');
        $this->proMode = $proMode;
        $this->queryType = $queryType;
        $this->rawReasoning = $rawReasoning;
        $this->responseFormat = $responseFormat;

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

        if ($this->responseFormat) {
            $requestPayload['response_format'] = ['type' => $this->responseFormat];
        }

        if ($this->proMode) {
            $requestPayload['thinking'] = ['type' => 'enabled'];
            $requestPayload['reasoning_effort'] = $this->resolveReasoningEffort();
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
            ->connectTimeout(15) // Waktu maksimal untuk menghubungkan
            ->timeout(120) // Waktu maksimal menunggu respon (2 menit)
            ->post($this->baseUrl . '/chat/completions', $requestPayload);

        if (!$response->successful()) {
            $errorBody = $response->json();
            $errorMessage = $errorBody['error']['message'] ?? 'Unknown error from DeepSeek API.';
            Log::error("DeepSeek API Error [{$this->modelName}]: " . $response->body());
            throw new \Exception("DeepSeek Error: " . $errorMessage);
        }

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $finishReason = null;
        $promptTokens = 0;
        $completionTokens = 0;
        $isThinking = false;
        $thinkingEmitted = false;
        $contentEmitted = false;

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

                    // Handle reasoning/thinking content (Pro mode)
                    if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                        if (!$thinkingEmitted) {
                            $thinkingEmitted = true;
                            $isThinking = true;
                            if (!$this->rawReasoning) {
                                yield [
                                    'content' => "<details class=\"ds-thinking\">\n<summary>💭 DeepSeek Thinking...</summary>\n\n",
                                    'done' => false,
                                ];
                            } else {
                                yield [
                                    'type' => 'thinking_start',
                                    'content' => '',
                                    'done' => false,
                                ];
                            }
                        }
                        yield [
                            'type' => $this->rawReasoning ? 'thinking' : 'chunk',
                            'content' => $delta['reasoning_content'],
                            'done' => false,
                        ];
                    }

                    // Transition from thinking to actual response
                    if ($isThinking && isset($delta['content']) && $delta['content'] !== '') {
                        $isThinking = false;
                        if (!$this->rawReasoning) {
                            yield [
                                'content' => "\n\n</details>\n\n",
                                'done' => false,
                            ];
                        } else {
                            yield [
                                'type' => 'thinking_end',
                                'content' => '',
                                'done' => false,
                            ];
                        }
                    }

                    // Handle main content
                    if (isset($delta['content']) && $delta['content'] !== '') {
                        $contentEmitted = true;
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

        // Close thinking block if still open
        if ($isThinking) {
            if (!$this->rawReasoning) {
                yield [
                    'content' => "\n\n</details>\n\n",
                    'done' => false,
                ];
            } else {
                yield [
                    'type' => 'thinking_end',
                    'content' => '',
                    'done' => false,
                ];
            }
        }

        if ($thinkingEmitted && !$contentEmitted) {
            yield [
                'type' => 'chunk',
                'content' => "\n\n*(Catatan: Model hanya mengembalikan proses reasoning tanpa jawaban akhir. Silakan baca di dalam blok thinking di atas.)*",
                'done' => false
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
     * Resolve reasoning effort based on query classification.
     * Simple queries don't need deep reasoning chains.
     */
    private function resolveReasoningEffort(): string
    {
        $default = config('services.deepseek.pro_reasoning_effort', 'high');
        
        if (!$this->queryType) {
            return $default;
        }

        return match($this->queryType) {
            'general' => 'low',
            'latest_data' => 'medium',
            'domain_specific' => $default,
            default => $default,
        };
    }

    /**
     * Prepare messages for Pro mode.
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
