<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\Message;
use App\Services\AI\ChatProcessor;
use App\Services\Search\WebSearchAgent;
use App\Jobs\ExtractMessageEntities;
use App\Jobs\ExtractThesisEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;


class ChatController extends Controller
{
    protected $chatProcessor;

    public function __construct(ChatProcessor $chatProcessor)
    {
        $this->chatProcessor = $chatProcessor;
    }

    /**
     * Display the list of rooms (History).
     */
    public function index()
    {
        $hasRooms = auth()->user()->rooms()->exists();
        $rooms = auth()->user()->rooms()->latest()->paginate(20);

        return view('chat.index', compact('rooms', 'hasRooms'));
    }

    /**
     * Show a specific chat room.
     */
    public function show(Room $room)
    {
        $rooms = auth()->user()->rooms()->latest()->paginate(20);
        $messages = $room->messages;
        $isProcessing = \Illuminate\Support\Facades\Cache::has('user_processing_' . auth()->id());

        return view('chat.show', compact('room', 'messages', 'rooms', 'isProcessing'));
    }

    public function create()
    {
        return view('chat.create');
    }

    public function store(Request $request)
    {
        $room = auth()->user()->rooms()->create([
            'title' => $request->input('title', 'New Conversation'),
            'category' => $request->input('category', 'General'),
            'persona' => $request->input('persona', 'general'),
            'system_prompt' => '',
            'memory_config' => json_encode([]),
        ]);

        return redirect()->route('chat.show', [
            'room' => $room->id,
            'model' => $request->input('model_name')
        ]);
    }

