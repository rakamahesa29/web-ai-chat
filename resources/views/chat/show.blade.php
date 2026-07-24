@extends('layouts.app')
@section('content')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark-dimmed.min.css" id="hljs-dark" media="(prefers-color-scheme: dark)">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css" id="hljs-light" media="(prefers-color-scheme: light)">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        .ai-markdown h1 { font-size: 1.5rem; font-weight: bold; margin-top: 1rem; margin-bottom: 0.5rem; color: rgb(var(--hermes-text)); }
        .ai-markdown h2 { font-size: 1.25rem; font-weight: bold; margin-top: 1rem; margin-bottom: 0.5rem; color: rgb(var(--hermes-text)); }
        .ai-markdown h3 { font-size: 1.125rem; font-weight: bold; margin-top: 1rem; margin-bottom: 0.5rem; color: rgb(var(--hermes-text)); }
        .ai-markdown p { margin-bottom: 0.75rem; line-height: 1.7; }
        .ai-markdown ul { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 0.75rem; }
        .ai-markdown ol { list-style-type: decimal; padding-left: 1.5rem; margin-bottom: 0.75rem; }
        .ai-markdown li { margin-bottom: 0.25rem; }
        .ai-markdown strong { font-weight: bold; color: rgb(var(--hermes-text)); }
        .ai-markdown em { font-style: italic; }
        .ai-markdown blockquote { border-left: 3px solid rgb(var(--hermes-accent)); padding-left: 1rem; color: rgb(var(--hermes-muted)); font-style: italic; margin-bottom: 0.75rem; background: rgb(var(--hermes-accent) / 0.1); padding: 0.75rem 1rem; border-radius: 0 0.5rem 0.5rem 0; }
        .ai-markdown code { font-family: 'JetBrains Mono', monospace; background-color: rgb(var(--hermes-hover)); padding: 0.125rem 0.375rem; border-radius: 0.25rem; font-size: 0.875em; color: #d946ef; }
        .dark .ai-markdown code { color: #f472b6; }
        .ai-markdown pre { background-color: rgb(var(--hermes-surface)); color: rgb(var(--hermes-text)); padding: 1rem; border-radius: 0.75rem; overflow-x: auto; margin-bottom: 0.75rem; border: 1px solid rgb(var(--hermes-border)); }
        .ai-markdown pre code { background-color: transparent; padding: 0; color: inherit; font-size: 0.875em; }
        .ai-markdown table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; font-size: 0.875rem; }
        .ai-markdown th, .ai-markdown td { border: 1px solid rgb(var(--hermes-border)); padding: 0.5rem 0.75rem; text-align: left; }
        .ai-markdown th { background-color: rgb(var(--hermes-surface)); font-weight: bold; }
        .ai-markdown a { color: rgb(var(--hermes-accent)); text-decoration: underline; }
        .ai-markdown a:hover { color: #818cf8; }
        
        .ai-markdown details.ds-thinking { background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 0.75rem; padding: 0.75rem 1rem; margin-bottom: 0.75rem; }
        .ai-markdown details.ds-thinking summary { cursor: pointer; font-weight: 600; color: #60a5fa; font-size: 0.8rem; margin-bottom: 0.5rem; }
        .ai-markdown details.ds-thinking > :not(summary) { font-size: 0.8rem; color: rgb(var(--hermes-muted)); line-height: 1.6; }
        
        #editor:empty:before { content: attr(data-placeholder); color: rgb(var(--hermes-muted)); pointer-events: none; display: block; }
        
        .my-swal-popup { border-radius: 16px !important; background: rgb(var(--hermes-card)) !important; border: 1px solid rgb(var(--hermes-border)) !important; color: rgb(var(--hermes-text)) !important; }
        .my-swal-title { color: rgb(var(--hermes-text)) !important; }
        .my-swal-html { color: rgb(var(--hermes-muted)) !important; }
        .swal2-confirm { background-color: #6366f1 !important; }
        .swal2-cancel { background-color: rgb(var(--hermes-hover)) !important; color: rgb(var(--hermes-text)) !important; }
    </style>
    <script>
        (function() {
            const isDark = document.documentElement.classList.contains('dark');
            const darkSheet = document.getElementById('hljs-dark');
            const lightSheet = document.getElementById('hljs-light');
            if (isDark) {
                darkSheet.media = 'all';
                lightSheet.media = 'not all';
            } else {
                lightSheet.media = 'all';
                darkSheet.media = 'not all';
            }
        })();
    </script>

    <div class="flex h-[calc(100dvh-0px)] bg-hermes-bg relative overflow-hidden" x-data="{ sidebarOpen: false }">
        
        <!-- Mobile Sidebar Overlay -->
        <div x-show="sidebarOpen" @click="sidebarOpen = false" 
             x-transition:enter="transition-opacity ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-black/60 z-20 md:hidden"></div>

        <!-- SIDEBAR -->
        <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
               class="fixed md:relative h-[calc(100dvh-0px)] w-72 bg-hermes-surface border-r border-hermes-border flex flex-col z-30 transform transition-transform duration-300 ease-in-out">
            
            <!-- Sidebar Header -->
            <div class="p-4 border-b border-hermes-border">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-hermes-muted uppercase tracking-wider">Conversations</h3>
                    <button @click="sidebarOpen = false" class="md:hidden hermes-btn-icon">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
                <a href="{{ route('chat.index') }}"
                   class="flex items-center justify-center gap-2 w-full py-2.5 px-4 border border-dashed border-hermes-border rounded-lg text-sm font-medium text-hermes-muted hover:text-hermes-text hover:border-hermes-accent hover:bg-hermes-accent/10 transition-all">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span>New Chat</span>
                </a>
            </div>
            
            <!-- Sidebar Content -->
            <div class="flex-1 overflow-y-auto p-3 space-y-1">
                @if (isset($rooms) && count($rooms) > 0)
                    @foreach ($rooms as $r)
                        <div class="group relative">
                            <a href="{{ route('chat.show', ['room' => $r->id, 'model' => request('model')]) }}" 
                               class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all
                                      {{ request()->route()->getName() == 'chat.show' && $r->id == $room->id 
                                         ? 'bg-hermes-accent/10 text-hermes-accent border-l-2 border-hermes-accent' 
                                         : 'text-hermes-muted hover:text-hermes-text hover:bg-hermes-hover' }}">
                                <i data-lucide="message-circle" class="w-4 h-4 shrink-0"></i>
                                <span class="text-sm truncate" id="sidebar-title-{{ $r->id }}">{{ $r->title }}</span>
                            </a>
                            <form action="{{ route('chat.destroy', $r->id) }}" method="POST" 
                                  class="absolute right-2 top-1/2 -translate-y-1/2 opacity-0 group-hover:opacity-100 transition-opacity"
                                  onsubmit="return confirm('Delete this chat?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="p-1.5 text-hermes-muted hover:text-hermes-danger hover:bg-hermes-danger/10 rounded-lg transition">
                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                </button>
                            </form>
                        </div>
                    @endforeach
                @else
                    <div class="text-center py-8">
                        <i data-lucide="message-square-dashed" class="w-8 h-8 text-hermes-border mx-auto mb-2"></i>
                        <p class="text-xs text-hermes-muted">No conversations yet</p>
                    </div>
                @endif
            </div>
            
            <!-- Sidebar Footer -->
            <div class="p-4 border-t border-hermes-border">
                <a href="{{ route('brains.index') }}" class="hermes-sidebar-item">
                    <i data-lucide="database" class="w-4 h-4"></i>
                    <span>Knowledge Base</span>
                </a>
            </div>
        </aside>

        <!-- MAIN CHAT AREA -->
        <div class="flex-1 flex flex-col bg-hermes-bg overflow-hidden w-full h-[calc(100dvh-0px)]">
            
            <!-- Chat Header -->
            <header class="px-4 md:px-6 py-3 border-b border-hermes-border bg-hermes-surface flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <!-- Mobile Sidebar Toggle -->
                    <button @click="sidebarOpen = true" class="md:hidden hermes-btn-icon shrink-0">
                        <i data-lucide="panel-left" class="w-5 h-5"></i>
                    </button>
                    
                    <div class="min-w-0">
                        <h1 class="font-semibold text-hermes-text truncate" id="header-room-title">{{ $room->title }}</h1>
                        <div class="flex items-center gap-2 text-xs text-hermes-muted mt-2">
                            <span class="hermes-badge bg-hermes-accent/10 text-hermes-accent">
                                {{ $room->category ?? 'General' }}
                            </span>
                            <button onclick="openSkillsPanel()" id="skills-badge" class="hermes-badge bg-hermes-hover text-hermes-muted hover:bg-hermes-accent/10 hover:text-hermes-accent transition cursor-pointer">
                                <i data-lucide="book-open" class="w-3 h-3"></i>
                                <span id="skills-count">{{ $room->skills()->where('is_active', true)->count() }} skills</span>
                            </button>
                            <span id="memory-cycle-indicator" class="hermes-badge bg-hermes-hover text-hermes-muted">
                                <i data-lucide="layers" class="w-3 h-3"></i>
                                <span id="memory-cycle-count">0/10</span>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Model Selector -->
                <div class="flex items-center gap-3 shrink-0">
                    @if(($room->persona ?? 'general') === 'education')
                    <button type="button" id="skripsiToggleBtn" onclick="toggleSkripsiMode()" 
                        class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg border border-hermes-border hover:border-hermes-muted transition-all cursor-pointer"
                        title="Activate Skripsi Mode (Zero-Similarity Engine)">
                        <span class="w-3.5 h-3.5 rounded-full border-2 border-hermes-border flex items-center justify-center transition-all" id="skripsiRadioOuter">
                            <span class="w-1.5 h-1.5 rounded-full bg-transparent transition-all" id="skripsiRadioDot"></span>
                        </span>
                        <span class="text-xs font-medium text-hermes-muted transition-colors" id="skripsiLabel">Skripsi</span>
                    </button>
                    @endif
                    <button type="button" id="deepseekProToggleBtn" onclick="toggleDeepseekPro()" 
                        class="hidden items-center gap-2 px-2.5 py-1.5 rounded-lg border border-hermes-border hover:border-blue-400/50 transition-all cursor-pointer"
                        title="Activate DeepSeek Pro (Deep Thinking + Reasoning)">
                        <span class="w-3.5 h-3.5 rounded-full border-2 border-hermes-border flex items-center justify-center transition-all" id="dsProRadioOuter">
                            <span class="w-1.5 h-1.5 rounded-full bg-transparent transition-all" id="dsProRadioDot"></span>
                        </span>
                        <span class="text-xs font-medium text-hermes-muted transition-colors" id="dsProLabel">DeepSeek Pro</span>
                    </button>
                    <select id="modelSelect" class="hermes-input py-2 px-3 text-sm w-36 md:w-44 cursor-pointer">
                        <option value="ollama" {{ request('model') === 'ollama' ? 'selected' : '' }}>Gemma4 12B</option>
                        <option value="ollama_cloud" {{ request('model') === 'ollama_cloud' ? 'selected' : '' }}>Gemma4 31B Cloud</option>
                        <option value="deepseek" {{ request('model') === 'deepseek' ? 'selected' : '' }}>DeepSeek API</option>
                    </select>
                </div>
            </header>

            <!-- Chat Messages -->
            <div id="chat-container" class="flex-1 overflow-y-auto p-4 md:p-6 space-y-4 scroll-smooth">
                @foreach ($messages as $msg)
                    <div class="flex {{ $msg->sender_type == 'user' ? 'justify-end' : 'justify-start' }} message-wrapper" data-id="{{ $msg->id }}">
                        @if ($msg->sender_type == 'user')
                            <div class="flex flex-col items-end max-w-[90%] md:max-w-[80%]">
                                <div class="hermes-message-user">
                                    <div class="text-sm whitespace-pre-wrap leading-relaxed">{{ $msg->content }}</div>
                                    @if(!empty($msg->context_code) || !empty($msg->search_context))
                                        <div class="flex flex-wrap gap-2 mt-2 pt-2 border-t border-white/20">
                                            @if(!empty($msg->context_code))
                                                <span class="hermes-badge bg-white/20 text-white/90">
                                                    <i data-lucide="paperclip" class="w-3 h-3"></i>
                                                    File
                                                </span>
                                            @endif
                                            @if(!empty($msg->search_context))
                                                <span class="hermes-badge bg-emerald-500/30 text-emerald-200">
                                                    <i data-lucide="globe" class="w-3 h-3"></i>
                                                    Web
                                                </span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1 px-1 mt-1.5">
                                    <button onclick="deleteMessage({{ $msg->id }}, this)" class="hermes-btn-icon p-1 text-hermes-muted hover:text-hermes-danger" title="Delete">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                    </button>
                                </div>
                            </div>
                        @else
                            <div class="flex flex-col items-start max-w-[90%] md:max-w-[75%] w-full">
                                <div class="hermes-message-bot w-full overflow-x-auto">
                                    <textarea class="hidden raw-message-content">{{ $msg->content }}</textarea>
                                    <div class="text-sm leading-relaxed ai-markdown parsed-message-content"></div>
                                </div>
                                <div class="flex items-center gap-1 px-1 mt-1.5">
                                    <button onclick="rateMessage({{ $msg->id }}, 3, this)" class="hermes-btn-icon p-1 {{ $msg->satisfaction_score === 3 ? 'text-hermes-success' : 'text-hermes-muted hover:text-hermes-success' }}" title="Good">
                                        <i data-lucide="thumbs-up" class="w-3.5 h-3.5"></i>
                                    </button>
                                    <button onclick="rateMessage({{ $msg->id }}, 1, this)" class="hermes-btn-icon p-1 {{ $msg->satisfaction_score === 1 ? 'text-hermes-danger' : 'text-hermes-muted hover:text-hermes-danger' }}" title="Poor">
                                        <i data-lucide="thumbs-down" class="w-3.5 h-3.5"></i>
                                    </button>
                                    <button onclick="deleteMessage({{ $msg->id }}, this)" class="hermes-btn-icon p-1 text-hermes-muted hover:text-hermes-danger" title="Delete">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
                
                <!-- Loading Indicator -->
                <div id="loading-indicator" class="hidden flex justify-start">
                    <div class="flex items-center gap-3 px-4 py-3 bg-hermes-card border border-hermes-border rounded-2xl rounded-bl-sm">
                        <div class="flex space-x-1">
                            <div class="h-2 w-2 bg-hermes-accent rounded-full animate-bounce"></div>
                            <div class="h-2 w-2 bg-hermes-accent rounded-full animate-bounce [animation-delay:0.15s]"></div>
                            <div class="h-2 w-2 bg-hermes-accent rounded-full animate-bounce [animation-delay:0.3s]"></div>
                        </div>
                        <span id="loading-text" class="text-xs text-hermes-muted">Thinking...</span>
                    </div>
                </div>
            </div>

            <!-- Composer Area -->
            <div class="p-3 md:p-4 border-t border-hermes-border bg-hermes-surface">
                <form action="{{ route('chat.send', $room->id) }}" method="POST" id="chatForm">
                    @csrf
                    <input type="hidden" name="model_name" id="selectedModel" value="{{ request('model') ?? 'ollama' }}">
                    <input type="hidden" name="message" id="actual_message_input">
                    <input type="hidden" name="context_code" id="context_code_input" value="">
                    <input type="hidden" name="use_web_search" id="use_web_search_input" value="0">
                    <input type="hidden" name="persona_override" id="persona_override_input" value="">
                    <input type="hidden" name="deepseek_pro" id="deepseek_pro_input" value="0">
                    <input type="file" id="fileUploadInput" class="hidden" accept=".pdf,.doc,.docx,.txt,.php,.js,.py,.html,.css,.json,.xml,.csv,.md" onchange="handleFileUpload(event)">

                    <!-- Attachment Indicator -->
                    <div id="attachment-indicator" class="hidden mb-2">
                        <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-hermes-accent/10 text-hermes-accent rounded-lg text-xs">
                            <i data-lucide="paperclip" class="w-3.5 h-3.5"></i>
                            <span id="attachment-text">File attached</span>
                            <button type="button" onclick="clearContextCode()" class="hover:text-hermes-danger ml-1">
                                <i data-lucide="x" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>


                    <div class="relative flex items-end gap-2 bg-hermes-card border border-hermes-border rounded-xl p-2 focus-within:border-hermes-accent transition-colors">
                        <!-- Action Buttons -->
                        <div class="flex items-center gap-1">
                            <div class="relative" x-data="{ open: false }">
                                <button type="button" @click="open = !open" class="hermes-btn-icon" title="Attach">
                                    <i data-lucide="plus" class="w-5 h-5"></i>
                                </button>
                                <div x-show="open" @click.away="open = false"
                                     x-transition
                                     class="absolute bottom-full left-0 mb-2 w-48 py-2 bg-hermes-card border border-hermes-border rounded-xl shadow-xl z-50">
                                    <button type="button" @click="open = false; openContextModal()" class="hermes-dropdown-item w-full">
                                        <i data-lucide="code" class="w-4 h-4"></i>
                                        <span>Paste Code</span>
                                    </button>
                                    <button type="button" @click="open = false; document.getElementById('fileUploadInput').click()" class="hermes-dropdown-item w-full">
                                        <i data-lucide="file-up" class="w-4 h-4"></i>
                                        <span>Upload File</span>
                                    </button>
                                    <button type="button" @click="open = false; openSkillsPanel()" class="hermes-dropdown-item text-start w-full">
                                        <i data-lucide="book-open" class="w-4 h-4"></i>
                                        <span>Room Skills</span>
                                    </button>
                                </div>
                            </div>
                            
                            <button type="button" onclick="toggleWebSearch()" id="webSearchBtn" class="hermes-btn-icon" title="Web Search">
                                <i data-lucide="globe" class="w-5 h-5"></i>
                            </button>
                        </div>

                        <!-- Editor -->
                        <div id="editor" contenteditable="true"
                             class="flex-1 py-2 px-3 min-h-[24px] max-h-[150px] overflow-y-auto text-sm text-hermes-text outline-none"
                             data-placeholder="Message AI..."></div>

                        <!-- Send Button -->
                        <button type="submit" id="sendBtn" class="hermes-btn-icon bg-hermes-accent text-white hover:bg-indigo-600">
                            <i data-lucide="arrow-up" class="w-5 h-5"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Status Bar (Hermes Style) -->
            <div class="hermes-status-bar" id="status-bar">
                <div class="hermes-status-item">
                    <i data-lucide="cpu" class="w-3.5 h-3.5"></i>
                    <span id="status-model">
                        {{ request('model') === 'deepseek' ? 'DeepSeek' : (request('model') === 'ollama_cloud' ? 'Gemma4 31-Cloud' : 'Gemma4 12B') }}
                    </span>
                </div>
                <div class="hermes-status-item">
                    <i data-lucide="user-circle" class="w-3.5 h-3.5"></i>
                    <span id="status-persona">{{ $room->category ?? 'General Assistant' }}</span>
                </div>
                <div class="flex-1"></div>
                <div class="hermes-status-item" id="connection-status">
                    <span class="hermes-status-dot connected" id="status-dot"></span>
                    <span id="status-text">Connected</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Context Modal Template -->
    <template id="context-modal-template">
        <div class="text-left">
            <div class="flex items-center gap-3 border-b border-hermes-border pb-4 mb-4">
                <div class="p-2 bg-hermes-accent/10 rounded-xl">
                    <i data-lucide="code" class="w-6 h-6 text-hermes-accent"></i>
                </div>
                <h2 class="text-lg font-bold text-hermes-text">Paste Code</h2>
            </div>
            <p class="text-sm text-hermes-muted mb-4">Paste your code snippet. AI will analyze it with your message.</p>
            <textarea id="custom-context-textarea" 
                class="w-full h-64 p-4 bg-hermes-surface border border-hermes-border rounded-xl text-sm font-mono text-hermes-text focus:ring-2 focus:ring-hermes-accent focus:border-transparent outline-none resize-none transition-all placeholder-hermes-muted" 
                placeholder="Paste your code here..."></textarea>
        </div>
    </template>

    <script>
        marked.setOptions({
            highlight: function(code, lang) {
                const language = hljs.getLanguage(lang) ? lang : 'plaintext';
                return hljs.highlight(code, { language }).value;
            },
            breaks: true,
        });

        const editor = document.getElementById('editor');
        const hiddenInput = document.getElementById('actual_message_input');
        const contextInput = document.getElementById('context_code_input');
        const webSearchInput = document.getElementById('use_web_search_input');
        const webSearchBtn = document.getElementById('webSearchBtn');
        const sendBtn = document.getElementById('sendBtn');
        const loadingIndicator = document.getElementById('loading-indicator');
        const loadingText = document.getElementById('loading-text');
        const chatContainer = document.getElementById('chat-container');
        const attachmentIndicator = document.getElementById('attachment-indicator');
        const statusDot = document.getElementById('status-dot');
        const statusText = document.getElementById('status-text');
        const statusModel = document.getElementById('status-model');
        
        const personaOverrideInput = document.getElementById('persona_override_input');
        let streamingMarkdownText = "";
        let isWebSearchEnabled = false;
        let isSkripsiMode = false;
        let currentUserMessageId = null;
        let currentBotWrapper = null;
        let isKnowledgeGap = false;
        let isWebSearchSuggestion = false;
        let currentQueryType = null;

        document.addEventListener('DOMContentLoaded', () => {
            chatContainer.scrollTo(0, chatContainer.scrollHeight);
            document.querySelectorAll('.message-wrapper').forEach(wrapper => {
                const rawElem = wrapper.querySelector('.raw-message-content');
                const parseElem = wrapper.querySelector('.parsed-message-content');
                if (rawElem && parseElem) {
                    parseElem.innerHTML = marked.parse(rawElem.value);
                }
            });
            lucide.createIcons();
        });

        function updateConnectionStatus(status) {
            statusDot.className = 'hermes-status-dot';
            if (status === 'thinking') {
                statusDot.classList.add('thinking');
                statusText.textContent = 'Thinking...';
            } else if (status === 'connected') {
                statusDot.classList.add('connected');
                statusText.textContent = 'Connected';
            } else {
                statusDot.classList.add('disconnected');
                statusText.textContent = 'Disconnected';
            }
        }

        const jitIconMap = {
            'jit_start':          '📂',
            'jit_scanning':       '🔍',
            'jit_scan_complete':  '📋',
            'jit_filtering':      '⚡',
            'jit_filtered':       '✅',
            'jit_hashing':        '🔐',
            'jit_embedding':      '🧠',
            'jit_embed_complete': '✅',
            'jit_searching':      '🎯',
            'jit_search_complete':'✅',
            'jit_error':          '❌',
        };

        function handleJitStatus(data) {
            const icon = jitIconMap[data.status] || '⏳';
            const msg = data.message || 'Processing...';

            loadingText.innerHTML = `<span class="text-hermes-accent">${icon}</span> ${msg}`;

            if (data.keywords && data.keywords.length) {
                loadingText.innerHTML += `<br><span class="text-xs text-hermes-muted mt-0.5 block">Keywords: ${data.keywords.join(', ')}</span>`;
            }

            scrollToBottom();
        }

        function toggleWebSearch() {
            isWebSearchEnabled = !isWebSearchEnabled;
            webSearchInput.value = isWebSearchEnabled ? '1' : '0';
            
            if (isWebSearchEnabled) {
                webSearchBtn.classList.add('text-emerald-400', 'bg-emerald-500/10');
                webSearchBtn.classList.remove('text-hermes-muted');
            } else {
                webSearchBtn.classList.remove('text-emerald-400', 'bg-emerald-500/10');
                webSearchBtn.classList.add('text-hermes-muted');
            }
        }

        function toggleSkripsiMode() {
            isSkripsiMode = !isSkripsiMode;
            personaOverrideInput.value = isSkripsiMode ? 'education-skripsi' : '';

            const outer = document.getElementById('skripsiRadioOuter');
            const dot = document.getElementById('skripsiRadioDot');
            const label = document.getElementById('skripsiLabel');
            const btn = document.getElementById('skripsiToggleBtn');
            const statusPersona = document.getElementById('status-persona');
            
            if (isSkripsiMode) {
                outer.classList.add('border-amber-400', 'bg-amber-400/20');
                outer.classList.remove('border-hermes-border');
                dot.classList.add('bg-amber-400');
                dot.classList.remove('bg-transparent');
                label.classList.add('text-amber-400');
                label.classList.remove('text-hermes-muted');
                btn.classList.add('border-amber-400/50', 'bg-amber-400/5');
                btn.classList.remove('border-hermes-border');
                if (statusPersona) statusPersona.textContent = 'Education — Skripsi Mode';
            } else {
                outer.classList.remove('border-amber-400', 'bg-amber-400/20');
                outer.classList.add('border-hermes-border');
                dot.classList.remove('bg-amber-400');
                dot.classList.add('bg-transparent');
                label.classList.remove('text-amber-400');
                label.classList.add('text-hermes-muted');
                btn.classList.remove('border-amber-400/50', 'bg-amber-400/5');
                btn.classList.add('border-hermes-border');
                if (statusPersona) statusPersona.textContent = '{{ $room->category ?? "General Assistant" }}';
            }
        }

        editor.addEventListener('input', function() {
            hiddenInput.value = editor.innerText;
        });

        // DeepSeek Pro toggle state
        let isDeepseekPro = false;

        function toggleDeepseekPro() {
            isDeepseekPro = !isDeepseekPro;
            document.getElementById('deepseek_pro_input').value = isDeepseekPro ? '1' : '0';

            const outer = document.getElementById('dsProRadioOuter');
            const dot = document.getElementById('dsProRadioDot');
            const label = document.getElementById('dsProLabel');
            const btn = document.getElementById('deepseekProToggleBtn');
            const statusModel = document.getElementById('status-model');

            if (isDeepseekPro) {
                outer.classList.add('border-blue-400', 'bg-blue-400/20');
                outer.classList.remove('border-hermes-border');
                dot.classList.add('bg-blue-400');
                dot.classList.remove('bg-transparent');
                label.classList.add('text-blue-400');
                label.classList.remove('text-hermes-muted');
                btn.classList.add('border-blue-400/50', 'bg-blue-400/5');
                btn.classList.remove('border-hermes-border');
                statusModel.textContent = 'DeepSeek Pro';
            } else {
                outer.classList.remove('border-blue-400', 'bg-blue-400/20');
                outer.classList.add('border-hermes-border');
                dot.classList.remove('bg-blue-400');
                dot.classList.add('bg-transparent');
                label.classList.remove('text-blue-400');
                label.classList.add('text-hermes-muted');
                btn.classList.remove('border-blue-400/50', 'bg-blue-400/5');
                btn.classList.add('border-hermes-border');
                statusModel.textContent = 'DeepSeek';
            }
        }

        function updateDeepseekProVisibility() {
            const modelValue = document.getElementById('modelSelect').value;
            const btn = document.getElementById('deepseekProToggleBtn');

            if (modelValue === 'deepseek') {
                btn.classList.remove('hidden');
                btn.classList.add('flex');
            } else {
                btn.classList.remove('flex');
                btn.classList.add('hidden');
                // Reset pro mode when switching away from DeepSeek
                if (isDeepseekPro) {
                    toggleDeepseekPro();
                }
            }
        }

        // Initialize visibility on page load
        document.addEventListener('DOMContentLoaded', () => {
            updateDeepseekProVisibility();
        });

        editor.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('chatForm').dispatchEvent(new Event('submit'));
            }
        });

        document.getElementById('chatForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const messageValue = hiddenInput.value.trim();
            const codeValue = contextInput.value.trim();
            const isSearchActive = isWebSearchEnabled;

            if (!messageValue && !codeValue) return;

            appendUserMessage(messageValue, codeValue !== '', isSearchActive);
            
            editor.innerHTML = '';
            hiddenInput.value = '';
            streamingMarkdownText = "";
            isKnowledgeGap = false;
            isWebSearchSuggestion = false;
            currentQueryType = null;
            sendBtn.disabled = true;
            sendBtn.classList.add('opacity-50', 'cursor-not-allowed');
            
            updateConnectionStatus('thinking');
            loadingText.innerText = "Thinking...";
            loadingIndicator.classList.remove('hidden');
            scrollToBottom();

            const formData = new FormData(this);
            formData.set('message', messageValue || "Please analyze the attached code.");
            formData.set('context_code', codeValue);
            formData.set('use_web_search', isSearchActive ? '1' : '0');
            formData.set('deepseek_pro', isDeepseekPro ? '1' : '0');

            clearContextCode();
            
            if (isWebSearchEnabled) toggleWebSearch();

            try {
                const response = await fetch(this.action, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                if (response.status === 429) {
                    const errorData = await response.json();
                    Swal.fire({ icon: 'warning', title: 'Rate Limited', text: errorData.message, customClass: { popup: 'my-swal-popup' } });
                    finishStreaming();
                    return;
                }

                if (!response.ok) throw new Error('Network response was not ok');
                
                const reader = response.body.getReader();
                const decoder = new TextDecoder('utf-8');
                let isFirstChunk = true;
                let textContainer = null;
                let botColDiv = null;
                let botWrapper = null;

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    const chunk = decoder.decode(value, { stream: true });
                    const events = chunk.split('\n\n');

                    for (const event of events) {
                        if (event.startsWith('data: ')) {
                            const dataStr = event.substring(6).trim();
                            if (dataStr === '[DONE]') {
                                finishStreaming();
                                return;
                            }

                            try {
                                const data = JSON.parse(dataStr);

                                if (data.user_message_id) currentUserMessageId = data.user_message_id;

                                if (data.type === 'meta') {
                                    if (data.status === 'compressing') {
                                        loadingText.innerText = "Compressing memory...";
                                    } else if (data.status === 'classified') {
                                        currentQueryType = data.query_type;
                                        console.log('Query classified as:', data.query_type, '-', data.reason);
                                    } else if (data.status === 'searching_internal') {
                                        loadingText.innerHTML = `Searching: <span class="text-hermes-accent">${data.keywords.join(', ')}</span>`;
                                    } else if (data.status === 'using_model_knowledge') {
                                        loadingText.innerText = "Using AI knowledge...";
                                    } else if (data.status === 'thinking') {
                                        loadingText.innerText = data.message || "Thinking...";
                                    } else if (data.status?.startsWith('jit_')) {
                                        handleJitStatus(data);
                                    } else if (data.status === 'suggest_web_search') {
                                        // Latest data query with no internal data - show web search prompt
                                        isWebSearchSuggestion = true;
                                        loadingIndicator.classList.add('hidden');
                                        // Use user_message_id from the event (injected by server) for reliability
                                        showWebSearchSuggestion(data.message, data.reason, data.user_message_id || currentUserMessageId);
                                        return;
                                    } else if (data.status === 'recommend_web_search') {
                                        // After response, recommend web search for potentially outdated data
                                        // Use user_message_id from the event (injected by server) for reliability
                                        showWebSearchRecommendation(data.message, data.internal_sources || [], data.user_message_id || currentUserMessageId);
                                    }
                                }

                                if (data.room_title) {
                                    document.getElementById('header-room-title').textContent = data.room_title;
                                }
                                
                                if (data.content !== undefined && (!data.type || data.type === 'chunk')) {
                                    streamingMarkdownText += data.content;

                                    if (streamingMarkdownText.includes('[KNOWLEDGE_GAP_DETECTED]')) {
                                        if (!isKnowledgeGap) {
                                            isKnowledgeGap = true;
                                            loadingIndicator.classList.add('hidden');
                                            
                                            if (isFirstChunk) {
                                                const elements = createBotMessageContainer();
                                                textContainer = elements.textContainer;
                                                botColDiv = elements.colDiv;
                                                botWrapper = elements.wrapper;
                                                currentBotWrapper = botWrapper;
                                                isFirstChunk = false;
                                            }

                                            textContainer.innerHTML = '';
                                            botColDiv.innerHTML = `
                                                <div class="hermes-message-bot bg-hermes-warning/10 border-hermes-warning/30">
                                                    <div class="flex items-center gap-2 mb-3 font-semibold text-hermes-warning">
                                                        <i data-lucide="alert-circle" class="w-5 h-5"></i>
                                                        Knowledge Gap Detected
                                                    </div>
                                                    <p class="text-sm text-hermes-muted mb-4">AI doesn't have enough information. Choose an alternative:</p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <button onclick="retryMessage('web')" class="hermes-btn bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20 text-xs">
                                                            <i data-lucide="globe" class="w-4 h-4"></i> Web Search
                                                        </button>
                                                        <button onclick="retryMessage('cloud')" class="hermes-btn bg-hermes-accent/10 text-hermes-accent hover:bg-hermes-accent/20 text-xs">
                                                            <i data-lucide="cloud" class="w-4 h-4"></i> Use DeepSeek
                                                        </button>
                                                        <button onclick="retryMessage('local_force')" class="hermes-btn bg-hermes-hover text-hermes-muted hover:bg-hermes-border text-xs">
                                                            Force Local
                                                        </button>
                                                    </div>
                                                </div>
                                            `;
                                            lucide.createIcons();
                                            scrollToBottom();
                                        }
                                        continue;
                                    }

                                    if (isFirstChunk && streamingMarkdownText.trim() === "") continue;

                                    if (isFirstChunk) {
                                        loadingIndicator.classList.add('hidden');
                                        const elements = createBotMessageContainer();
                                        textContainer = elements.textContainer;
                                        botColDiv = elements.colDiv;
                                        botWrapper = elements.wrapper;
                                        currentBotWrapper = botWrapper;
                                        isFirstChunk = false;
                                    }

                                    if (!isKnowledgeGap) {
                                        const displayText = streamingMarkdownText.replace(/\[THESIS_EVAL\][\s\S]*?\[\/THESIS_EVAL\]/g, '').trim();
                                        textContainer.innerHTML = marked.parse(displayText);
                                        scrollToBottom();
                                    }
                                }

                                if (data.message_id && !isKnowledgeGap) {
                                    botWrapper.dataset.id = data.message_id;
                                    
                                    let continueButtonHtml = '';
                                    if (data.finish_reason === 'length') {
                                        continueButtonHtml = `
                                            <button onclick="continueGeneration()" class="hermes-btn-icon p-1 text-hermes-accent hover:bg-hermes-accent/10" title="Continue">
                                                <i data-lucide="chevrons-right" class="w-3.5 h-3.5"></i>
                                            </button>
                                        `;
                                    }

                                    botColDiv.insertAdjacentHTML('beforeend', `
                                    <div class="flex items-center gap-1 px-1 mt-1.5">
                                        <button onclick="rateMessage(${data.message_id}, 3, this)" class="hermes-btn-icon p-1 text-hermes-muted hover:text-hermes-success" title="Good">
                                            <i data-lucide="thumbs-up" class="w-3.5 h-3.5"></i>
                                        </button>
                                        <button onclick="rateMessage(${data.message_id}, 1, this)" class="hermes-btn-icon p-1 text-hermes-muted hover:text-hermes-danger" title="Poor">
                                            <i data-lucide="thumbs-down" class="w-3.5 h-3.5"></i>
                                        </button>
                                        <button onclick="deleteMessage(${data.message_id}, this)" class="hermes-btn-icon p-1 text-hermes-muted hover:text-hermes-danger" title="Delete">
                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                        </button>
                                        ${continueButtonHtml}
                                    </div>
                                    `);
                                    lucide.createIcons();
                                    scrollToBottom();
                                }

                            } catch (err) {
                                console.log("Parsing chunk error", err, dataStr);
                            }
                        }
                    }
                }
            } catch (error) {
                console.error('Fetch error:', error);
                updateConnectionStatus('disconnected');
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection failed or AI is offline.', customClass: { popup: 'my-swal-popup' } });
                finishStreaming();
            }
        });

        function finishStreaming() {
            loadingIndicator.classList.add('hidden');
            sendBtn.disabled = false;
            sendBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            updateConnectionStatus('connected');
            editor.focus();
            lucide.createIcons();
        }

        function scrollToBottom() {
            chatContainer.scrollTo({ top: chatContainer.scrollHeight, behavior: 'smooth' });
        }

        function appendUserMessage(content, hasCode = false, usedWebSearch = false) {
            const msgDiv = document.createElement('div');
            msgDiv.className = `flex justify-end message-wrapper`;
            
            const displayContent = content || "Please analyze the attached code.";

            let badges = '';
            if (hasCode) {
                badges += `<span class="hermes-badge bg-white/20 text-white/90"><i data-lucide="paperclip" class="w-3 h-3"></i> File</span>`;
            }
            if (usedWebSearch) {
                badges += `<span class="hermes-badge bg-emerald-500/30 text-emerald-200"><i data-lucide="globe" class="w-3 h-3"></i> Web</span>`;
            }

            msgDiv.innerHTML = `
            <div class="flex flex-col items-end max-w-[90%] md:max-w-[80%]">
                <div class="hermes-message-user">
                    <div class="text-sm whitespace-pre-wrap leading-relaxed">${displayContent.replace(/</g, "&lt;").replace(/>/g, "&gt;")}</div>
                    ${badges ? `<div class="flex flex-wrap gap-2 mt-2 pt-2 border-t border-white/20">${badges}</div>` : ''}
                </div>
            </div>
            `;
            chatContainer.insertBefore(msgDiv, loadingIndicator);
            lucide.createIcons();
        }

        function createBotMessageContainer() {
            const wrapper = document.createElement('div');
            wrapper.className = `flex justify-start message-wrapper`;
            const colDiv = document.createElement('div');
            colDiv.className = `flex flex-col items-start max-w-[90%] md:max-w-[75%]`;

            const innerDiv = document.createElement('div');
            innerDiv.className = `hermes-message-bot w-full overflow-x-auto`;

            const textContainer = document.createElement('div');
            textContainer.className = `text-sm leading-relaxed ai-markdown`;

            innerDiv.appendChild(textContainer);
            colDiv.appendChild(innerDiv);
            wrapper.appendChild(colDiv);
            chatContainer.insertBefore(wrapper, loadingIndicator);

            return { textContainer, colDiv, wrapper };
        }

        async function openContextModal() {
            const templateHtml = document.getElementById('context-modal-template').innerHTML;
            const currentCode = document.getElementById('context_code_input').value;

            const { value: contextCode } = await Swal.fire({
                html: templateHtml,
                showCancelButton: true,
                confirmButtonText: 'Attach',
                cancelButtonText: 'Cancel',
                width: '600px',
                customClass: { popup: 'my-swal-popup' },
                didOpen: () => {
                    const textarea = document.getElementById('custom-context-textarea');
                    if (textarea) {
                        textarea.value = currentCode;
                        textarea.focus();
                    }
                },
                preConfirm: () => {
                    const textarea = document.getElementById('custom-context-textarea');
                    return textarea ? textarea.value : '';
                }
            });

            if (contextCode !== undefined && contextCode.trim() !== '') {
                document.getElementById('context_code_input').value = contextCode;
                document.getElementById('attachment-indicator').classList.remove('hidden');
                document.getElementById('attachment-text').innerText = 'Code attached';
            }
        }

        function clearContextCode() {
            const contextInput = document.getElementById('context_code_input');
            if (contextInput) contextInput.value = '';
            const attachmentIndicator = document.getElementById('attachment-indicator');
            if (attachmentIndicator) attachmentIndicator.classList.add('hidden');
        }


        async function handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            event.target.value = '';

            const formData = new FormData();
            formData.append('file', file);
            formData.append('_token', '{{ csrf_token() }}');

            Swal.fire({
                title: 'Reading file...',
                allowOutsideClick: false,
                customClass: { popup: 'my-swal-popup' },
                didOpen: () => Swal.showLoading()
            });

            try {
                const response = await fetch("{{ route('chat.uploadFile') }}", {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const result = await response.json();

                if (response.ok) {
                    document.getElementById('context_code_input').value = `[FILE: ${result.filename}]\n\n` + result.content;
                    document.getElementById('attachment-indicator').classList.remove('hidden');
                    document.getElementById('attachment-text').innerText = result.filename;
                    Swal.close();
                } else {
                    throw new Error(result.error || 'Upload failed');
                }
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'Error', text: error.message, customClass: { popup: 'my-swal-popup' } });
            }
        }
        
        function continueGeneration() {
            const hiddenInput = document.getElementById('actual_message_input');
            hiddenInput.value = "Continue from where you left off. Don't repeat, just continue.";
            document.getElementById('chatForm').dispatchEvent(new Event('submit'));
        }
        
        async function retryMessage(type) {
            if (!currentUserMessageId) {
                console.error('retryMessage: No currentUserMessageId available');
                return;
            }
            
            console.log('retryMessage: Starting retry with type:', type, 'messageId:', currentUserMessageId);
            
            if (currentBotWrapper) currentBotWrapper.remove();
            
            updateConnectionStatus('thinking');
            loadingText.innerText = "Thinking...";
            loadingIndicator.classList.remove('hidden');
            scrollToBottom();
            
            const formData = new FormData();
            formData.append('_token', '{{ csrf_token() }}');
            
            if (type === 'web') {
                formData.append('model_name', document.getElementById('selectedModel').value);
                formData.append('use_web_search', '1');
                formData.append('force_local', '0');
            } else if (type === 'cloud') {
                formData.append('model_name', 'deepseek');
                formData.append('use_web_search', '0');
                formData.append('force_local', '0');
            } else if (type === 'local_force') {
                formData.append('model_name', 'ollama');
                formData.append('use_web_search', '0');
                formData.append('force_local', '1');
            } else if (type === 'ollama_cloud_force') {
                formData.append('model_name', 'ollama_cloud');
                formData.append('use_web_search', '0');
                formData.append('force_local', '1');
            }

            if (isSkripsiMode) {
                formData.append('persona_override', 'education-skripsi');
            }

            if (isDeepseekPro) {
                formData.append('deepseek_pro', '1');
            }
            
            try {
                const response = await fetch(`/chat/{{ $room->id }}/retry/${currentUserMessageId}`, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                
                if (!response.ok) throw new Error('Network error');
                
                const reader = response.body.getReader();
                const decoder = new TextDecoder('utf-8');
                let isFirstChunk = true;
                let textContainer = null;
                let botColDiv = null;
                let botWrapper = null;
                streamingMarkdownText = "";
                isKnowledgeGap = false;

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    const chunk = decoder.decode(value, { stream: true });
                    const events = chunk.split('\n\n');

                    for (const event of events) {
                        if (event.startsWith('data: ')) {
                            const dataStr = event.substring(6).trim();
                            if (dataStr === '[DONE]') {
                                finishStreaming();
                                return;
                            }

                            try {
                                const data = JSON.parse(dataStr);

                                // Handle meta events in retry (same as main submit handler)
                                if (data.type === 'meta') {
                                    if (data.status === 'searching_internal') {
                                        loadingText.innerHTML = `Searching: <span class="text-hermes-accent">${data.keywords?.join(', ') || ''}</span>`;
                                    } else if (data.status === 'using_model_knowledge') {
                                        loadingText.innerText = "Using AI knowledge...";
                                    } else if (data.status === 'thinking') {
                                        loadingText.innerText = data.message || "Thinking...";
                                    } else if (data.status?.startsWith('jit_')) {
                                        handleJitStatus(data);
                                    } else if (data.status === 'searching_web') {
                                        loadingText.innerText = "Searching web...";
                                    } else if (data.status === 'classified') {
                                        console.log('Retry query classified as:', data.query_type);
                                    } else if (data.status === 'suggest_web_search') {
                                        // This shouldn't happen during retry with web search enabled
                                        // But handle it gracefully
                                        console.warn('Received suggest_web_search during retry - this may indicate a bug');
                                        loadingText.innerText = "Processing...";
                                    }
                                    continue;
                                }

                                if (data.content !== undefined && (!data.type || data.type === 'chunk')) {
                                    streamingMarkdownText += data.content;

                                    if (isFirstChunk && streamingMarkdownText.trim() === "") continue;

                                    if (isFirstChunk) {
                                        loadingIndicator.classList.add('hidden');
                                        const elements = createBotMessageContainer();
                                        textContainer = elements.textContainer;
                                        botColDiv = elements.colDiv;
                                        botWrapper = elements.wrapper;
                                        currentBotWrapper = botWrapper;
                                        isFirstChunk = false;
                                    }

                                    const retryDisplayText = streamingMarkdownText.replace(/\[THESIS_EVAL\][\s\S]*?\[\/THESIS_EVAL\]/g, '').trim();
                                    textContainer.innerHTML = marked.parse(retryDisplayText);
                                    scrollToBottom();
                                }

                                if (data.message_id) {
                                    botWrapper.dataset.id = data.message_id;
                                    botColDiv.insertAdjacentHTML('beforeend', `
                                    <div class="flex items-center gap-1 px-1 mt-1.5">
                                        <button onclick="rateMessage(${data.message_id}, 3, this)" class="hermes-btn-icon p-1 text-hermes-muted hover:text-hermes-success" title="Good">
                                            <i data-lucide="thumbs-up" class="w-3.5 h-3.5"></i>
                                        </button>
                                        <button onclick="rateMessage(${data.message_id}, 1, this)" class="hermes-btn-icon p-1 text-hermes-muted hover:text-hermes-danger" title="Poor">
                                            <i data-lucide="thumbs-down" class="w-3.5 h-3.5"></i>
                                        </button>
                                        <button onclick="deleteMessage(${data.message_id}, this)" class="hermes-btn-icon p-1 text-hermes-muted hover:text-hermes-danger" title="Delete">
                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                        </button>
                                    </div>
                                    `);
                                    lucide.createIcons();
                                }
                            } catch (err) {
                                console.log("Parsing error", err);
                            }
                        }
                    }
                }
            } catch (error) {
                console.error('Retry fetch error:', error);
                updateConnectionStatus('disconnected');
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Error', 
                    text: 'Failed to process web search. Please try again.',
                    customClass: { popup: 'my-swal-popup' } 
                });
                finishStreaming();
            }
        }

        async function deleteMessage(messageId, btnElement) {
            const result = await Swal.fire({
                title: 'Delete message?',
                text: "This action cannot be undone.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#2a2a2a',
                confirmButtonText: 'Delete',
                customClass: { popup: 'my-swal-popup' }
            });
            
            if (result.isConfirmed) {
                const wrapper = btnElement.closest('.message-wrapper');
                try {
                    const res = await fetch(`/chat/message/${messageId}`, {
                        method: 'DELETE',
                        headers: { "X-CSRF-TOKEN": "{{ csrf_token() }}" }
                    });
                    if (res.ok) wrapper.remove();
                } catch (e) {
                    console.error(e);
                }
            }
        }

        async function rateMessage(messageId, score, btnElement) {
            try {
                const res = await fetch(`/chat/message/${messageId}/rate`, {
                    method: 'POST',
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": "{{ csrf_token() }}"
                    },
                    body: JSON.stringify({ score: score })
                });
                
                if (res.ok) {
                    const siblings = btnElement.parentElement.querySelectorAll('button');
                    siblings.forEach(b => {
                        if (!b.title.includes('Delete')) {
                            b.classList.remove('text-hermes-success', 'text-hermes-danger');
                            b.classList.add('text-hermes-muted');
                        }
                    });
                    btnElement.classList.remove('text-hermes-muted');
                    btnElement.classList.add(score === 3 ? 'text-hermes-success' : 'text-hermes-danger');
                }
            } catch (e) {
                console.error(e);
            }
        }
        
        document.getElementById('modelSelect').addEventListener('change', function(e) {
            document.getElementById('selectedModel').value = e.target.value;
            statusModel.textContent = e.target.value === 'deepseek' ? 'DeepSeek' : e.target.value === 'ollama_cloud' ? 'Gemma4 31B Cloud' : 'Gemma4 12B';

            const url = new URL(window.location);
            url.searchParams.set('model', e.target.value);
            history.replaceState(null, '', url);

            document.querySelectorAll('aside a[href*="chat/"]').forEach(link => {
                const linkUrl = new URL(link.href);
                linkUrl.searchParams.set('model', e.target.value);
                link.href = linkUrl.toString();
            });

            updateDeepseekProVisibility();
        });

        // Store the message ID for web search suggestion flow
        let pendingWebSearchMessageId = null;

        // Web Search Suggestion - shown when query needs latest data but none in internal DB
        function showWebSearchSuggestion(message, reason, messageId) {
            // Store the message ID for when user clicks the button
            pendingWebSearchMessageId = messageId || currentUserMessageId;
            console.log('showWebSearchSuggestion: messageId received:', messageId, 'currentUserMessageId:', currentUserMessageId, 'stored:', pendingWebSearchMessageId);
            
            if (!pendingWebSearchMessageId) {
                console.error('No message ID available for web search suggestion');
                finishStreaming();
                return;
            }
            
            const wrapper = document.createElement('div');
            wrapper.className = 'flex justify-start message-wrapper web-search-suggestion';
            wrapper.innerHTML = `
                <div class="flex flex-col items-start max-w-[90%] md:max-w-[75%] w-full">
                    <div class="hermes-message-bot bg-amber-500/10 border-amber-500/30 w-full">
                        <div class="flex items-center gap-2 mb-3 font-semibold text-amber-400">
                            <i data-lucide="search" class="w-5 h-5"></i>
                            Data Terbaru Diperlukan
                        </div>
                        <p class="text-sm text-hermes-muted mb-4">${message}</p>
                        <div class="flex flex-wrap gap-2">
                            <button onclick="confirmWebSearch()" class="hermes-btn bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20 text-sm flex items-center gap-2">
                                <i data-lucide="globe" class="w-4 h-4"></i>
                                Ya, Cari di Web
                            </button>
                            <button onclick="skipWebSearch()" class="hermes-btn bg-hermes-hover text-hermes-muted hover:bg-hermes-border text-sm flex items-center gap-2">
                                <i data-lucide="brain" class="w-4 h-4"></i>
                                Gunakan Pengetahuan AI
                            </button>
                        </div>
                    </div>
                </div>
            `;
            chatContainer.insertBefore(wrapper, loadingIndicator);
            lucide.createIcons();
            scrollToBottom();
            finishStreaming();
        }

        // Store message ID for web search recommendation flow
        let pendingRecommendationMessageId = null;

        // Web Search Recommendation - shown after response when data might be outdated
        function showWebSearchRecommendation(message, internalSources, messageId) {
            // Store the message ID for when user clicks the button
            pendingRecommendationMessageId = messageId || currentUserMessageId;
            console.log('showWebSearchRecommendation: messageId received:', messageId, 'currentUserMessageId:', currentUserMessageId, 'stored:', pendingRecommendationMessageId);
            
            const sourcesHtml = internalSources.length > 0 
                ? `<div class="text-xs text-hermes-muted mt-2">Sumber internal: ${internalSources.join(', ')}</div>`
                : '';
            
            const wrapper = document.createElement('div');
            wrapper.className = 'flex justify-start message-wrapper web-search-recommendation';
            wrapper.innerHTML = `
                <div class="flex flex-col items-start max-w-[90%] md:max-w-[75%] w-full">
                    <div class="px-4 py-3 bg-blue-500/10 border border-blue-500/20 rounded-xl mt-2">
                        <div class="flex items-center gap-2 text-blue-400 text-sm">
                            <i data-lucide="info" class="w-4 h-4"></i>
                            <span>${message}</span>
                        </div>
                        ${sourcesHtml}
                        <button onclick="confirmWebSearchFromRecommendation(this)" class="mt-3 hermes-btn bg-blue-500/10 text-blue-400 hover:bg-blue-500/20 text-xs flex items-center gap-2">
                            <i data-lucide="globe" class="w-3.5 h-3.5"></i>
                            Cari Update Terbaru
                        </button>
                    </div>
                </div>
            `;
            chatContainer.insertBefore(wrapper, loadingIndicator);
            lucide.createIcons();
            scrollToBottom();
        }

        // Confirm web search - user clicked "Ya, Cari di Web"
        async function confirmWebSearch() {
            // Remove the suggestion wrapper
            document.querySelector('.web-search-suggestion')?.remove();
            
            // Use the stored message ID from the suggestion flow
            const messageId = pendingWebSearchMessageId || currentUserMessageId;
            if (!messageId) {
                console.error('No message ID available for web search');
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Error', 
                    text: 'Cannot perform web search: message ID not found. Please try again.',
                    customClass: { popup: 'my-swal-popup' } 
                });
                return;
            }
            
            // Set currentUserMessageId to ensure retryMessage uses the correct ID
            currentUserMessageId = messageId;
            
            // Enable web search and retry
            retryMessage('web');
        }

        // Skip web search - user wants to use AI knowledge instead
        async function skipWebSearch() {
            // Remove the suggestion wrapper
            document.querySelector('.web-search-suggestion')?.remove();
            
            // Use the stored message ID from the suggestion flow
            const messageId = pendingWebSearchMessageId || currentUserMessageId;
            if (!messageId) {
                console.error('No message ID available for skip web search');
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Error', 
                    text: 'Cannot process: message ID not found. Please try again.',
                    customClass: { popup: 'my-swal-popup' } 
                });
                return;
            }
            
            // Set currentUserMessageId to ensure retryMessage uses the correct ID
            currentUserMessageId = messageId;
            
            // Force local response without RAG constraints
            retryMessage('local_force');
        }

        // Confirm web search from recommendation
        async function confirmWebSearchFromRecommendation(btnElement) {
            // Remove the recommendation wrapper
            btnElement.closest('.web-search-recommendation')?.remove();
            
            // Use the stored message ID from the recommendation flow
            const messageId = pendingRecommendationMessageId || currentUserMessageId;
            if (!messageId) {
                console.error('No message ID available for web search recommendation');
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Error', 
                    text: 'Cannot perform web search: message ID not found. Please try again.',
                    customClass: { popup: 'my-swal-popup' } 
                });
                return;
            }
            
            // Set currentUserMessageId to ensure retryMessage uses the correct ID
            currentUserMessageId = messageId;
            
            // Retry with web search
            retryMessage('web');
        }

        // =============================================
        // ROOM SKILLS MANAGEMENT
        // =============================================
        const SKILLS_API_BASE = `/chat/{{ $room->id }}/skills`;
        let roomSkills = [];

        async function loadSkills() {
            try {
                const res = await fetch(SKILLS_API_BASE, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    roomSkills = data.skills;
                    updateSkillsBadge();
                }
            } catch (e) {
                console.error('Failed to load skills:', e);
            }
        }

        function updateSkillsBadge() {
            const activeCount = roomSkills.filter(s => s.is_active).length;
            const badge = document.getElementById('skills-count');
            if (badge) badge.textContent = `${activeCount} skill${activeCount !== 1 ? 's' : ''}`;
        }

        async function openSkillsPanel() {
            await loadSkills();

            const skillListHtml = roomSkills.length > 0 
                ? roomSkills.map(skill => `
                    <div class="flex items-center justify-between p-3 bg-hermes-surface rounded-lg border border-hermes-border" id="skill-item-${skill.id}">
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <button onclick="toggleSkill(${skill.id})" class="shrink-0 w-8 h-5 rounded-full transition-colors relative ${skill.is_active ? 'bg-hermes-accent' : 'bg-hermes-border'}" title="${skill.is_active ? 'Disable' : 'Enable'}">
                                <span class="absolute top-0.5 ${skill.is_active ? 'right-0.5' : 'left-0.5'} w-4 h-4 bg-white rounded-full shadow transition-all"></span>
                            </button>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-hermes-text truncate">${skill.title}</p>
                                <p class="text-xs text-hermes-muted">${skill.source_type === 'file_upload' ? '📄 ' + (skill.original_filename || 'File') : '✏️ Manual'}</p>
                            </div>
                        </div>
                        <button onclick="deleteSkill(${skill.id})" class="shrink-0 p-1.5 text-hermes-muted hover:text-hermes-danger hover:bg-hermes-danger/10 rounded-lg transition" title="Remove">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </div>
                `).join('')
                : '<p class="text-sm text-hermes-muted text-center py-4">No skills added yet. Add one below.</p>';

            const { value: action } = await Swal.fire({
                html: `
                    <div class="text-left">
                        <div class="flex items-center gap-3 border-b border-hermes-border pb-4 mb-4">
                            <div class="p-2 bg-hermes-accent/10 rounded-xl">
                                <i data-lucide="book-open" class="w-6 h-6 text-hermes-accent"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-hermes-text">Room Skills</h2>
                                <p class="text-xs text-hermes-muted">Rules & instructions specific to this room</p>
                            </div>
                        </div>
                        <div class="space-y-2 max-h-[250px] overflow-y-auto mb-4" id="skills-list-container">
                            ${skillListHtml}
                        </div>
                        <div class="border-t border-hermes-border pt-4">
                            <p class="text-xs font-semibold text-hermes-muted uppercase tracking-wider mb-3">Add New Skill</p>
                            <div class="flex gap-2">
                                <button type="button" id="btn-add-skill-manual" class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 border border-hermes-border rounded-lg text-sm text-hermes-text hover:border-hermes-accent hover:bg-hermes-accent/5 transition">
                                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    Write Manually
                                </button>
                                <button type="button" id="btn-add-skill-file" class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 border border-hermes-border rounded-lg text-sm text-hermes-text hover:border-hermes-accent hover:bg-hermes-accent/5 transition">
                                    <i data-lucide="file-up" class="w-4 h-4"></i>
                                    Upload .md / .txt
                                </button>
                            </div>
                        </div>
                    </div>
                `,
                showConfirmButton: false,
                showCloseButton: true,
                width: '550px',
                customClass: { popup: 'my-swal-popup' },
                didOpen: () => {
                    lucide.createIcons();
                    document.getElementById('btn-add-skill-manual').addEventListener('click', () => {
                        Swal.close();
                        openAddSkillManual();
                    });
                    document.getElementById('btn-add-skill-file').addEventListener('click', () => {
                        Swal.close();
                        openAddSkillFile();
                    });
                }
            });
        }

        async function openAddSkillManual() {
            const { value: formData } = await Swal.fire({
                html: `
                    <div class="text-left">
                        <div class="flex items-center gap-3 border-b border-hermes-border pb-4 mb-4">
                            <div class="p-2 bg-hermes-accent/10 rounded-xl">
                                <i data-lucide="edit-3" class="w-6 h-6 text-hermes-accent"></i>
                            </div>
                            <h2 class="text-lg font-bold text-hermes-text">Add Skill</h2>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-hermes-muted uppercase tracking-wider mb-1.5">Skill Title</label>
                                <input type="text" id="skill-title-input" 
                                    class="w-full p-3 bg-hermes-surface border border-hermes-border rounded-xl text-sm text-hermes-text focus:ring-2 focus:ring-hermes-accent focus:border-transparent outline-none transition placeholder-hermes-muted"
                                    placeholder="e.g. Laravel Coding Standards">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-hermes-muted uppercase tracking-wider mb-1.5">Content (Markdown supported)</label>
                                <textarea id="skill-content-input" rows="10"
                                    class="w-full p-3 bg-hermes-surface border border-hermes-border rounded-xl text-sm font-mono text-hermes-text focus:ring-2 focus:ring-hermes-accent focus:border-transparent outline-none resize-none transition placeholder-hermes-muted"
                                    placeholder="Write your skill/rules in Markdown format..."></textarea>
                            </div>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Add Skill',
                cancelButtonText: 'Cancel',
                width: '600px',
                customClass: { popup: 'my-swal-popup' },
                didOpen: () => {
                    lucide.createIcons();
                    document.getElementById('skill-title-input').focus();
                },
                preConfirm: () => {
                    const title = document.getElementById('skill-title-input').value.trim();
                    const content = document.getElementById('skill-content-input').value.trim();
                    if (!title) { Swal.showValidationMessage('Title is required'); return false; }
                    if (!content) { Swal.showValidationMessage('Content is required'); return false; }
                    return { title, content };
                }
            });

            if (formData) {
                await saveSkill(formData.title, formData.content);
            }
        }

        async function openAddSkillFile() {
            const { value: formData } = await Swal.fire({
                html: `
                    <div class="text-left">
                        <div class="flex items-center gap-3 border-b border-hermes-border pb-4 mb-4">
                            <div class="p-2 bg-hermes-accent/10 rounded-xl">
                                <i data-lucide="file-up" class="w-6 h-6 text-hermes-accent"></i>
                            </div>
                            <h2 class="text-lg font-bold text-hermes-text">Upload Skill File</h2>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-hermes-muted uppercase tracking-wider mb-1.5">Skill Title</label>
                                <input type="text" id="skill-file-title-input" 
                                    class="w-full p-3 bg-hermes-surface border border-hermes-border rounded-xl text-sm text-hermes-text focus:ring-2 focus:ring-hermes-accent focus:border-transparent outline-none transition placeholder-hermes-muted"
                                    placeholder="e.g. Developer Guidelines">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-hermes-muted uppercase tracking-wider mb-1.5">File (.md or .txt)</label>
                                <input type="file" id="skill-file-input" accept=".md,.txt"
                                    class="w-full p-3 bg-hermes-surface border border-hermes-border rounded-xl text-sm text-hermes-text file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-hermes-accent/10 file:text-hermes-accent hover:file:bg-hermes-accent/20 cursor-pointer">
                                <p class="text-xs text-hermes-muted mt-1.5">Max 2MB. Supported: .md, .txt</p>
                            </div>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Upload & Add',
                cancelButtonText: 'Cancel',
                width: '500px',
                customClass: { popup: 'my-swal-popup' },
                didOpen: () => {
                    lucide.createIcons();
                    document.getElementById('skill-file-title-input').focus();
                },
                preConfirm: () => {
                    const title = document.getElementById('skill-file-title-input').value.trim();
                    const fileInput = document.getElementById('skill-file-input');
                    if (!title) { Swal.showValidationMessage('Title is required'); return false; }
                    if (!fileInput.files[0]) { Swal.showValidationMessage('Please select a file'); return false; }
                    return { title, file: fileInput.files[0] };
                }
            });

            if (formData) {
                await saveSkillFile(formData.title, formData.file);
            }
        }

        async function saveSkill(title, content) {
            try {
                const res = await fetch(SKILLS_API_BASE, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ title, content })
                });
                const data = await res.json();
                if (res.ok && data.status === 'success') {
                    roomSkills.push(data.skill);
                    updateSkillsBadge();
                    Swal.fire({ icon: 'success', title: 'Skill Added', text: data.message, timer: 1500, showConfirmButton: false, customClass: { popup: 'my-swal-popup' } });
                } else {
                    throw new Error(data.error || 'Failed to save skill');
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Error', text: e.message, customClass: { popup: 'my-swal-popup' } });
            }
        }

        async function saveSkillFile(title, file) {
            const formData = new FormData();
            formData.append('title', title);
            formData.append('file', file);
            formData.append('_token', '{{ csrf_token() }}');

            try {
                const res = await fetch(SKILLS_API_BASE, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await res.json();
                if (res.ok && data.status === 'success') {
                    roomSkills.push(data.skill);
                    updateSkillsBadge();
                    Swal.fire({ icon: 'success', title: 'Skill Added', text: data.message, timer: 1500, showConfirmButton: false, customClass: { popup: 'my-swal-popup' } });
                } else {
                    throw new Error(data.error || 'Failed to upload skill');
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Error', text: e.message, customClass: { popup: 'my-swal-popup' } });
            }
        }

        async function toggleSkill(skillId) {
            try {
                const res = await fetch(`${SKILLS_API_BASE}/${skillId}/toggle`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                    }
                });
                const data = await res.json();
                if (res.ok && data.status === 'success') {
                    const skill = roomSkills.find(s => s.id === skillId);
                    if (skill) skill.is_active = data.is_active;
                    updateSkillsBadge();
                    openSkillsPanel();
                }
            } catch (e) {
                console.error('Toggle skill error:', e);
            }
        }

        async function deleteSkill(skillId) {
            const confirm = await Swal.fire({
                title: 'Remove this skill?',
                text: 'This cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Remove',
                customClass: { popup: 'my-swal-popup' }
            });

            if (!confirm.isConfirmed) return;

            try {
                const res = await fetch(`${SKILLS_API_BASE}/${skillId}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                    }
                });
                const data = await res.json();
                if (res.ok && data.status === 'success') {
                    roomSkills = roomSkills.filter(s => s.id !== skillId);
                    updateSkillsBadge();
                    const el = document.getElementById(`skill-item-${skillId}`);
                    if (el) el.remove();
                }
            } catch (e) {
                console.error('Delete skill error:', e);
            }
        }

        // Load skills on page init
        document.addEventListener('DOMContentLoaded', () => loadSkills());

        // Memory cycle indicator
        document.addEventListener('DOMContentLoaded', () => {
            const cycleCountText = document.getElementById('memory-cycle-count');
            const MAX_MESSAGES = 10;
            
            function updateMemoryCycle() {
                const messageWrappers = document.querySelectorAll('.message-wrapper');
                let currentCount = messageWrappers.length;
                let displayCount = currentCount % MAX_MESSAGES || (currentCount > 0 ? MAX_MESSAGES : 0);
                cycleCountText.innerText = `${displayCount}/${MAX_MESSAGES}`;
            }

            updateMemoryCycle();

            const chatContainer = document.getElementById('chat-container');
            if (chatContainer) {
                const observer = new MutationObserver(() => updateMemoryCycle());
                observer.observe(chatContainer, { childList: true });
            }
        });
    </script>
@endsection
