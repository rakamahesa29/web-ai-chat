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
    private string $workspacePath = '';
    private int $maxIterations = 30;
    private string $stateTokenCachePrefix = 'agent_state_';

    public function __construct(string $stateTokenCachePrefix = 'agent_state_')
    {
        $this->stateTokenCachePrefix = $stateTokenCachePrefix;
    }

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

        $displayName = ($workspacePath === '/' || empty($workspacePath)) ? 'Full Mac (/)' : basename($workspacePath);
        yield [
            'type'   => 'meta',
            'status' => 'agent_start',
            'message' => 'Agent mode — ' . $displayName,
        ];

        $systemPrompt = $this->buildAgentSystemPrompt();
        $messages   = [];
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];

        foreach ($conversationHistory as $msg) {
            $role = ($msg['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => $msg['content'] ?? ''];
        }
        $lastUserMsg = ['role' => 'user', 'content' => $this->buildUserPrompt($userMessage)];
        if (!empty($options['images'])) {
            $lastUserMsg['images'] = $options['images'];
        }
        $messages[] = $lastUserMsg;

        $phase1Options = $options;
        $adapter = ProviderFactory::make($modelName, $phase1Options);
        $iteration = 0;

        while ($iteration < $this->maxIterations) {
            $iteration++;

            yield ['type' => 'meta', 'status' => 'agent_thinking', 'iteration' => $iteration];

            $aiResponse = '';
            yield from $this->streamAndCollectResponse($adapter, $messages, $aiResponse);
            if (empty(trim($aiResponse))) {
                yield ['content' => 'Agent Error: AI returned empty response.', 'done' => true];
                return;
            }

            $toolCalls = $this->extractToolCalls($aiResponse);

            if (empty($toolCalls)) {
                $isStalled = $this->isAiStalled($aiResponse, $iteration);

                if ($isStalled) {
                    Log::warning("AgentProcessor: phase1 AI stalled without tool_call, re-prompting", [
                        'iteration' => $iteration,
                        'preview'   => substr($aiResponse, 0, 150),
                    ]);
                    $messages[] = ['role' => 'assistant', 'content' => "Menyiapkan berkas berikutnya..."];
                    $messages[] = [
                        'role'    => 'user',
                        'content' => "CRITICAL AUTONOMOUS INSTRUCTION: Anda mengumumkan langkah/berkas berikutnya tetapi TIDAK menyertakan blok `<tool_call>`. JANGAN tulis monolog, penjelasan, atau draft kode di chat. Pengguna ingin Anda bekerja secara otomatis tanpa menunggu kata 'lanjut'. Segera keluarkan blok `<tool_call>` untuk `write_file` SEKARANG juga!"
                    ];
                    continue;
                }

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

        // Emit structured checkpoints for Antigravity-style sequence rendering
        foreach ($toolResults as $r) {
            $tool = $r['tool'] ?? '';
            $path = $r['path'] ?? '';
            $status = $r['status'] ?? 'error';
            $msg = $r['message'] ?? '';
            $action = match($tool) {
                'read_file', 'list_files' => 'explored',
                'delete_file'             => 'deleted',
                'write_file'              => (str_contains(strtolower($msg), 'created') || !str_contains(strtolower($msg), 'overwrit') ? 'created' : 'edited'),
                default                   => 'executed',
            };
            yield [
                'type'        => 'meta',
                'status'      => 'agent_checkpoint',
                'action'      => $action,
                'path'        => $path,
                'file'        => basename($path),
                'step'        => $iteration,
                'status_code' => $status,
                'message'     => $msg,
            ];
        }

        // Emit tool execution results as a visible summary
        yield ['content' => $this->buildToolResultSummary($toolResults) . "\n\n", 'done' => false];

        // Feed stripped AI response + tool results back into conversation
        // We strip tool calls to save tokens and prevent context bloat
        $cleanAiResponse = $this->stripToolCalls($aiResponse);
        if (empty($cleanAiResponse)) {
            $cleanAiResponse = "Executed operations.";
        } elseif (strlen($cleanAiResponse) > 400) {
            $cleanAiResponse = substr($cleanAiResponse, 0, 300) . "... (checkpoint executed)";
        }
        $messages[] = ['role' => 'assistant', 'content' => $cleanAiResponse];
        $messages[] = ['role' => 'user', 'content' => $this->formatToolResults($toolResults)];

        $phase2Options = $options;
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
                $isStalled = $this->isAiStalled($aiResponse, $iteration);

                if ($isStalled) {
                    Log::warning("AgentProcessor: phase2 AI stalled without tool_call, re-prompting", [
                        'iteration' => $iteration,
                        'preview'   => substr($aiResponse, 0, 150),
                    ]);
                    $messages[] = ['role' => 'assistant', 'content' => "Menyiapkan berkas berikutnya..."];
                    $messages[] = [
                        'role'    => 'user',
                        'content' => "CRITICAL AUTONOMOUS INSTRUCTION: Anda mengumumkan langkah/berkas berikutnya tetapi TIDAK menyertakan blok `<tool_call>`. JANGAN tulis monolog, penjelasan, atau draft kode di chat. Pengguna ingin Anda bekerja secara otomatis tanpa menunggu kata 'lanjut'. Segera keluarkan blok `<tool_call>` untuk `write_file` SEKARANG juga!"
                    ];
                    continue;
                }

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
        $clean = trim(strtolower($message));
        $isContinuation = in_array($clean, ['lanjut', 'lanjutkan', 'continue', 'next', 'ok lanjut', 'oke lanjut']);

        if ($isContinuation) {
            return "USER REQUEST: Lanjutkan ke langkah/checkpoint berikutnya dan selesaikan semua berkas yang belum dibuat.\n\n"
                . "CRITICAL AUTONOMOUS INSTRUCTION: Proceed IMMEDIATELY to the next checkpoint. Emit ONE `<tool_call>` block now without chatting or monologuing.";
        }

        return "USER REQUEST: {$message}\n\n"
            . "CRITICAL EXECUTION PROTOCOL:\n"
            . "1. AUTONOMOUS END-TO-END: Complete ALL parts of the user request automatically. Do NOT stop after one step or wait for the user to say 'lanjut'. Proceed sequentially through all checkpoints.\n"
            . "2. IMMEDIATE TOOL CALL: In this turn, output AT MOST 1 brief line stating what file you are creating/updating, followed IMMEDIATELY by the `<tool_call>` block.\n"
            . "3. ZERO CODE IN CHAT: NEVER dump raw HTML, CSS, or JS code into your chat text. Put ALL code inside the 'content' parameter of write_file.\n"
            . "4. ZERO MONOLOGUE: Do NOT write internal thoughts, drafting, or step-by-step reasoning in chat. Emit the `<tool_call>` directly.";
    }

    private function buildAgentSystemPrompt(): string
    {
        $shouldScan = !empty($this->workspacePath) 
            && $this->workspacePath !== '/' 
            && !str_starts_with($this->workspacePath, '~');
        $dirListing = $shouldScan ? $this->getDirectoryListing($this->workspacePath) : '';
        $wsPath = !empty($this->workspacePath) ? $this->workspacePath : 'User Home (~/) & Global Mac Filesystem';
        $structureBlock = !empty(trim($dirListing)) ? "\n**Directory Structure:**\n```\n{$dirListing}\n```\n" : "";

        return <<<PROMPT
You are an autonomous AI system & coding agent operating on macOS (like Antigravity IDE / Gemini for Mac).

**Active Workspace / Context:** {$wsPath}{$structureBlock}

## Tools

Use `<tool_call>` tags with JSON to operate on files. Tools are executed locally on the user's MacBook in the background.

### write_file
Create or overwrite a file.
```json
{"tool": "write_file", "path": "path/to/file.ext", "content": "file contents"}
```

### read_file
Read a file's contents.
```json
{"tool": "read_file", "path": "path/to/file.ext"}
```

### list_files
List directory contents.
```json
{"tool": "list_files", "path": "path/to/dir/"}
```

### delete_file
Delete a file.
```json
{"tool": "delete_file", "path": "path/to/file.ext"}
```

## Rules

1. **Step-by-Step Checkpoint Execution (Antigravity IDE Pattern):**
   - When a user request involves multiple files or components (e.g. landing page with cart modal, product detail page, checkout page), **break the task down into discrete sequential checkpoints**.
   - Work on **ONE file / checkpoint per turn**. Do NOT attempt to combine multiple pages into a single giant file.
   - Sequence:
     * Checkpoint 1: Read or update the primary page (e.g. edit `landing.html` to add cart badge & modal).
     * Checkpoint 2: Create the next page (e.g. `detail-product.html` with responsive e-commerce layout).
     * Checkpoint 3: Create the subsequent page (e.g. `checkout.html`).
   - Execute ONE `<tool_call>` per turn. Once tool execution succeeds, proceed to the next checkpoint until all requested files are created.
   - **CRITICAL CONTINUATION RULE:**
     * In EVERY intermediate turn where more files or steps remain, you MUST include the `<tool_call>` block IMMEDIATELY in that turn.
     * NEVER stop after merely saying "Let me write it", "Now step 2...", "Sekarang saya akan membuat...", or announcing your plan without the `<tool_call>`.
     * If you state that you are going to write or create a file, you MUST include the `<tool_call>` block right away in the SAME response.

2. **CRITICAL ANTI-SLOWDOWN RULE:**
   - **NEVER dump full HTML, CSS, or JavaScript code into your conversational chat response.**
   - Put all complete file code ONLY inside the `"content"` parameter of the `write_file` `<tool_call>`.
   - In chat, output only a short, 1-2 sentence description of the current checkpoint (e.g. *"Langkah 1: Menambahkan modal keranjang belanja pada landing.html..."*).
   - Writing thousands of lines of raw code in chat will cause severe token bottlenecks and make the response extremely slow.

3. **Smart Path Mapping:**
   - You can use relative paths (e.g. `"path": "index.html"`), OR
   - You can use tilde or absolute paths (e.g. `"path": "~/Desktop/landing.html"`, `"path": "~/Desktop/detail-product.html"`). They are resolved and executed directly on the user's Mac.

4. **Modular Web Architecture:**
   - Keep pages clean, modular, and interconnected (e.g. links `<a href="detail-product.html">` and `<a href="checkout.html">`).
   - Maintain consistent styling and design across all created pages.

5. **CRITICAL:** You MUST ONLY emit ONE `<tool_call>` block per response turn. Do not output multiple tool calls at once. After emitting a tool call, stop immediately and wait for execution results.

6. **JSON must be valid:** Escape double quotes in content as `\"` and use `\n` for newlines. Keep tool call JSON compact and valid.

7. **Respond in the user's language.**

## Example Multi-Step Flow

User: "buatkan landing page dan halaman detail produk di Desktop"
Agent Turn 1: "Langkah 1/2: Membuat landing page..." <tool_call>{"tool": "write_file", "path": "~/Desktop/landing.html", "content": "..."}</tool_call>
(After tool result):
Agent Turn 2: "Langkah 2/2: Membuat halaman detail produk..." <tool_call>{"tool": "write_file", "path": "~/Desktop/detail-product.html", "content": "..."}</tool_call>
(After tool result):
Agent Turn 3: "Seluruh halaman selesai dibuat! Berkas `landing.html` dan `detail-product.html` telah tersedia di Desktop Anda."
PROMPT;
    }

    private function getDirectoryListing(string $path, int $maxDepth = 3, int $depth = 0): string
    {
        $cleanPath = rtrim($path, '/');
        // Never traverse root, virtual filesystems, system directories, or non-directories
        $restricted = ['', '/', '/dev', '/proc', '/sys', '/etc', '/run', '/var', '/bin', '/sbin', '/usr', '/lib', '/tmp'];
        if ($depth > $maxDepth || in_array($cleanPath, $restricted) || !@is_dir($path)) {
            return '';
        }

        $items = @scandir($path);
        if (!$items) return '';

        $dirs  = [];
        $files = [];
        $ignored = ['.', '..', '.git', 'node_modules', 'vendor', 'storage', '.DS_Store', 'dev', 'proc', 'sys', 'run'];
        foreach ($items as $item) {
            if (str_starts_with($item, '.') || in_array($item, $ignored)) continue;
            $full = $cleanPath . '/' . $item;
            if (@is_link($full)) continue;
            if (@is_dir($full)) {
                $dirs[] = $item;
            } elseif (@is_file($full)) {
                $files[] = $item;
            }
        }
        sort($dirs); sort($files);

        $lines = [];
        $prefix = str_repeat('  ', $depth);
        $count = 0;
        foreach (array_merge($dirs, $files) as $item) {
            if ($count++ >= 50) { $lines[] = "$prefix... (truncated)"; break; }
            $full = $cleanPath . '/' . $item;
            if (@is_dir($full)) {
                $lines[] = $prefix . "📁 $item/";
                $sub = $this->getDirectoryListing($full, $maxDepth, $depth + 1);
                if (!empty($sub)) $lines[] = $sub;
            } elseif (@is_file($full)) {
                $size = @filesize($full);
                $sizeStr = ($size !== false && $size !== null) ? ' (' . $this->formatSize($size) . ')' : '';
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

        // 1. Match <tool_call>...</tool_call> blocks
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

        // 2. Match unclosed <tool_call> (e.g. at the end of response)
        if (empty($calls) && preg_match('/<tool_call>\s*([^{\n]*\{.*)$/s', $response, $matches)) {
            $salvaged = $this->salvageJsonFields(trim($matches[1]));
            if ($salvaged && isset($salvaged['tool'])) {
                $calls[] = $salvaged;
            }
        }

        // 3. Match ```tool_call or ```tool code fences
        if (empty($calls) && preg_match_all('/```(?:tool_call|tool)\s*(.*?)\s*```/s', $response, $matches)) {
            foreach ($matches[1] as $rawJson) {
                $data = json_decode(trim($rawJson), true) ?: $this->salvageJsonFields(trim($rawJson));
                if ($data && isset($data['tool'])) {
                    $calls[] = $data;
                }
            }
        }

        // 4. Match ```json code fences containing "tool": "..."
        if (empty($calls) && preg_match_all('/```(?:json)?\s*(\{[\s\S]*?"tool"\s*:\s*"(?:write_file|read_file|delete_file|list_files)"[\s\S]*?\})\s*```/s', $response, $matches)) {
            foreach ($matches[1] as $rawJson) {
                $data = json_decode(trim($rawJson), true) ?: $this->salvageJsonFields(trim($rawJson));
                if ($data && isset($data['tool'])) {
                    $calls[] = $data;
                }
            }
        }

        // 5. Fallback: bare JSON objects containing "tool": "write_file", etc.
        if (empty($calls)) {
            if (preg_match('/\{[\s\S]*?"tool"\s*:\s*"(write_file|read_file|delete_file|list_files)"[\s\S]*?\}/s', $response, $match)) {
                $data = json_decode(trim($match[0]), true) ?: $this->salvageJsonFields(trim($match[0]));
                if ($data && isset($data['tool'])) {
                    $calls[] = $data;
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
        // Try XML <invoke> format first (used by some models natively)
        if (preg_match('/<invoke\s+name="([^"]+)">/s', $raw, $m)) {
            $result = ['tool' => $m[1]];
            if (preg_match('/<parameter\s+name="path">\s*(.*?)\s*<\/parameter>/s', $raw, $pm)) {
                $result['path'] = $pm[1];
            }
            if (preg_match('/<parameter\s+name="content">\s*(.*?)\s*(<\/parameter>|<\/invoke>|$)/s', $raw, $pm)) {
                $result['content'] = $pm[1];
            }
            return $result;
        }

        // Extract "tool": "value"
        if (!preg_match('/"tool"\s*:\s*"(write_file|read_file|delete_file|list_files)"/', $raw, $m)) {
            return null;
        }
        $result = ['tool' => $m[1]];

        // Extract "path": "value"
        if (preg_match('/"path"\s*:\s*"([^"]*)"/', $raw, $m)) {
            $result['path'] = $m[1];
        }

        // Extract "content": "..." — handles multiline content and truncation
        // Match from "content": " until the last "} or end of string if truncated.
        if (preg_match('/"content"\s*:\s*"(.*?)("\s*\}|"$|$)/s', $raw, $m)) {
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
                if (($r['tool'] ?? '') === 'write_file') {
                    // Do not feed back the full content for write_file to prevent token bloat
                    $text .= "```\n...(content successfully saved)...\n```\n";
                } else {
                    $max = 8000;
                    $c = strlen($r['content']) > $max ? substr($r['content'], 0, $max) . "\n...(truncated)" : $r['content'];
                    $text .= "```\n{$c}\n```\n";
                }
            }
            if (isset($r['files'])) {
                foreach ($r['files'] as $f) {
                    $ic = ($f['type'] ?? '') === 'dir' ? '📁' : '📄';
                    $sz = isset($f['size']) ? " ({$f['size']} bytes)" : '';
                    $text .= "  {$ic} {$f['name']}{$sz}\n";
                }
            }
        }
        $text .= "</tool_results>\n\n"
            . "Status of previous checkpoint: SUCCESSFUL.\n"
            . "INSTRUCTION FOR NEXT ACTION:\n"
            . "- Check the user's initial request. If any files, pages, or features remain unfinished, proceed to the NEXT checkpoint IMMEDIATELY by emitting ONE `<tool_call>` block.\n"
            . "- AUTONOMOUS RULE: DO NOT wait for the user to say 'lanjut' or 'continue'. The user expects you to finish the entire task autonomously!\n"
            . "- DO NOT output an announcement without the `<tool_call>`. You MUST include the `<tool_call>` block in this very response.\n"
            . "- If and ONLY if ALL files and requested features are 100% completed and working, provide a concise final summary without any tool calls.";
        return $text;
    }

    /**
     * Check if AI announced a step or drafted code but stalled without emitting a tool call.
     */
    private function isAiStalled(string $text, int $iteration): bool
    {
        if ($iteration >= 15) {
            return false;
        }

        $lower = strtolower($text);

        $stalledPhrases = [
            'let me write', 'let me create', 'let me build', 'let me compose', 'let me produce',
            'let me draft', 'let me craft', 'let me implement', 'let me now', 'let me add',
            'i will create', 'i will write', 'i will build', 'i will make', 'i will now', 'i will add',
            'i need to create', 'i need to write', 'i need to build', 'i need to make',
            'now step', 'now create', 'now write', 'next step', 'remaining checkpoint', 'missing file',
            'write it', 'write the tool call', 'writing now', 'let me check', 'let me start',
            'saya akan membuat', 'langkah selanjutnya', 'akan saya buat', 'mari kita buat',
            'saya akan menambahkan', 'berikutnya saya akan', 'belum dibuat', 'belum ada'
        ];

        foreach ($stalledPhrases as $p) {
            if (str_contains($lower, $p)) {
                return true;
            }
        }

        // Checkpoint announcements like "Langkah 2/3:", "Step 3:", "Checkpoint 2:"
        if (preg_match('/\b(?:langkah|step|checkpoint)\s*\d+/i', $text)) {
            return true;
        }

        // Leaked HTML / CSS / code outside of tool call
        if (preg_match('/<!doctype\s+html|<html[\s>]|```(?:html|css|javascript|js|php)/i', $text)) {
            return true;
        }

        return false;
    }

    // ──────────────────────────────────────────────
    //  User-visible progress helpers
    // ──────────────────────────────────────────────

    /**
     * Remove <tool_call> blocks from AI response, returning only the human-readable text.
     */
    private function stripToolCalls(string $response): string
    {
        // Strip <tool_call>, <tool_calls>, and <invoke> blocks
        $cleaned = preg_replace('/<(tool_call|tool_calls|invoke)>.*?<\/\1>/s', '', $response);
        // Also strip orphaned tags without closing tag
        $cleaned = preg_replace('/<(tool_call|tool_calls|invoke)>.*$/ms', '', $cleaned);
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