    public function send(Request $request, Room $room)
    {
        set_time_limit(0);
        ignore_user_abort(true); // Izinkan proses berjalan di background meskipun user pindah halaman

        $lockKey = 'user_processing_' . auth()->id();
        if (\Illuminate\Support\Facades\Cache::has($lockKey)) {
            return response()->json(['status' => 'error', 'message' => 'Harap tunggu, AI sedang memproses pesan Anda sebelumnya di background.'], 429);
        }

        // 1. Validasi Input (Tambahkan use_web_search)
        $request->validate([
            'message' => 'required|string',
            'model_name' => 'required|string',
            'context_code' => 'nullable|string',
            'use_web_search' => 'nullable|boolean',
            'deepseek_pro' => 'nullable|boolean',
            'persona_override' => 'nullable|string|in:education-skripsi'
        ]);

        $searchContext = null;

        // 2. Eksekusi Web Search Agent jika toggle aktif
        if ($request->boolean('use_web_search')) {
            $searchAgent = new WebSearchAgent();
            // Lakukan pencarian ke Google menggunakan pertanyaan user
            $searchContext = $searchAgent->search($request->message);
        }

        // 3. Simpan pesan user beserta data pencarian ke database
        $userMessage = $room->messages()->create([
            'content' => $request->message,
            'context_code' => $request->context_code,
            'search_context' => $searchContext,
            'sender_id' => auth()->id(),
            'sender_type' => 'user',
            'tokens_used' => 0,
            'category' => $room->category,
        ]);

        // Kunci user agar tidak bisa mengirim pesan baru sampai proses ini selesai
        \Illuminate\Support\Facades\Cache::put($lockKey, true, now()->addMinutes(5));

        \Log::info("Chat Request", [
            'room_id' => $room->id, 
            'model' => $request->model_name,
            'used_web_search' => $request->boolean('use_web_search')
        ]);

        try {
            $options = [
                'use_web_search' => $request->boolean('use_web_search'),
                'force_local' => false,
                'persona_override' => $request->input('persona_override'),
                'deepseek_pro' => $request->boolean('deepseek_pro'),
                'chat_mode'      => $request->input('chat_mode', 'ask'),
                'workspace_path' => $request->input('workspace_path'),
            ];
            
            $aiResponse = $this->chatProcessor->process((int)$room->id, $request->model_name, $options);

            if ($aiResponse instanceof \Generator) {
                return $this->streamAiResponse($aiResponse, $room, $userMessage, $lockKey, $request->model_name);
            }

            // Handle Non-Streaming Response (e.g., standard API calls)
            if (is_array($aiResponse)) {
                if (strpos($aiResponse['content'], '[KNOWLEDGE_GAP_DETECTED]') !== false) {
                    \Illuminate\Support\Facades\Cache::forget($lockKey);
                    return response()->json([
                        'status' => 'success',
                        'knowledge_gap' => true,
                        'user_message_id' => $userMessage->id
                    ]);
                }

                $botMessage = $room->messages()->create([
                    'content' => $aiResponse['content'],
                    'tokens_used' => $aiResponse['tokens'] ?? 0,
                    'sender_id' => 0,
                    'sender_type' => 'bot',
                    'category' => $room->category,
                ]);

                \Illuminate\Support\Facades\Cache::forget($lockKey);

                return response()->json([
                    'status' => 'success',
                    'data' => $aiResponse,
                    'message_id' => $botMessage->id,
                    'room_title' => $room->title
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Cache::forget($lockKey);
            \Log::error("Chat Error: " . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function retry(Request $request, Room $room, Message $message)
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $lockKey = 'user_processing_' . auth()->id();
        if (\Illuminate\Support\Facades\Cache::has($lockKey)) {
            return response()->json(['status' => 'error', 'message' => 'Harap tunggu, AI sedang memproses pesan Anda sebelumnya di background.'], 429);
        }

        $request->validate([
            'model_name' => 'required|string',
            'use_web_search' => 'nullable|boolean',
            'force_local' => 'nullable|boolean',
            'deepseek_pro' => 'nullable|boolean',
            'persona_override' => 'nullable|string|in:education-skripsi'
        ]);

        \Illuminate\Support\Facades\Cache::put($lockKey, true, now()->addMinutes(5));

        if ($request->boolean('use_web_search') && empty($message->search_context)) {
            $searchAgent = new WebSearchAgent();
            $searchContext = $searchAgent->search($message->content);
            $message->update(['search_context' => $searchContext]);
        }

        try {
            $options = [
                'use_web_search' => $request->boolean('use_web_search'),
                'force_local' => $request->boolean('force_local'),
                'persona_override' => $request->input('persona_override'),
                'deepseek_pro' => $request->boolean('deepseek_pro'),
                'chat_mode'      => $request->input('chat_mode', 'ask'),
                'workspace_path' => $request->input('workspace_path'),
            ];

            $aiResponse = $this->chatProcessor->process((int)$room->id, $request->model_name, $options);

            if ($aiResponse instanceof \Generator) {
                return $this->streamAiResponse($aiResponse, $room, $message, $lockKey, $request->model_name);
            }

            if (is_array($aiResponse)) {
                $botMessage = $room->messages()->create([
                    'content' => $aiResponse['content'],
                    'tokens_used' => $aiResponse['tokens'] ?? 0,
                    'sender_id' => 0,
                    'sender_type' => 'bot',
                    'category' => $room->category,
                ]);

                \Illuminate\Support\Facades\Cache::forget($lockKey);

                return response()->json([
                    'status' => 'success',
                    'data' => $aiResponse,
                    'message_id' => $botMessage->id,
                    'room_title' => $room->title
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Cache::forget($lockKey);
            \Log::error("Chat Retry Error: " . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function streamAiResponse($aiResponse, $room, $userMessage, $lockKey, $modelName = 'ollama')
    {
        return response()->stream(function () use ($aiResponse, $room, $userMessage, $lockKey, $modelName) {
            $fullContent = '';

            echo "data: " . json_encode(['type' => 'meta', 'user_message_id' => $userMessage->id]) . "\n\n";
            if (ob_get_level() > 0) ob_flush(); flush();

            try {
                foreach ($aiResponse as $chunk) {
                    $chunk['room_title'] = $room->title;

                    if (isset($chunk['type']) && $chunk['type'] === 'meta') {
                        $chunk['user_message_id'] = $userMessage->id;
                    } elseif (isset($chunk['content'])) {
                        $fullContent .= $chunk['content'];
                    }

                    if (isset($chunk['done']) && $chunk['done'] === true) {
                        if (strpos($fullContent, '[KNOWLEDGE_GAP_DETECTED]') !== false) {
                            $chunk['knowledge_gap'] = true;
                        } else {
                            $totalTokens = $chunk['tokens'] ?? 0;
                            $promptTokens = $chunk['prompt_tokens'] ?? 0;
                            $finishReason = $chunk['finish_reason'] ?? null;

                            if ($promptTokens > 0) {
                                $userMessage->update(['tokens_used' => $promptTokens]);
                            }

                            // Extract and strip thesis evaluation marker before saving
                            $thesisEvalJson = null;
                            $cleanContent = $fullContent;
                            if (preg_match('/\[THESIS_EVAL\](.*?)\[\/THESIS_EVAL\]/s', $fullContent, $evalMatch)) {
                                $thesisEvalJson = trim($evalMatch[1]);
                                $cleanContent = trim(preg_replace('/\[THESIS_EVAL\].*?\[\/THESIS_EVAL\]/s', '', $fullContent));
                            }

                            $botMsg = $room->messages()->create([
                                'content' => $cleanContent,
                                'tokens_used' => $totalTokens,
                                'sender_id' => 0,
                                'sender_type' => 'bot',
                                'category' => $room->category,
                            ]);

                            $chunk['message_id'] = $botMsg->id;
                            $chunk['finish_reason'] = $finishReason;

                            // Save thesis evaluation data synchronously
                            if ($thesisEvalJson) {
                                ExtractThesisEvaluation::dispatchSync(
                                    $room->id,
                                    $botMsg->id,
                                    $thesisEvalJson
                                );
                            }

                            // Dispatch Knowledge Graph extraction job asynchronously
                            if (config('services.knowledge_graph.enabled', true)) {
                                ExtractMessageEntities::dispatch(
                                    $room->id,
                                    $userMessage->content,
                                    $cleanContent,
                                    $botMsg->id,
                                    $modelName
                                )->onQueue('knowledge-graph');
                            }
                        }
                    }

                    if (!connection_aborted()) {
                        echo "data: " . json_encode($chunk) . "\n\n";
                        if (ob_get_level() > 0) ob_flush(); flush();
                    }
                }

                if (!connection_aborted()) {
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush(); flush();
                }
            } finally {
                \Illuminate\Support\Facades\Cache::forget($lockKey);
            }
        }, 200, [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/event-stream',
            'Connection' => 'keep-alive',
            'X_Accel_Buffering' => 'no',
        ]);
    }

    public function updateRoom(Request $request, Room $room)
    {
        $request->validate(['title' => 'required|string']);
        $room->update(['title' => $request->title]);
        return response()->json(['message' => 'Room renamed successfully']);
    }

    public function updateContext(Request $request, Room $room)
    {
        if ($room->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized access'], 403);
        }

        $request->validate([
            'system_prompt' => 'nullable|string'
        ]);

        try {
            $room->update([
                'system_prompt' => $request->system_prompt
            ]);

            return response()->json([
                'status' => 'success', 
                'message' => 'Context Code berhasil disimpan'
            ]);
        } catch (\Exception $e) {
            \Log::error("Context Update Error: " . $e->getMessage());
            return response()->json(['error' => 'Gagal menyimpan konteks'], 500);
        }
    }

    public function destroy(Room $room)
    {
        try {
            $room->messages()->delete();
            $room->delete();

            return redirect()->route('chat.index')->with('success', 'Percakapan berhasil dihapus.');
        } catch (\Exception $e) {
            \Log::error("Delete Error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Gagal menghapus percakapan.');
        }
    }

    public function destroyMessage(Message $message)
    {
        if ($message->room->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized access'], 403);
        }
        try {
            $message->delete();
            return response()->json(['status' => 'success', 'message' => 'Pesan berhasil dihapus']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Gagal menghapus pesan'], 500);
        }
    }

    public function uploadFile(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240' // max 10MB
        ]);

        try {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            $content = '';

            if ($extension === 'pdf') {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($file->getPathname());
                $content = $pdf->getText();
            } elseif (in_array($extension, ['doc', 'docx'])) {
                // Native ZIP extraction for DOCX
                $zip = new \ZipArchive;
                if ($zip->open($file->getPathname()) === true) {
                    if (($index = $zip->locateName('word/document.xml')) !== false) {
                        $data = $zip->getFromIndex($index);
                        $zip->close();
                        $content = strip_tags(str_replace(['<w:p>', '</w:p>'], ["\n", "\n"], $data));
                    } else {
                        $zip->close();
                        throw new \Exception("Format DOCX tidak valid atau tidak bisa dibaca.");
                    }
                } else {
                    throw new \Exception("Gagal membuka file DOCX.");
                }
            } else {
                // For txt, php, js, etc.
                $content = file_get_contents($file->getPathname());
            }

            // Clean up excessive whitespace
            $content = preg_replace("/\n\s*\n/", "\n\n", trim($content));

            return response()->json([
                'status' => 'success',
                'content' => $content,
                'filename' => $file->getClientOriginalName()
            ]);

        } catch (\Exception $e) {
            \Log::error("File Upload Error: " . $e->getMessage());
            return response()->json(['error' => 'Gagal memproses file: ' . $e->getMessage()], 500);
        }
    }

    public function rateMessage(Request $request, Message $message)
    {
        if ($message->room->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized access'], 403);
        }
        $request->validate(['score' => 'required|integer|in:3,2,1']);

        try {
            $message->update(['satisfaction_score' => $request->score]);
            return response()->json(['status' => 'success', 'message' => 'Rating berhasil disimpan']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Gagal menyimpan rating'], 500);
        }
    }
}