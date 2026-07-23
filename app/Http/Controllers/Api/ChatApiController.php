<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\Message;
use App\Services\AI\ChatProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChatApiController extends Controller
{
    protected $chatProcessor;

    public function __construct(ChatProcessor $chatProcessor)
    {
        $this->chatProcessor = $chatProcessor;
    }

    // ── Rooms ──

    public function index()
    {
        $rooms = request()->user()->rooms()
            ->latest()
            ->get()
            ->map(fn($room) => $this->formatRoom($room));

        return response()->json(['status' => 'ok', 'data' => $rooms]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title'    => 'required|string|max:255',
            'category' => 'sometimes|string|max:100',
            'persona'  => 'sometimes|string|max:50',
            'model'    => 'sometimes|string|max:50',
        ]);

        $room = $request->user()->rooms()->create([
            'title'         => $request->title,
            'category'      => $request->category ?? 'General',
            'persona'       => $request->persona ?? 'general',
            'system_prompt' => '',
            'memory_config' => json_encode([]),
        ]);

        return response()->json([
            'status' => 'ok',
            'data'   => $this->formatRoom($room),
        ], 201);
    }

    public function show(Room $room)
    {
        $this->authorizeRoom($room);

        return response()->json([
            'status' => 'ok',
            'data'   => $this->formatRoom($room, true),
        ]);
    }

    public function update(Request $request, Room $room)
    {
        $this->authorizeRoom($room);

        $room->update($request->only(['title', 'category', 'persona']));

        return response()->json([
            'status' => 'ok',
            'data'   => $this->formatRoom($room),
        ]);
    }

    public function destroy(Room $room)
    {
        $this->authorizeRoom($room);
        $room->delete();

        return response()->json(['status' => 'ok', 'message' => 'Room deleted.']);
    }

    // ── Messages ──

    public function messages(Room $room)
    {
        $this->authorizeRoom($room);

        $messages = $room->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn($msg) => $this->formatMessage($msg));

        return response()->json(['status' => 'ok', 'data' => $messages]);
    }

    /**
     * Send a message — SSE streaming response.
     */
    public function send(Request $request, Room $room)
    {
        $this->authorizeRoom($room);

        // Rate limiting — skip for agent-continue (handled separately)
        $isAgent = $request->input('chat_mode') === 'agent';
        $lockKey = 'user_processing_' . $request->user()->id;
        if (Cache::has($lockKey)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Please wait, AI is processing your previous message.',
            ], 429);
        }

        $request->validate([
            'message'        => 'required|string',
            'model'          => 'sometimes|string|in:ollama,ollama_cloud,deepseek',
            'use_web_search' => 'sometimes|boolean',
            'deepseek_pro'   => 'sometimes|boolean',
            'skripsi_mode'   => 'sometimes|boolean',
            'workspace_path' => 'sometimes|string|nullable',
            'chat_mode'      => 'sometimes|string|in:ask,agent',
        ]);

        // Save user message
        $userMessage = $room->messages()->create([
            'sender_id'   => $request->user()->id,
            'sender_type' => 'user',
            'content'     => $request->message,
        ]);

        // Process with AI (streaming)
        set_time_limit(0);

        return response()->stream(function () use ($request, $room, $userMessage, $isAgent) {
            $lockKey = 'user_processing_' . $request->user()->id;
            Cache::put($lockKey, true, 300);

            try {
                $fullResponse = '';
                $isWaiting = false; // Agent waiting for client execution
                $options = [
                    'use_web_search' => $request->boolean('use_web_search', false),
                    'deepseek_pro'   => $request->boolean('deepseek_pro', false),
                    'skripsi_mode'   => $request->boolean('skripsi_mode', false),
                    'persona_override' => $request->skripsi_mode ? 'education-skripsi' : null,
                    'chat_mode'      => $request->input('chat_mode', 'ask'),
                    'workspace_path' => $request->input('workspace_path'),
                ];

                $generator = $this->chatProcessor->process(
                    $room->id,
                    $request->model ?? 'ollama',
                    $options
                );

                foreach ($generator as $event) {
                    if (is_array($event)) {
                        echo "data: " . json_encode($event) . "\n\n";
                        if (isset($event['content'])) {
                            $fullResponse .= $event['content'];
                        }
                        if (($event['status'] ?? '') === 'agent_waiting') {
                            $isWaiting = true;
                        }
                    }
                    ob_flush(); flush();
                }

                // Agent phase 1: awaiting client — unlock so client can call agent-continue
                if ($isWaiting) {
                    Cache::forget($lockKey);
                    echo "data: " . json_encode([
                        'done'             => true,
                        'user_message_id'  => $userMessage->id,
                        'awaiting_tools'   => true,
                    ]) . "\n\n";
                    ob_flush(); flush();
                    return;
                }

                // Normal completion — save bot message
                if (!empty($fullResponse)) {
                    $botMessage = $room->messages()->create([
                        'sender_id'   => $request->user()->id,
                        'sender_type' => 'bot',
                        'content'     => $fullResponse,
                    ]);

                    echo "data: " . json_encode([
                        'done'            => true,
                        'user_message_id' => $userMessage->id,
                        'bot_message_id'  => $botMessage->id,
                    ]) . "\n\n";
                } else {
                    echo "data: " . json_encode([
                        'done'            => true,
                        'user_message_id' => $userMessage->id,
                    ]) . "\n\n";
                }
                ob_flush(); flush();

            } catch (\Exception $e) {
                echo "data: " . json_encode([
                    'error' => $e->getMessage(),
                    'done'  => true,
                ]) . "\n\n";
                ob_flush(); flush();
            } finally {
                if (!$isWaiting) {
                    Cache::forget($lockKey);
                }
            }
        }, 200, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'    => 'keep-alive',
        ]);
    }

    /**
     * Agent continue — client sends back tool execution results.
     */
    public function agentContinue(Request $request, Room $room)
    {
        $this->authorizeRoom($room);

        $request->validate([
            'state_token' => 'required|string',
            'results'     => 'required|array',
        ]);

        $stateToken = $request->input('state_token');
        $results    = $request->input('results');

        return response()->stream(function () use ($request, $room, $stateToken, $results) {
            try {
                $fullResponse = '';
                $isWaiting = false;

                $generator = $this->chatProcessor->processAgentContinue(
                    $stateToken,
                    $results
                );

                foreach ($generator as $event) {
                    if (is_array($event)) {
                        echo "data: " . json_encode($event) . "\n\n";
                        if (isset($event['content'])) {
                            $fullResponse .= $event['content'];
                        }
                        if (($event['status'] ?? '') === 'agent_waiting') {
                            $isWaiting = true;
                        }
                    }
                    ob_flush(); flush();
                }

                // Agent waiting again — more tool requests
                if ($isWaiting) {
                    echo "data: " . json_encode([
                        'done'           => true,
                        'awaiting_tools' => true,
                    ]) . "\n\n";
                    ob_flush(); flush();
                    return;
                }

                // Final response — save bot message
                if (!empty($fullResponse)) {
                    $botMessage = $room->messages()->create([
                        'sender_id'   => $request->user()->id,
                        'sender_type' => 'bot',
                        'content'     => $fullResponse,
                    ]);

                    echo "data: " . json_encode([
                        'done'           => true,
                        'bot_message_id' => $botMessage->id,
                    ]) . "\n\n";
                } else {
                    echo "data: " . json_encode(['done' => true]) . "\n\n";
                }
                ob_flush(); flush();

            } catch (\Exception $e) {
                echo "data: " . json_encode([
                    'error' => $e->getMessage(),
                    'done'  => true,
                ]) . "\n\n";
                ob_flush(); flush();
            }
        }, 200, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function rateMessage(Request $request, Message $message)
    {
        $request->validate([
            'rating' => 'required|integer|in:-1,0,1',
        ]);

        $message->update([
            'satisfaction_score' => $request->rating === 1 ? 3 : ($request->rating === -1 ? 1 : null),
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function destroyMessage(Message $message)
    {
        $message->delete();

        return response()->json(['status' => 'ok', 'message' => 'Message deleted.']);
    }

    public function uploadFile(Request $request)
    {
        $request->validate([
            'file'    => 'required|file|max:10240',
            'room_id' => 'required|exists:rooms,id',
        ]);

        $path = $request->file('file')->store('chat-uploads', 'public');
        $name = $request->file('file')->getClientOriginalName();

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'name' => $name,
                'path' => $path,
                'url'  => asset('storage/' . $path),
            ],
        ]);
    }

    // ── Helpers ──

    private function authorizeRoom(Room $room): void
    {
        if ($room->user_id !== request()->user()->id) {
            abort(403, 'Unauthorized access to this room.');
        }
    }

    private function formatRoom(Room $room, bool $withMessages = false): array
    {
        $data = [
            'id'         => $room->id,
            'title'      => $room->title,
            'category'   => $room->category,
            'persona'    => $room->persona,
            'created_at' => $room->created_at?->toISOString(),
            'updated_at' => $room->updated_at?->toISOString(),
        ];

        if ($withMessages) {
            $data['messages'] = $room->messages()
                ->orderBy('created_at')
                ->get()
                ->map(fn($m) => $this->formatMessage($m));
        }

        return $data;
    }

    private function formatMessage(Message $msg): array
    {
        return [
            'id'                 => $msg->id,
            'content'            => $msg->content,
            'sender_type'        => $msg->sender_type, // 'user' or 'bot'
            'satisfaction_score' => $msg->satisfaction_score,
            'tokens_used'        => $msg->tokens_used,
            'created_at'         => $msg->created_at?->toISOString(),
        ];
    }
}
