<?php

namespace App\Services\AI;

use App\Models\Room;
use App\Services\AI\Adapters\BaseAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AgentProcessor — Client-side execution architecture.
 *
 * The backend orchestrates the AI tool-calling loop but file operations
 * (create/edit/delete) are executed by the SwiftUI client locally.
 *
 * Two-phase flow:
 *   1. process()  — runs AI → yields tool requests + state_token via SSE → saves state
 *   2. continue() — loads state → feeds tool results to AI → repeats or yields final response
 */
class AgentProcessor
{
    private ?string $workspacePath = null;
    private int $maxIterations = 10;
    private string $stateTokenCachePrefix = 'agent_state_';

    // ──────────────────────────────────────────────
    //  Phase 1: Initial agent request
    // ──────────────────────────────────────────────

    /**
     * Run the AI loop. If AI produces tool calls, yield them as requests
     * and save state for client to resume later.
     */
    public function process(
        int $roomId,
        string $modelName,
        string $workspacePath,
        string $userMessage,
        array $conversationHistory,
        array $options = []
    ): \Generator {
        $this->workspacePath = $workspacePath;

        yield [
            'type'   => 'meta',
            'status' => 'agent_start',
            'message' => 'Agent mode — ' . basename($workspacePath),
        ];

        $systemPrompt = $this->buildAgentSystemPrompt();
        $messages   = [];
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];

