@extends('layouts.app')

@section('content')
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .my-swal-popup { border-radius: 16px !important; background: rgb(var(--hermes-card)) !important; border: 1px solid rgb(var(--hermes-border)) !important; color: rgb(var(--hermes-text)) !important; }

        .analysis-content h1 { color: rgb(var(--hermes-text)); font-size: 1.125rem; font-weight: 700; margin-bottom: 0.75rem; }
        .analysis-content h2 { color: rgb(var(--hermes-text)); font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem; margin-top: 1.25rem; }
        .analysis-content h3 { color: rgb(var(--hermes-text)); font-size: 0.875rem; font-weight: 600; margin-bottom: 0.5rem; margin-top: 1rem; }
        .analysis-content p { color: rgb(var(--hermes-muted)); font-size: 0.875rem; line-height: 1.625; margin-bottom: 0.75rem; }
        .analysis-content ul { color: rgb(var(--hermes-muted)); font-size: 0.875rem; list-style-type: disc; padding-left: 1.25rem; margin-bottom: 0.75rem; }
        .analysis-content ol { color: rgb(var(--hermes-muted)); font-size: 0.875rem; list-style-type: decimal; padding-left: 1.25rem; margin-bottom: 0.75rem; }
        .analysis-content ul > li + li, .analysis-content ol > li + li { margin-top: 0.25rem; }
        .analysis-content li { color: rgb(var(--hermes-muted)); }
        .analysis-content strong { color: rgb(var(--hermes-text)); font-weight: 600; }
        .analysis-content em { color: rgb(var(--hermes-muted)); font-style: italic; }
        .analysis-content code { color: #d946ef; background: rgba(217, 70, 239, 0.1); padding: 0.125rem 0.375rem; border-radius: 0.25rem; font-size: 0.75rem; }
        .dark .analysis-content code { color: #fbbf24; background: rgba(245,158,11,0.1); }
        .analysis-content blockquote { border-left: 2px solid rgba(245,158,11,0.3); padding-left: 1rem; color: rgb(var(--hermes-muted)); font-style: italic; }
        .analysis-content hr { border-color: rgb(var(--hermes-border)); margin: 1rem 0; }

        .analysis-content table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; font-size: 0.875rem; }
        .analysis-content thead { border-bottom: 2px solid rgb(var(--hermes-border)); }
        .analysis-content th { color: rgb(var(--hermes-text)); font-weight: 600; text-align: left; padding: 0.625rem 0.75rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .analysis-content td { color: rgb(var(--hermes-muted)); padding: 0.5rem 0.75rem; border-bottom: 1px solid rgb(var(--hermes-border)); }
        .analysis-content tbody tr:hover { background: rgba(99,102,241,0.04); }
        .analysis-content tbody tr:last-child td { border-bottom: none; }
    </style>

    <div class="space-y-8">
        <!-- Header -->
        <div>
            <h1 class="text-2xl font-bold text-hermes-text">Dashboard</h1>
            <p class="text-hermes-muted text-sm mt-1">Manage your AI providers and monitor analytics.</p>
        </div>

        <!-- AI Providers Card -->
        <div class="hermes-card p-6">
            <h3 class="font-semibold text-lg text-hermes-text mb-2">AI Providers</h3>
            <p class="text-sm text-hermes-muted mb-6">Toggle the provider you want to use for chat sessions.</p>
            
            <div class="flex flex-wrap gap-4">
                <!-- Ollama Toggle -->
                <div class="flex items-center gap-4 bg-hermes-surface p-3 rounded-xl border border-hermes-border">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-blue-500/10 rounded-lg flex items-center justify-center">
                            <i data-lucide="cpu" class="w-4 h-4 text-blue-400"></i>
                        </div>
                        <span class="text-sm font-medium text-hermes-text">Ollama (Gemma4 12B)</span>
                    </div>
                    <button onclick="toggleProvider('ollama')" id="toggle-ollama"
                        class="relative inline-flex h-6 w-11 items-center rounded-full {{ !empty($stats['is_ollama_mode']) ? 'bg-hermes-success' : 'bg-hermes-border' }} transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-hermes-accent focus:ring-offset-2 focus:ring-offset-hermes-bg">
                        <span id="pill-ollama"
                            class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform duration-200 {{ !empty($stats['is_ollama_mode']) ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                </div>

                <!-- Ollama Cloud Toggle -->
                <div class="flex items-center gap-4 bg-hermes-surface p-3 rounded-xl border border-hermes-border">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-blue-500/10 rounded-lg flex items-center justify-center">
                            <i data-lucide="cpu" class="w-4 h-4 text-blue-400"></i>
                        </div>
                        <span class="text-sm font-medium text-hermes-text">Ollama (Gemma4 31B Cloud)</span>
                    </div>
                    <button onclick="toggleProvider('ollama_cloud')" id="toggle-ollama_cloud"
                        class="relative inline-flex h-6 w-11 items-center rounded-full {{ !empty($stats['is_ollama_cloud_mode']) ? 'bg-hermes-success' : 'bg-hermes-border' }} transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-hermes-accent focus:ring-offset-2 focus:ring-offset-hermes-bg">
                        <span id="pill-ollama_cloud"
                            class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform duration-200 {{ !empty($stats['is_ollama_cloud_mode']) ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                </div>

                <!-- DeepSeek Toggle -->
                <div class="flex items-center gap-4 bg-hermes-surface p-3 rounded-xl border border-hermes-border">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-purple-500/10 rounded-lg flex items-center justify-center">
                            <i data-lucide="cloud" class="w-4 h-4 text-purple-400"></i>
                        </div>
                        <span class="text-sm font-medium text-hermes-text">DeepSeek API</span>
                    </div>
                    <button onclick="toggleProvider('deepseek')" id="toggle-deepseek"
                        class="relative inline-flex h-6 w-11 items-center rounded-full {{ !empty($stats['is_deepseek_mode']) ? 'bg-hermes-success' : 'bg-hermes-border' }} transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-hermes-accent focus:ring-offset-2 focus:ring-offset-hermes-bg">
                        <span id="pill-deepseek"
                            class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform duration-200 {{ !empty($stats['is_deepseek_mode']) ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="hermes-card p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-8 h-8 bg-hermes-accent/10 rounded-lg flex items-center justify-center">
                        <i data-lucide="hard-drive" class="w-4 h-4 text-hermes-accent"></i>
                    </div>
                </div>
                <p class="text-xs font-medium text-hermes-muted uppercase tracking-wider">Avg. Memory</p>
                <h3 class="text-2xl font-bold text-hermes-text mt-1">
                    {{ $stats['avg_memory'] ?? '0' }}
                    <span class="text-sm font-normal text-hermes-muted">KB</span>
                </h3>
            </div>
            
            <div class="hermes-card p-5 bg-blue-500/5 border-blue-500/20">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-8 h-8 bg-blue-500/10 rounded-lg flex items-center justify-center">
                        <i data-lucide="bot" class="w-4 h-4 text-blue-400"></i>
                    </div>
                </div>
                <p class="text-xs font-medium text-blue-400 uppercase tracking-wider">Bot Tokens/Msg</p>
                <h3 class="text-2xl font-bold text-hermes-text mt-1">
                    {{ $stats['avg_bot_tokens'] ?? '0' }}
                    <span class="text-sm font-normal text-hermes-muted">tk</span>
                </h3>
            </div>
            
            <div class="hermes-card p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-8 h-8 bg-hermes-accent/10 rounded-lg flex items-center justify-center">
                        <i data-lucide="user" class="w-4 h-4 text-hermes-accent"></i>
                    </div>
                </div>
                <p class="text-xs font-medium text-hermes-muted uppercase tracking-wider">User Tokens/Msg</p>
                <h3 class="text-2xl font-bold text-hermes-text mt-1">
                    {{ $stats['avg_user_tokens'] ?? '0' }}
                    <span class="text-sm font-normal text-hermes-muted">tk</span>
                </h3>
            </div>
            
            <div class="hermes-card p-5 bg-hermes-success/5 border-hermes-success/20">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-8 h-8 bg-hermes-success/10 rounded-lg flex items-center justify-center">
                        <i data-lucide="check-circle" class="w-4 h-4 text-hermes-success"></i>
                    </div>
                </div>
                <p class="text-xs font-medium text-hermes-success uppercase tracking-wider">Success Rate</p>
                <h3 class="text-2xl font-bold text-hermes-text mt-1">{{ $stats['success_rate'] ?? '0' }}%</h3>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="hermes-card p-6">
                <h4 class="font-semibold text-hermes-text mb-4">Satisfaction Analytics</h4>
                <div class="h-64">
                    <canvas id="satisfactionChart"></canvas>
                </div>
            </div>
            <div class="hermes-card p-6">
                <h4 class="font-semibold text-hermes-text mb-4">Content Dynamics</h4>
                <div class="h-64">
                    <canvas id="radarChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Activity Chart -->
        <div class="hermes-card p-6">
            <h4 class="font-semibold text-hermes-text mb-4">Chat Activity (Last 7 Days)</h4>
            <div class="h-72">
                <canvas id="activityChart"></canvas>
            </div>
        </div>

        <!-- Knowledge Graph Section -->
        @if($graphStats['is_enabled'])
        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold text-hermes-text">Knowledge Graph</h3>
                    <p class="text-sm text-hermes-muted mt-1">Visualize conversation context relationships and token efficiency.</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-hermes-muted">{{ $graphStats['index_progress'] }}% indexed</span>
                    <div class="w-24 h-2 bg-hermes-border rounded-full overflow-hidden">
                        <div class="h-full bg-hermes-accent transition-all" style="width: {{ $graphStats['index_progress'] }}%"></div>
                    </div>
                </div>
            </div>

            <!-- Graph Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="hermes-card p-5 bg-indigo-500/5 border-indigo-500/20">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-8 h-8 bg-indigo-500/10 rounded-lg flex items-center justify-center">
                            <i data-lucide="circle-dot" class="w-4 h-4 text-indigo-400"></i>
                        </div>
                    </div>
                    <p class="text-xs font-medium text-indigo-400 uppercase tracking-wider">Total Nodes</p>
                    <h3 class="text-2xl font-bold text-hermes-text mt-1">{{ number_format($graphStats['total_nodes']) }}</h3>
                </div>

                <div class="hermes-card p-5 bg-purple-500/5 border-purple-500/20">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-8 h-8 bg-purple-500/10 rounded-lg flex items-center justify-center">
                            <i data-lucide="git-branch" class="w-4 h-4 text-purple-400"></i>
                        </div>
                    </div>
                    <p class="text-xs font-medium text-purple-400 uppercase tracking-wider">Total Edges</p>
                    <h3 class="text-2xl font-bold text-hermes-text mt-1">{{ number_format($graphStats['total_edges']) }}</h3>
                </div>

                <div class="hermes-card p-5 bg-emerald-500/5 border-emerald-500/20">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-8 h-8 bg-emerald-500/10 rounded-lg flex items-center justify-center">
                            <i data-lucide="zap" class="w-4 h-4 text-emerald-400"></i>
                        </div>
                    </div>
                    <p class="text-xs font-medium text-emerald-400 uppercase tracking-wider">Tokens Saved</p>
                    <h3 class="text-2xl font-bold text-hermes-text mt-1">{{ number_format($graphStats['total_tokens_saved']) }}</h3>
                </div>

                <div class="hermes-card p-5">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-8 h-8 bg-hermes-accent/10 rounded-lg flex items-center justify-center">
                            <i data-lucide="network" class="w-4 h-4 text-hermes-accent"></i>
                        </div>
                    </div>
                    <p class="text-xs font-medium text-hermes-muted uppercase tracking-wider">Avg. Degree</p>
                    <h3 class="text-2xl font-bold text-hermes-text mt-1">{{ $graphStats['avg_degree'] }}</h3>
                </div>
            </div>

            <!-- Graph Visualization -->
            <div class="hermes-card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="font-semibold text-hermes-text">Interactive Knowledge Graph</h4>
                    <div class="flex items-center gap-3">
                        <select id="graphRoomFilter" class="bg-hermes-surface border border-hermes-border rounded-lg px-3 py-1.5 text-sm text-hermes-text focus:outline-none focus:ring-2 focus:ring-hermes-accent">
                            <option value="">All Rooms</option>
                        </select>
                        <button onclick="refreshGraph()" class="p-2 hover:bg-hermes-surface rounded-lg transition-colors">
                            <i data-lucide="refresh-cw" class="w-4 h-4 text-hermes-muted"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Node Type Legend -->
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 mb-4 text-xs">
                    <span class="text-hermes-muted font-medium">Nodes:</span>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-indigo-500"></span>
                        <span class="text-hermes-muted">Topic</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                        <span class="text-hermes-muted">Concept</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                        <span class="text-hermes-muted">Person</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-red-500"></span>
                        <span class="text-hermes-muted">Action</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-violet-500"></span>
                        <span class="text-hermes-muted">Entity</span>
                    </div>
                    <span class="text-hermes-border mx-1">|</span>
                    <span class="text-hermes-muted font-medium">Edges:</span>
                    <div class="flex items-center gap-1.5">
                        <svg width="24" height="8" class="shrink-0">
                            <line x1="0" y1="4" x2="24" y2="4" stroke="#888888" stroke-width="2"/>
                        </svg>
                        <span class="text-hermes-muted">Extracted</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg width="24" height="8" class="shrink-0">
                            <line x1="0" y1="4" x2="24" y2="4" stroke="#888888" stroke-width="2" stroke-dasharray="4,2"/>
                        </svg>
                        <span class="text-hermes-muted">Inferred</span>
                    </div>
                </div>

                <div id="knowledgeGraphContainer" class="h-[28rem] bg-hermes-bg rounded-lg border border-hermes-border relative">
                    @if($graphStats['total_nodes'] === 0)
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="text-center">
                            <i data-lucide="network" class="w-12 h-12 text-hermes-muted mx-auto mb-3"></i>
                            <p class="text-hermes-muted">No graph data yet.</p>
                            <p class="text-sm text-hermes-muted mt-1">Start chatting to build your knowledge graph!</p>
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Top Nodes -->
            @if(count($graphStats['top_nodes']) > 0)
            <div class="hermes-card p-6">
                <h4 class="font-semibold text-hermes-text mb-4">Top Knowledge Nodes</h4>
                <div class="space-y-3">
                    @foreach($graphStats['top_nodes'] as $nodeName => $frequency)
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-2 h-2 rounded-full bg-hermes-accent"></div>
                            <span class="text-sm text-hermes-text">{{ ucfirst($nodeName) }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-32 h-2 bg-hermes-border rounded-full overflow-hidden">
                                <div class="h-full bg-hermes-accent" style="width: {{ min(100, ($frequency / max(1, max($graphStats['top_nodes']))) * 100) }}%"></div>
                            </div>
                            <span class="text-xs text-hermes-muted w-8 text-right">{{ $frequency }}</span>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
        @endif

        <!-- User Behavior Analysis Section -->
        <div class="hermes-card p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-amber-500/10 rounded-xl flex items-center justify-center">
                        <i data-lucide="scan-search" class="w-5 h-5 text-amber-400"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-lg text-hermes-text">User Behavior Analysis</h3>
                        <p class="text-sm text-hermes-muted">AI-powered profiling based on your chat history and knowledge graph.</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    @if($latestAnalysis && $latestAnalysis->status === 'completed')
                    <div class="flex items-center gap-1.5 text-emerald-400" id="savedIndicator">
                        <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>
                        <span class="text-xs font-medium">Saved</span>
                    </div>
                    @endif
                    <select id="analysisModelSelect"
                        class="bg-hermes-surface border border-hermes-border rounded-xl px-3 py-2.5 text-sm text-hermes-text focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500/50 transition-colors">
                        <option value="ollama" {{ ($latestAnalysis && $latestAnalysis->model_used === 'ollama') ? 'selected' : '' }}>Gemma4 12B (Local)</option>
                        <option value="ollama_cloud" {{ ($latestAnalysis && $latestAnalysis->model_used === 'ollama_cloud') ? 'selected' : '' }}>Gemma4 31B (Cloud)</option>
                        <option value="deepseek" {{ (!$latestAnalysis || $latestAnalysis->model_used === 'deepseek') ? 'selected' : '' }}>DeepSeek API</option>
                    </select>
                    <button type="button" id="analyzeBtn" onclick="startAnalysis()"
                        class="px-5 py-2.5 {{ $latestAnalysis && $latestAnalysis->status === 'completed' ? 'bg-hermes-accent hover:bg-hermes-accent/80' : 'bg-amber-500 hover:bg-amber-600' }} text-white text-sm font-medium rounded-xl transition-colors duration-200 flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                        @if($latestAnalysis && $latestAnalysis->status === 'completed')
                        <i data-lucide="refresh-cw" class="w-4 h-4" id="analyzeBtnIcon"></i>
                        <span id="analyzeBtnText">Renew Analysis</span>
                        @else
                        <i data-lucide="sparkles" class="w-4 h-4" id="analyzeBtnIcon"></i>
                        <span id="analyzeBtnText">Analyze User Data</span>
                        @endif
                    </button>
                </div>
            </div>

            <div id="analysisStatusBar" class="hidden mb-4">
                <div class="flex items-center justify-between bg-hermes-surface p-3 rounded-xl border border-hermes-border">
                    <div class="flex items-center gap-3">
                        <div class="animate-spin w-5 h-5 border-2 border-amber-400 border-t-transparent rounded-full"></div>
                        <span class="text-sm text-amber-400 font-medium" id="analysisStatusText">Processing analysis...</span>
                    </div>
                    <button type="button" id="analysisCancelBtn" onclick="cancelAnalysis()"
                        class="text-xs text-red-400 hover:text-red-300 font-medium px-3 py-1 rounded-lg hover:bg-red-500/10 transition-colors">
                        Cancel
                    </button>
                </div>
            </div>

            <div id="analysisError" class="hidden mb-4">
                <div class="flex items-center gap-3 bg-red-500/10 p-3 rounded-xl border border-red-500/20">
                    <i data-lucide="alert-circle" class="w-5 h-5 text-red-400 shrink-0"></i>
                    <span class="text-sm text-red-400" id="analysisErrorText"></span>
                </div>
            </div>

            <div id="analysisResult" class="{{ $latestAnalysis && $latestAnalysis->status === 'completed' ? '' : 'hidden' }}">
                @if($latestAnalysis && $latestAnalysis->status === 'completed')
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-hermes-muted">Last analyzed: {{ $latestAnalysis->created_at->diffForHumans() }}</span>
                        <span class="text-xs text-hermes-muted/50">|</span>
                        <span class="text-xs text-hermes-muted">{{ $latestAnalysis->created_at->format('M d, Y H:i') }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-amber-400 bg-amber-500/10 px-2 py-1 rounded-full flex items-center gap-1">
                            <i data-lucide="cpu" class="w-3 h-3"></i>
                            {{ $latestAnalysis->model_used === 'ollama' ? 'Gemma4 12B' : ($latestAnalysis->model_used === 'ollama_cloud' ? 'Gemma4 31B' : 'DeepSeek') }}
                        </span>
                        <span class="text-xs text-emerald-400 bg-emerald-500/10 px-2 py-1 rounded-full flex items-center gap-1">
                            <i data-lucide="database" class="w-3 h-3"></i>
                            Saved
                        </span>
                    </div>
                </div>
                @endif
                <div id="analysisContent" class="analysis-content prose prose-invert prose-sm max-w-none bg-hermes-surface rounded-xl p-5 border border-hermes-border overflow-y-auto max-h-[32rem]">
                    @if($latestAnalysis && $latestAnalysis->status === 'completed')
                        {!! \Illuminate\Support\Str::markdown($latestAnalysis->analysis_result ?? '') !!}
                    @endif
                </div>
            </div>

            <div id="analysisEmpty" class="{{ $latestAnalysis ? 'hidden' : '' }}">
                <div class="text-center py-8">
                    <i data-lucide="scan-search" class="w-12 h-12 text-hermes-muted mx-auto mb-3 opacity-30"></i>
                    <p class="text-hermes-muted text-sm">No analysis yet. Click the button above to start.</p>
                </div>
            </div>
        </div>

         <!-- Insight Card -->
         <div class="hermes-card p-6 bg-hermes-accent/5 border-hermes-accent/20">
            <div class="flex items-start gap-4">
                <div class="p-2 bg-hermes-accent/10 rounded-xl shrink-0">
                    <i data-lucide="lightbulb" class="w-5 h-5 text-hermes-accent"></i>
                </div>
                <div>
                    <h4 class="font-semibold text-hermes-text mb-1">Optimization Insight</h4>
                    <p class="text-sm text-hermes-muted">
                        The radar chart highlights your most used topics. Consider updating your system prompt to optimize for these specific contexts.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/vis-network@9.1.6/standalone/umd/vis-network.min.js"></script>

    <script>
        async function toggleProvider(type) {
            const pill = document.getElementById(`pill-${type}`);
            const button = document.getElementById(`toggle-${type}`);
            if (!pill || !button) return;

            let isCurrentlyActive = button.classList.contains('bg-hermes-success');
            let newState = !isCurrentlyActive;

            if (newState) {
                button.classList.replace('bg-hermes-border', 'bg-hermes-success');
                pill.classList.add('translate-x-6');
                pill.classList.remove('translate-x-1');
            } else {
                button.classList.replace('bg-hermes-success', 'bg-hermes-border');
                pill.classList.remove('translate-x-6');
                pill.classList.add('translate-x-1');
            }

            const providerName = type.charAt(0).toUpperCase() + type.slice(1);

            try {
                const response = await fetch("{{ route('dashboard.updateProviderMode') }}", {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        provider: type,
                        enabled: newState
                    })
                });

                if (response.ok) {
                    Swal.fire({
                        title: 'Success',
                        text: `${providerName} has been ${newState ? 'enabled' : 'disabled'}.`,
                        icon: 'success',
                        toast: true,
                        position: 'top-end',
                        timer: 3000,
                        showConfirmButton: false,
                        customClass: { popup: 'my-swal-popup' }
                    });
                } else {
                    throw new Error("Server error");
                }
            } catch (e) {
                if (!newState) {
                    button.classList.replace('bg-hermes-border', 'bg-hermes-success');
                    pill.classList.add('translate-x-6');
                    pill.classList.remove('translate-x-1');
                } else {
                    button.classList.replace('bg-hermes-success', 'bg-hermes-border');
                    pill.classList.remove('translate-x-6');
                    pill.classList.add('translate-x-1');
                }

                Swal.fire({
                    title: 'Error',
                    text: `Failed to update ${providerName} settings.`,
                    icon: 'error',
                    toast: true,
                    position: 'top-end',
                    timer: 3000,
                    showConfirmButton: false,
                    customClass: { popup: 'my-swal-popup' }
                });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const satisfactionScores = @json($satisfactionData);
            const dbTopicData = @json($topicData);

            const happyCount = satisfactionScores['3'] || 0;
            const neutralCount = satisfactionScores['2'] || 0;
            const poorCount = satisfactionScores['1'] || 0;

            // Chart defaults - theme aware
            const rootStyles = getComputedStyle(document.documentElement);
            const chartMuted = `rgb(${rootStyles.getPropertyValue('--hermes-muted').trim()})`;
            const chartBorder = `rgb(${rootStyles.getPropertyValue('--hermes-border').trim()})`;
            Chart.defaults.color = chartMuted;
            Chart.defaults.borderColor = chartBorder;

            const ctx1 = document.getElementById('satisfactionChart').getContext('2d');
            new Chart(ctx1, {
                type: 'doughnut',
                data: {
                    labels: ['Happy', 'Neutral', 'Poor'],
                    datasets: [{
                        data: [happyCount, neutralCount, poorCount],
                        backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '75%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: chartMuted, padding: 20 }
                        }
                    }
                }
            });

            let radarLabels = ['Coding', 'Creative', 'Logic', 'General', 'Analysis'];
            let radarValues = [10, 10, 10, 10, 10];

            if (Object.keys(dbTopicData).length > 0) {
                radarLabels = Object.keys(dbTopicData);
                radarValues = Object.values(dbTopicData);
            }

            const ctx2 = document.getElementById('radarChart').getContext('2d');
            new Chart(ctx2, {
                type: 'radar',
                data: {
                    labels: radarLabels,
                    datasets: [{
                        label: 'Total Sessions',
                        data: radarValues,
                        fill: true,
                        backgroundColor: 'rgba(99, 102, 241, 0.2)',
                        borderColor: '#6366f1',
                        borderWidth: 2,
                        pointBackgroundColor: '#6366f1',
                        pointBorderColor: '#6366f1',
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            beginAtZero: true,
                            ticks: { 
                                precision: 0, 
                                color: chartMuted,
                                backdropColor: 'transparent'
                            },
                            grid: { 
                                color: chartBorder,
                                lineWidth: 1
                            },
                            angleLines: {
                                color: chartBorder,
                                lineWidth: 1
                            },
                            pointLabels: { 
                                color: chartMuted,
                                font: { size: 11 }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: { color: chartMuted }
                        }
                    }
                }
            });

            const activityLabels = @json($activityLabels);
            const activityData = @json($activityData);
            
            const ctx3 = document.getElementById('activityChart').getContext('2d');
            new Chart(ctx3, {
                type: 'bar',
                data: {
                    labels: activityLabels,
                    datasets: [{
                        label: 'Messages',
                        data: activityData,
                        backgroundColor: '#6366f1',
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0, color: chartMuted },
                            grid: { color: chartBorder }
                        },
                        x: {
                            ticks: { color: chartMuted },
                            grid: { display: false }
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            });

            // Auto-resume polling if an analysis is in progress
            @if($latestAnalysis && in_array($latestAnalysis->status, ['pending', 'processing']))
                document.getElementById('analysisModelSelect').disabled = true;
                setAnalyzingState(
                    '{{ $latestAnalysis->status === "pending" ? "Analysis queued, waiting for processing..." : (($latestAnalysis->model_used === "ollama" ? "Gemma4 12B" : ($latestAnalysis->model_used === "ollama_cloud" ? "Gemma4 31B" : "DeepSeek")) . " is analyzing your data...") }}',
                    {{ $latestAnalysis->id }}
                );
            @endif

            // Knowledge Graph Visualization
            initKnowledgeGraph();
        });

        let analysisPollingInterval = null;
        let hasExistingAnalysis = {{ $latestAnalysis && $latestAnalysis->status === 'completed' ? 'true' : 'false' }};

        function getDefaultBtnText() {
            return hasExistingAnalysis ? 'Renew Analysis' : 'Analyze User Data';
        }

        async function startAnalysis() {
            const btn = document.getElementById('analyzeBtn');
            const btnText = document.getElementById('analyzeBtnText');
            const errorDiv = document.getElementById('analysisError');
            const emptyDiv = document.getElementById('analysisEmpty');
            const modelSelect = document.getElementById('analysisModelSelect');
            const selectedModel = modelSelect.value;

            btn.disabled = true;
            modelSelect.disabled = true;
            btnText.textContent = hasExistingAnalysis ? 'Renewing...' : 'Starting...';
            errorDiv.classList.add('hidden');
            emptyDiv.classList.add('hidden');

            try {
                const response = await fetch("{{ route('dashboard.analyzeUser') }}", {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ model: selectedModel })
                });

                const data = await response.json();

                if (response.status === 409 && data.analysis_id) {
                    setAnalyzingState('Analysis already in progress. Resuming poll...', data.analysis_id);
                    return;
                }

                if (!response.ok) {
                    throw new Error(data.message || 'Failed to start analysis.');
                }

                const modelLabel = getModelLabel(selectedModel);
                const statusMsg = data.is_renewal
                    ? `Renewal queued via ${modelLabel}, combining with previous analysis...`
                    : `Analysis queued, ${modelLabel} is processing...`;
                setAnalyzingState(statusMsg, data.analysis_id);
            } catch (e) {
                btn.disabled = false;
                modelSelect.disabled = false;
                btnText.textContent = getDefaultBtnText();
                errorDiv.classList.remove('hidden');
                document.getElementById('analysisErrorText').textContent = e.message;
            }
        }

        function getModelLabel(provider) {
            const labels = {
                'ollama': 'Gemma4 12B',
                'ollama_cloud': 'Gemma4 31B',
                'deepseek': 'DeepSeek'
            };
            return labels[provider] || provider;
        }

        function setAnalyzingState(message, analysisId) {
            const btn = document.getElementById('analyzeBtn');
            const btnText = document.getElementById('analyzeBtnText');
            const statusBar = document.getElementById('analysisStatusBar');
            const statusText = document.getElementById('analysisStatusText');
            const cancelBtn = document.getElementById('analysisCancelBtn');
            const modelSelect = document.getElementById('analysisModelSelect');

            btn.disabled = true;
            modelSelect.disabled = true;
            btnText.textContent = hasExistingAnalysis ? 'Renewing...' : 'Analyzing...';
            statusBar.classList.remove('hidden');
            statusText.textContent = message;
            if (cancelBtn) cancelBtn.setAttribute('data-analysis-id', analysisId);

            pollAnalysisStatus(analysisId);
        }

        async function cancelAnalysis() {
            const cancelBtn = document.getElementById('analysisCancelBtn');
            const analysisId = cancelBtn?.getAttribute('data-analysis-id');
            if (!analysisId) return;

            if (analysisPollingInterval) clearInterval(analysisPollingInterval);

            try {
                const url = "{{ route('dashboard.cancelAnalysis', ':id') }}".replace(':id', analysisId);
                await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                });
            } catch (e) {}

            document.getElementById('analysisStatusBar').classList.add('hidden');
            document.getElementById('analyzeBtn').disabled = false;
            document.getElementById('analysisModelSelect').disabled = false;
            document.getElementById('analyzeBtnText').textContent = getDefaultBtnText();

            Swal.fire({
                title: 'Cancelled',
                text: 'Analysis has been cancelled.',
                icon: 'info',
                toast: true,
                position: 'top-end',
                timer: 3000,
                showConfirmButton: false,
                customClass: { popup: 'my-swal-popup' }
            });
        }

        function pollAnalysisStatus(analysisId) {
            if (analysisPollingInterval) clearInterval(analysisPollingInterval);

            const statusText = document.getElementById('analysisStatusText');
            const modelSelect = document.getElementById('analysisModelSelect');
            const modelLabel = getModelLabel(modelSelect.value);

            analysisPollingInterval = setInterval(async () => {
                try {
                    const url = "{{ route('dashboard.analysisStatus', ':id') }}".replace(':id', analysisId);
                    const response = await fetch(url);
                    const data = await response.json();

                    if (data.status === 'processing') {
                        statusText.textContent = hasExistingAnalysis
                            ? `${modelLabel} is renewing your analysis with latest data...`
                            : `${modelLabel} is analyzing your data...`;
                    }

                    if (data.status === 'completed') {
                        clearInterval(analysisPollingInterval);
                        showAnalysisResult(data);
                    }

                    if (data.status === 'failed') {
                        clearInterval(analysisPollingInterval);
                        showAnalysisError(data.error_message || 'Analysis failed.');
                    }
                } catch (e) {
                    clearInterval(analysisPollingInterval);
                    showAnalysisError('Lost connection while polling status.');
                }
            }, 3000);
        }

        function showAnalysisResult(data) {
            const btn = document.getElementById('analyzeBtn');
            const btnText = document.getElementById('analyzeBtnText');
            const btnIcon = document.getElementById('analyzeBtnIcon');
            const statusBar = document.getElementById('analysisStatusBar');
            const resultDiv = document.getElementById('analysisResult');
            const modelSelect = document.getElementById('analysisModelSelect');

            statusBar.classList.add('hidden');
            btn.disabled = false;
            modelSelect.disabled = false;

            btnText.textContent = 'Renew Analysis';
            btn.classList.remove('bg-amber-500', 'hover:bg-amber-600');
            btn.classList.add('bg-hermes-accent', 'hover:bg-hermes-accent/80');
            if (btnIcon) {
                btnIcon.setAttribute('data-lucide', 'refresh-cw');
                if (window.lucide) lucide.createIcons();
            }

            let savedIndicator = document.getElementById('savedIndicator');
            if (!savedIndicator) {
                savedIndicator = document.createElement('div');
                savedIndicator.id = 'savedIndicator';
                savedIndicator.className = 'flex items-center gap-1.5 text-emerald-400';
                savedIndicator.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i><span class="text-xs font-medium">Saved</span>';
                btn.parentElement.insertBefore(savedIndicator, btn);
                if (window.lucide) lucide.createIcons();
            }

            resultDiv.classList.remove('hidden');

            const now = new Date();
            const formattedDate = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' + now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
            const usedModel = data.model_used || modelSelect.value;
            const usedModelLabel = getModelLabel(usedModel);

            const metaHtml = `<div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-3">
                    <span class="text-xs text-hermes-muted">Last analyzed: ${data.created_at}</span>
                    <span class="text-xs text-hermes-muted/50">|</span>
                    <span class="text-xs text-hermes-muted">${formattedDate}</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-amber-400 bg-amber-500/10 px-2 py-1 rounded-full flex items-center gap-1">
                        <i data-lucide="cpu" class="w-3 h-3"></i>
                        ${usedModelLabel}
                    </span>
                    <span class="text-xs text-emerald-400 bg-emerald-500/10 px-2 py-1 rounded-full flex items-center gap-1">
                        <i data-lucide="database" class="w-3 h-3"></i>
                        Saved
                    </span>
                </div>
            </div>`;

            resultDiv.innerHTML = metaHtml + `<div id="analysisContent" class="analysis-content prose prose-invert prose-sm max-w-none bg-hermes-surface rounded-xl p-5 border border-hermes-border overflow-y-auto max-h-[32rem]"></div>`;

            const newContentDiv = document.getElementById('analysisContent');
            newContentDiv.innerHTML = markdownToHtml(data.analysis_result || '');

            hasExistingAnalysis = true;

            if (window.lucide) lucide.createIcons();

            Swal.fire({
                title: 'Analysis Saved',
                text: 'Your behavior analysis report has been saved. Click "Renew Analysis" anytime to update with fresh data.',
                icon: 'success',
                toast: true,
                position: 'top-end',
                timer: 5000,
                showConfirmButton: false,
                customClass: { popup: 'my-swal-popup' }
            });
        }

        function showAnalysisError(message) {
            const btn = document.getElementById('analyzeBtn');
            const btnText = document.getElementById('analyzeBtnText');
            const statusBar = document.getElementById('analysisStatusBar');
            const errorDiv = document.getElementById('analysisError');
            const modelSelect = document.getElementById('analysisModelSelect');

            statusBar.classList.add('hidden');
            btn.disabled = false;
            modelSelect.disabled = false;
            btnText.textContent = getDefaultBtnText();

            errorDiv.classList.remove('hidden');
            document.getElementById('analysisErrorText').textContent = message;

            Swal.fire({
                title: 'Analysis Failed',
                text: message,
                icon: 'error',
                toast: true,
                position: 'top-end',
                timer: 4000,
                showConfirmButton: false,
                customClass: { popup: 'my-swal-popup' }
            });
        }

        function markdownToHtml(md) {
            if (!md) return '';

            const lines = md.split('\n');
            const output = [];
            let i = 0;

            while (i < lines.length) {
                if (i + 1 < lines.length && /^\|(.+\|)+\s*$/.test(lines[i].trim()) && /^\|[\s\-:|]+\|$/.test(lines[i + 1].trim())) {
                    const headerCells = lines[i].trim().replace(/^\||\|$/g, '').split('|').map(c => c.trim());
                    const alignRow = lines[i + 1].trim().replace(/^\||\|$/g, '').split('|').map(c => c.trim());
                    const aligns = alignRow.map(col => {
                        if (/^:-+:$/.test(col)) return 'center';
                        if (/^-+:$/.test(col)) return 'right';
                        return 'left';
                    });

                    let table = '<table><thead><tr>';
                    headerCells.forEach((cell, idx) => {
                        const align = aligns[idx] || 'left';
                        table += `<th style="text-align:${align}">${inlineMarkdown(cell)}</th>`;
                    });
                    table += '</tr></thead><tbody>';

                    i += 2;
                    while (i < lines.length && /^\|(.+\|)+\s*$/.test(lines[i].trim())) {
                        const cells = lines[i].trim().replace(/^\||\|$/g, '').split('|').map(c => c.trim());
                        table += '<tr>';
                        cells.forEach((cell, idx) => {
                            const align = aligns[idx] || 'left';
                            table += `<td style="text-align:${align}">${inlineMarkdown(cell)}</td>`;
                        });
                        table += '</tr>';
                        i++;
                    }
                    table += '</tbody></table>';
                    output.push(table);
                    continue;
                }

                output.push(lines[i]);
                i++;
            }

            let html = output.join('\n')
                .replace(/^### (.+)$/gm, '<h3>$1</h3>')
                .replace(/^## (.+)$/gm, '<h2>$1</h2>')
                .replace(/^# (.+)$/gm, '<h1>$1</h1>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/`(.+?)`/g, '<code>$1</code>')
                .replace(/^> (.+)$/gm, '<blockquote><p>$1</p></blockquote>')
                .replace(/^---$/gm, '<hr class="border-hermes-border my-4">')
                .replace(/^\d+\.\s+(.+)$/gm, '<li>$1</li>')
                .replace(/^[-*]\s+(.+)$/gm, '<li>$1</li>');

            html = html.replace(/((?:<li>.*<\/li>\n?)+)/g, function(match) {
                const isOrdered = /^\d+\./.test(md.substring(md.indexOf(match.replace(/<\/?li>/g, '').trim())));
                const tag = isOrdered ? 'ol' : 'ul';
                return `<${tag}>${match}</${tag}>`;
            });

            html = html.replace(/^(?!<[houbltp]|<li|<hr|<\/|<ta|<tr|<td|<th)(.*\S.*)$/gm, '<p>$1</p>');

            return html;
        }

        function inlineMarkdown(text) {
            return text
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/`(.+?)`/g, '<code>$1</code>');
        }

        let knowledgeGraphNetwork = null;

        async function initKnowledgeGraph() {
            const container = document.getElementById('knowledgeGraphContainer');
            if (!container || {{ $graphStats['total_nodes'] }} === 0) return;

            // Load room options
            await loadGraphRooms();

            // Load and render graph
            await refreshGraph();
        }

        async function loadGraphRooms() {
            try {
                const response = await fetch("{{ route('dashboard.graphRooms') }}");
                const rooms = await response.json();
                
                const select = document.getElementById('graphRoomFilter');
                rooms.forEach(room => {
                    const option = document.createElement('option');
                    option.value = room.id;
                    option.textContent = `${room.title} (${room.node_count} nodes)`;
                    select.appendChild(option);
                });

                select.addEventListener('change', refreshGraph);
            } catch (e) {
                console.error('Failed to load graph rooms:', e);
            }
        }

        async function refreshGraph() {
            const container = document.getElementById('knowledgeGraphContainer');
            const roomFilter = document.getElementById('graphRoomFilter');
            const roomId = roomFilter ? roomFilter.value : '';

            try {
                const url = new URL("{{ route('dashboard.graphData') }}", window.location.origin);
                if (roomId) url.searchParams.set('room_id', roomId);
                url.searchParams.set('limit', 100);

                const response = await fetch(url);
                const data = await response.json();

                if (data.nodes.length === 0) {
                    container.innerHTML = `
                        <div class="absolute inset-0 flex items-center justify-center">
                            <div class="text-center">
                                <i data-lucide="network" class="w-12 h-12 text-hermes-muted mx-auto mb-3"></i>
                                <p class="text-hermes-muted">No graph data for this room.</p>
                            </div>
                        </div>
                    `;
                    if (window.lucide) lucide.createIcons();
                    return;
                }

                renderGraph(container, data);
            } catch (e) {
                console.error('Failed to load graph data:', e);
            }
        }

        function renderGraph(container, data) {
            const processedNodes = data.nodes.map(node => ({
                ...node,
                borderWidth: 3,
                borderWidthSelected: 4,
                color: {
                    background: node.color,
                    border: node.color,
                    highlight: { background: node.color, border: '#ffffff' },
                    hover: { background: node.color, border: '#ffffff' }
                },
                chosen: {
                    node: function(values) {
                        values.shadowSize = 0;
                    }
                }
            }));

            const processedEdges = data.edges.map(edge => ({
                ...edge,
                label: undefined,
                color: {
                    color: edge.dashes ? '#6b7280' : '#a1a1aa',
                    highlight: '#6366f1',
                    hover: '#818cf8'
                },
                width: edge.dashes ? 2 : 3,
                dashes: edge.dashes ? [6, 4] : false
            }));

            const nodes = new vis.DataSet(processedNodes);
            const edges = new vis.DataSet(processedEdges);

            const options = {
                nodes: {
                    shape: 'dot',
                    scaling: {
                        min: 15,
                        max: 35,
                        label: { enabled: true, min: 12, max: 18 }
                    },
                    font: {
                        color: `rgb(${getComputedStyle(document.documentElement).getPropertyValue('--hermes-text').trim()})`,
                        size: 13,
                        face: 'Inter, system-ui, sans-serif',
                        strokeWidth: 5,
                        strokeColor: `rgb(${getComputedStyle(document.documentElement).getPropertyValue('--hermes-bg').trim()})`,
                        vadjust: 22
                    },
                    shadow: false
                },
                edges: {
                    width: 3,
                    font: { size: 0 },
                    smooth: {
                        enabled: true,
                        type: 'curvedCW',
                        roundness: 0.15
                    },
                    arrows: {
                        to: { enabled: true, scaleFactor: 0.5, type: 'arrow' }
                    },
                    selectionWidth: 2
                },
                physics: {
                    enabled: true,
                    barnesHut: {
                        gravitationalConstant: -4000,
                        centralGravity: 0.2,
                        springLength: 200,
                        springConstant: 0.02,
                        damping: 0.15,
                        avoidOverlap: 0.5
                    },
                    stabilization: {
                        enabled: true,
                        iterations: 200,
                        updateInterval: 25
                    },
                    minVelocity: 0.75
                },
                interaction: {
                    hover: true,
                    tooltipDelay: 150,
                    hideEdgesOnDrag: false,
                    hideEdgesOnZoom: false,
                    navigationButtons: false,
                    keyboard: { enabled: true, speed: { x: 10, y: 10, zoom: 0.02 } },
                    zoomView: true
                }
            };

            if (knowledgeGraphNetwork) {
                knowledgeGraphNetwork.destroy();
            }

            knowledgeGraphNetwork = new vis.Network(container, { nodes, edges }, options);

            knowledgeGraphNetwork.on('click', function(params) {
                if (params.nodes.length > 0) {
                    const nodeId = params.nodes[0];
                    const node = nodes.get(nodeId);
                    console.log('Selected node:', node);
                }
            });
        }
    </script>
@endsection