        foreach ($conversationHistory as $msg) {
            $role = ($msg['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => $msg['content'] ?? ''];
        }
        $messages[] = ['role' => 'user', 'content' => $this->buildUserPrompt($userMessage)];

        $phase1Options = $options;
        if (in_array($modelName, ['deepseek', 'deepseek-reasoner', 'deepseek-chat'])) {
            $phase1Options['deepseek_pro'] = false; // Force Flash (chat) for fast planning
        }
        $adapter = ProviderFactory::make($modelName, $phase1Options);
        $iteration = 0;

        while ($iteration < $this->maxIterations) {
            $iteration++;

            yield ['type' => 'meta', 'status' => 'agent_thinking', 'iteration' => $iteration];

            $aiResponse = '';
            yield from $this->streamAndCollectResponse($adapter, $messages, $aiResponse);
            if (empty(trim($aiResponse))) {
                yield ['content' => '❌ Agent Error: AI returned empty response.', 'done' => true];
                return;
            }

            $toolCalls = $this->extractToolCalls($aiResponse);

            if (empty($toolCalls)) {
                // No tool calls — final response
                yield from $this->streamFinalResponse($aiResponse);
                return;
            }

            // Emit the AI's reasoning/thinking (strip tool call blocks)
            $thinking = $this->stripToolCalls($aiResponse);
            if (!empty(trim($thinking))) {
                yield ['type' => 'meta', 'status' => 'agent_reasoning'];
                yield from $this->streamChunks($thinking);
            }

            // Emit planned operations summary
            $planSummary = $this->buildPlanSummary($toolCalls);
            yield ['content' => $planSummary, 'done' => false];

            // Save state and yield tool requests for client execution
            $stateToken = $this->saveState([
                'room_id'        => $roomId,
                'model_name'     => $modelName,
                'workspace_path' => $workspacePath,
                'messages'       => $messages,
                'ai_response'    => $aiResponse,
                'tool_calls'     => $toolCalls,
                'iteration'      => $iteration,
                'options'        => $options,
            ]);

            yield [
                'type'        => 'meta',
                'status'      => 'agent_tool_requests',
                'state_token' => $stateToken,
                'requests'    => $toolCalls,
            ];

            yield ['type' => 'meta', 'status' => 'agent_waiting', 'state_token' => $stateToken];

            Log::info("AgentProcessor: phase1 done, awaiting client execution", [
                'state_token' => $stateToken,
                'tool_count'  => count($toolCalls),
                'iteration'   => $iteration,
            ]);

            return; // End stream — client will continue via agent-continue
        }

        yield ['content' => '⚠️ Agent reached max iterations.', 'done' => true];
    }

    // ──────────────────────────────────────────────
    //  Phase 2: Client returns tool results
    // ──────────────────────────────────────────────

    /**
     * Resume the agent loop after client has executed tool calls.
     */
    public function continue(string $stateToken, array $toolResults): \Generator
    {
        $state = $this->loadState($stateToken);
        if (!$state) {
            yield ['content' => '❌ Agent session expired. Please try again.', 'done' => true];
            return;
        }

        $this->workspacePath = $state['workspace_path'];
        $messages   = $state['messages'];
        $aiResponse = $state['ai_response'];
        $iteration  = $state['iteration'];
        $options    = $state['options'];
        $modelName  = $state['model_name'];

        yield [
            'type'    => 'meta',
            'status'  => 'agent_resumed',
            'iteration' => $iteration,
        ];

        // Emit tool execution results as a visible summary
        yield ['content' => $this->buildToolResultSummary($toolResults), 'done' => false];

        // Feed AI response + tool results back into conversation
        $messages[] = ['role' => 'assistant', 'content' => $aiResponse];
        $messages[] = ['role' => 'user', 'content' => $this->formatToolResults($toolResults)];

        $phase2Options = $options;
        if (in_array($modelName, ['deepseek', 'deepseek-reasoner', 'deepseek-chat'])) {
            $phase2Options['deepseek_pro'] = true; // Use Pro (reasoner) for complex synthesis
        }
        $adapter = ProviderFactory::make($modelName, $phase2Options);

        yield ['type' => 'meta', 'status' => 'agent_analyzing', 'message' => 'Analyzing tool results…'];

        while ($iteration < $this->maxIterations) {
            $iteration++;

            yield ['type' => 'meta', 'status' => 'agent_thinking', 'iteration' => $iteration];

            $aiResponse = '';
            yield from $this->streamAndCollectResponse($adapter, $messages, $aiResponse);
            if (empty(trim($aiResponse))) {
                yield ['content' => '❌ Agent Error: AI returned empty response.', 'done' => true];
                Cache::forget($this->stateTokenCachePrefix . $stateToken);
                return;
            }

            $toolCalls = $this->extractToolCalls($aiResponse);

            if (empty($toolCalls)) {
                // Final response
                Cache::forget($this->stateTokenCachePrefix . $stateToken);
                yield from $this->streamFinalResponse($aiResponse);
                return;
            }

            // Emit reasoning + plan summary for this iteration
            $thinking = $this->stripToolCalls($aiResponse);
            if (!empty(trim($thinking))) {
                yield ['type' => 'meta', 'status' => 'agent_reasoning'];
                yield from $this->streamChunks($thinking);
            }
            $planSummary = $this->buildPlanSummary($toolCalls);
            yield ['content' => $planSummary, 'done' => false];

            // More tool calls — save state again
            $stateToken = $this->saveState([
                'room_id'        => $state['room_id'],
                'model_name'     => $modelName,
                'workspace_path' => $this->workspacePath,
                'messages'       => $messages,
                'ai_response'    => $aiResponse,
                'tool_calls'     => $toolCalls,
                'iteration'      => $iteration,
                'options'        => $options,
            ]);

            yield [
                'type'        => 'meta',
                'status'      => 'agent_tool_requests',
                'state_token' => $stateToken,
                'requests'    => $toolCalls,
            ];

            yield ['type' => 'meta', 'status' => 'agent_waiting', 'state_token' => $stateToken];

            Log::info("AgentProcessor: phase2 yielding more tools", [
                'state_token' => $stateToken,
                'tool_count'  => count($toolCalls),
            ]);

            return; // End stream — await client again
        }

        Cache::forget($this->stateTokenCachePrefix . $stateToken);
        yield ['content' => '⚠️ Agent reached max iterations.', 'done' => true];
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    private function streamFinalResponse(string $content): \Generator
    {
        $chunkSize = 10;
        $len = mb_strlen($content);
        for ($i = 0; $i < $len; $i += $chunkSize) {
            yield ['content' => mb_substr($content, $i, $chunkSize), 'done' => false];
        }
        yield ['content' => '', 'done' => true];
    }

    private function buildUserPrompt(string $message): string
    {
        return "USER REQUEST: {$message}\n\n"
            . "If the user asks you to create, edit, or delete files, use the appropriate tool. "
            . "Do NOT write code in chat — use the write_file tool.";
    }

    private function buildAgentSystemPrompt(): string
    {
        $dirListing = $this->getDirectoryListing($this->workspacePath);

        return <<<PROMPT
You are an AI coding agent. Your workspace:

**Path:** {$this->workspacePath}

**Directory Structure:**
```
{$dirListing}
```

## Tools

Use `<tool_call>` tags with JSON to operate on files. The tools will be executed on the user's machine.

### write_file
Create or overwrite a file.
```json
{"tool": "write_file", "path": "relative/path.ext", "content": "file contents"}
```

### read_file
Read a file's contents.
```json
{"tool": "read_file", "path": "relative/path.ext"}
```

### list_files
List directory contents.
```json
{"tool": "list_files", "path": "relative/path/"}
```

### delete_file
Delete a file.
```json
{"tool": "delete_file", "path": "relative/path.ext"}
```

## Rules

1. **All paths are relative to workspace.** E.g. `"path": "login.html"` creates at workspace root.
2. **Use write_file to create files.** Do NOT output code in chat.
3. **Be concise.** After receiving tool results, give a brief confirmation.
4. **Respond in the user's language.**
5. You can use multiple tool calls in one response.
6. **JSON must be valid.** Escape double quotes in content as `\"` and use `\n` for newlines. Keep tool call JSON compact.

## Example

User: "buatkan index.html dengan Hello World"
Agent: <tool_call>{"tool": "write_file", "path": "index.html", "content": "<!DOCTYPE html>\n<html>\n<body><h1>Hello World</h1></body>\n</html>"}</tool_call>

After tool results: "✅ index.html telah dibuat."
PROMPT;
    }

    private function getDirectoryListing(string $path, int $maxDepth = 3, int $depth = 0): string
    {
        if ($depth > $maxDepth || !is_dir($path)) return '';

        $items = scandir($path);
        if (!$items) return '(empty)';

        $dirs  = [];
        $files = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;
            is_dir("$path/$item") ? $dirs[] = $item : $files[] = $item;
        }
        sort($dirs); sort($files);

        $lines = [];
        $prefix = str_repeat('  ', $depth);
        $count = 0;
        foreach (array_merge($dirs, $files) as $item) {
            if ($count++ >= 50) { $lines[] = "$prefix... (truncated)"; break; }
            $full = "$path/$item";
            if (is_dir($full)) {
                $lines[] = $prefix . "📁 $item/";
                $lines[] = $this->getDirectoryListing($full, $maxDepth, $depth + 1);
            } else {
                $size = filesize($full);
                $sizeStr = $size !== false ? ' (' . $this->formatSize($size) . ')' : '';
                $lines[] = $prefix . "📄 $item" . $sizeStr;
            }
        }
        return implode("\n", array_filter($lines));
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    private function streamAndCollectResponse(BaseAdapter $adapter, array $messages, &$fullResponse): \Generator
    {
        $fullResponse = '';
        $stream = $adapter->generateResponse($messages);
        foreach ($stream as $chunk) {
            if (is_array($chunk)) {
                $type = $chunk['type'] ?? 'chunk';
                if (in_array($type, ['thinking_start', 'thinking', 'thinking_end'])) {
                    yield $chunk; // stream thinking to client live
                } else if ($type === 'chunk' && isset($chunk['content'])) {
                    $fullResponse .= $chunk['content'];
                }
            }
            if (is_array($chunk) && isset($chunk['done']) && $chunk['done'] === true) break;
        }
    }

    private function extractToolCalls(string $response): array
    {
        $calls = [];

        // Match <tool_call>...</tool_call> blocks
        if (preg_match_all('/<tool_call>\s*(.*?)\s*<\/tool_call>/s', $response, $matches)) {
            foreach ($matches[1] as $rawJson) {
                $data = json_decode(trim($rawJson), true);
                if ($data && isset($data['tool'])) {
                    $calls[] = $data;
                    continue;
                }
                // JSON parse failed — try field-by-field extraction
                $lastError = json_last_error_msg();
                Log::warning("AgentProcessor: json_decode failed for tool_call block", [
                    'error' => $lastError,
                    'preview' => substr(trim($rawJson), 0, 200),
                ]);
                $salvaged = $this->salvageJsonFields(trim($rawJson));
                if ($salvaged && isset($salvaged['tool'])) {
                    $calls[] = $salvaged;
                    Log::info("AgentProcessor: salvaged tool call via field extraction", [
                        'tool' => $salvaged['tool'],
                        'path' => $salvaged['path'] ?? '',
                    ]);
                }
            }
        }

        // Fallback: bare JSON tool objects
        if (empty($calls)) {
            if (preg_match_all('/\{[^{}]*"tool"\s*:\s*"(write_file|read_file|delete_file|list_files)"[^{}]*\}/s', $response, $matches)) {
                foreach ($matches[0] as $json) {
                    $data = json_decode($json, true);
                    if ($data && isset($data['tool'])) $calls[] = $data;
                }
            }
        }

        return $calls;
    }

    /**
     * Extract tool/path/content fields from malformed JSON using regex.
     * Handles content strings that contain unescaped quotes, newlines, or braces.
     */
    private function salvageJsonFields(string $raw): ?array
    {
        // Extract "tool": "value"
        if (!preg_match('/"tool"\s*:\s*"(write_file|read_file|delete_file|list_files)"/', $raw, $m)) {
            return null;
        }
        $result = ['tool' => $m[1]];

        // Extract "path": "value"
        if (preg_match('/"path"\s*:\s*"([^"]*)"/', $raw, $m)) {
            $result['path'] = $m[1];
        }

        // Extract "content": "..." — handles multiline content
        // Strategy: find "content": " then capture everything until the last "} or " at end
        if (preg_match('/"content"\s*:\s*"(.*)"\s*\}$/s', $raw, $m)) {
            $result['content'] = stripslashes($m[1]);
        } elseif (preg_match('/"content"\s*:\s*"(.*)"$/s', $raw, $m)) {
            $result['content'] = stripslashes($m[1]);
        }

        return $result;
    }

    private function formatToolResults(array $results): string
    {
        $text = "<tool_results>\n";
        foreach ($results as $r) {
            $icon = ($r['status'] ?? 'error') === 'success' ? '✅' : '❌';
            $text .= "{$icon} [{$r['tool']}] {$r['path']}: {$r['message']}\n";
            if (isset($r['content'])) {
                $max = 8000;
                $c = strlen($r['content']) > $max ? substr($r['content'], 0, $max) . "\n...(truncated)" : $r['content'];
                $text .= "```\n{$c}\n```\n";
            }
            if (isset($r['files'])) {
                foreach ($r['files'] as $f) {
                    $ic = ($f['type'] ?? '') === 'dir' ? '📁' : '📄';
                    $sz = isset($f['size']) ? " ({$f['size']} bytes)" : '';
                    $text .= "  {$ic} {$f['name']}{$sz}\n";
                }
            }
        }
        $text .= "</tool_results>\n\nProvide your final response based on these results. Be concise.";
        return $text;
    }

    // ──────────────────────────────────────────────
    //  User-visible progress helpers
    // ──────────────────────────────────────────────

    /**
     * Remove <tool_call> blocks from AI response, returning only the human-readable text.
     */
    private function stripToolCalls(string $response): string
    {
        // Strip <tool_call> blocks (including malformed ones)
        $cleaned = preg_replace('/<tool_call>.*?<\/tool_call>/s', '', $response);
        // Also strip orphaned <tool_call> without closing tag
        $cleaned = preg_replace('/<tool_call>.*$/ms', '', $cleaned);
        // Strip bare JSON tool objects
        $cleaned = preg_replace('/\n?\{[^{}]*"tool"\s*:\s*"(write_file|read_file|delete_file|list_files)"[^{}]*\}\n?/s', '', $cleaned);
        return trim($cleaned);
    }

    /**
     * Build a human-readable summary of planned tool operations.
     */
    private function buildPlanSummary(array $toolCalls): string
    {
        $lines = [];
        foreach ($toolCalls as $call) {
            $tool = $call['tool'] ?? '';
            $path = $call['path'] ?? '';
            $icon = match($tool) {
                'write_file' => '📝',
                'read_file'  => '📖',
                'delete_file'=> '🗑️',
                'list_files' => '📂',
                default      => '🔧',
            };
            $action = match($tool) {
                'write_file' => 'Create/update',
                'read_file'  => 'Read',
                'delete_file'=> 'Delete',
                'list_files' => 'List',
                default      => $tool,
            };
            $lines[] = "{$icon} {$action}: `{$path}`";
        }
        if (count($lines) > 1) {
            return "🔨 Planned operations:\n" . implode("\n", $lines);
        }
        return "🔨 {$lines[0]}";
    }

    /**
     * Build a human-readable summary of tool execution results.
     */
    private function buildToolResultSummary(array $results): string
    {
        $lines = [];
        foreach ($results as $r) {
            $tool = $r['tool'] ?? '';
            $path = $r['path'] ?? '';
            $status = $r['status'] ?? 'error';
            $message = $r['message'] ?? '';
            $icon = $status === 'success' ? '✅' : '❌';
            
            if ($tool === 'write_file') {
                $lines[] = "{$icon} `{$path}` — {$message}";
            } elseif ($tool === 'delete_file') {
                $lines[] = "{$icon} Deleted `{$path}`";
            } elseif ($tool === 'read_file') {
                $lines[] = "{$icon} Read `{$path}`";
            } elseif ($tool === 'list_files') {
                $lines[] = "{$icon} Listed `{$path}`";
            } else {
                $lines[] = "{$icon} {$tool}: {$message}";
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Stream text in small chunks (simulates streaming for better UX).
     */
    private function streamChunks(string $text): \Generator
    {
        $chunkSize = 15;
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i += $chunkSize) {
            yield ['content' => mb_substr($text, $i, $chunkSize), 'done' => false];
        }
    }

    // ──────────────────────────────────────────────
    //  State persistence
    // ──────────────────────────────────────────────

    private function saveState(array $state): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put($this->stateTokenCachePrefix . $token, $state, 300); // 5 min TTL
        return $token;
    }

    private function loadState(string $token): ?array
    {
        return Cache::get($this->stateTokenCachePrefix . $token);
    }
}
