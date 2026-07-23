@extends('layouts.app')
@section('content')
    {{-- Alert Success --}}
    @if (session('success'))
        <div class="mb-6">
            <div class="bg-hermes-success/10 border border-hermes-success/30 text-hermes-success px-4 py-3 rounded-xl flex items-center gap-3" role="alert">
                <i data-lucide="check-circle" class="w-5 h-5"></i>
                <span class="text-sm font-medium">{{ session('success') }}</span>
                <button type="button" onclick="this.parentElement.remove()" class="ml-auto hover:opacity-70">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    @endif

    {{-- Alert Error --}}
    @if (session('error'))
        <div class="mb-6">
            <div class="bg-hermes-danger/10 border border-hermes-danger/30 text-hermes-danger px-4 py-3 rounded-xl flex items-center gap-3" role="alert">
                <i data-lucide="alert-circle" class="w-5 h-5"></i>
                <span class="text-sm font-medium">{{ session('error') }}</span>
                <button type="button" onclick="this.parentElement.remove()" class="ml-auto hover:opacity-70">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    @endif

    <div class="w-full mx-auto">
        <div class="flex flex-col xl:flex-row gap-8">

            <!-- Sidebar -->
            <aside class="w-full xl:w-72 xl:order-1 order-2 flex flex-col space-y-6 shrink-0">
                <!-- New Chat Button -->
                <div class="hermes-card p-4">
                    <a href="{{ route('chat.create') }}"
                        class="flex items-center justify-center gap-2 w-full py-3 px-4 bg-hermes-accent text-white rounded-xl hover:bg-indigo-600 transition-all shadow-lg shadow-hermes-accent/20 group">
                        <i data-lucide="plus" class="w-5 h-5 group-hover:rotate-90 transition-transform duration-300"></i>
                        <span class="font-semibold text-sm">New Conversation</span>
                    </a>
                </div>

                <!-- Collections -->
                <div class="hermes-card p-4">
                    <p class="text-xs font-semibold text-hermes-muted uppercase tracking-wider mb-3 px-1">Collections</p>
                    <div class="space-y-1">
                        <a href="#" class="hermes-sidebar-item">
                            <i data-lucide="bookmark" class="w-4 h-4 text-hermes-accent"></i>
                            <span>My Library</span>
                        </a>
                        <a href="#" class="hermes-sidebar-item">
                            <i data-lucide="file-edit" class="w-4 h-4 text-purple-400"></i>
                            <span>Drafts</span>
                        </a>
                    </div>
                </div>

                <!-- History -->
                <div class="hermes-card p-4 flex-1">
                    <p class="text-xs font-semibold text-hermes-muted uppercase tracking-wider mb-3 px-1">History</p>
                    <div class="space-y-1 max-h-80 overflow-y-auto">
                        @if (isset($rooms) && count($rooms) > 0)
                            @foreach ($rooms as $r)
                                <div class="group relative">
                                    <a href="{{ route('chat.show', $r->id) }}" class="hermes-sidebar-item">
                                        <i data-lucide="message-circle" class="w-4 h-4 shrink-0"></i>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm truncate">{{ $r->title }}</div>
                                            <div class="text-[10px] text-hermes-muted mt-0.5">{{ \Carbon\Carbon::parse($r->created_at)->diffForHumans() }}</div>
                                        </div>
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
                            <div class="text-center py-6">
                                <i data-lucide="message-square-dashed" class="w-8 h-8 text-hermes-border mx-auto mb-2"></i>
                                <p class="text-xs text-hermes-muted">No history yet</p>
                            </div>
                        @endif
                    </div>
                </div>
            </aside>

            <!-- Main Content -->
            <main class="flex-1 pb-8 xl:order-2 order-1">
                <!-- Header -->
                <div class="mb-10">
                    <h1 class="text-3xl md:text-4xl font-bold text-hermes-text tracking-tight">
                        Welcome Back<span class="text-hermes-accent">.</span>
                    </h1>
                    <p class="text-hermes-muted mt-3 text-lg">
                        Start a new conversation or continue from where you left off.
                    </p>
                </div>

                <!-- Quick Start Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

                    <form action="{{ route('chat.store') }}" method="POST">
                        @csrf
                        <input type="hidden" name="title" value="Coding & Development">
                        <input type="hidden" name="model_name" value="ollama">
                        <button type="submit" class="w-full text-left p-5 hermes-card hover:border-hermes-accent/50 hover:bg-hermes-hover transition-all group">
                            <div class="w-10 h-10 bg-blue-500/10 rounded-xl flex items-center justify-center mb-4 group-hover:bg-blue-500/20 transition-colors">
                                <i data-lucide="code-2" class="w-5 h-5 text-blue-400"></i>
                            </div>
                            <h3 class="font-semibold text-hermes-text mb-1">Coding & Dev</h3>
                            <p class="text-sm text-hermes-muted leading-relaxed">Debug, code review, and programming help.</p>
                        </button>
                    </form>

                    <form action="{{ route('chat.store') }}" method="POST">
                        @csrf
                        <input type="hidden" name="title" value="Creative Writing">
                        <input type="hidden" name="model_name" value="ollama">
                        <button type="submit" class="w-full text-left p-5 hermes-card hover:border-purple-500/50 hover:bg-hermes-hover transition-all group">
                            <div class="w-10 h-10 bg-purple-500/10 rounded-xl flex items-center justify-center mb-4 group-hover:bg-purple-500/20 transition-colors">
                                <i data-lucide="pen-tool" class="w-5 h-5 text-purple-400"></i>
                            </div>
                            <h3 class="font-semibold text-hermes-text mb-1">Creative Writing</h3>
                            <p class="text-sm text-hermes-muted leading-relaxed">Draft blogs, scripts, and story ideas.</p>
                        </button>
                    </form>

                    <form action="{{ route('chat.store') }}" method="POST">
                        @csrf
                        <input type="hidden" name="title" value="Daily Life & Advice">
                        <input type="hidden" name="model_name" value="ollama">
                        <button type="submit" class="w-full text-left p-5 hermes-card hover:border-emerald-500/50 hover:bg-hermes-hover transition-all group">
                            <div class="w-10 h-10 bg-emerald-500/10 rounded-xl flex items-center justify-center mb-4 group-hover:bg-emerald-500/20 transition-colors">
                                <i data-lucide="sun" class="w-5 h-5 text-emerald-400"></i>
                            </div>
                            <h3 class="font-semibold text-hermes-text mb-1">Daily Life</h3>
                            <p class="text-sm text-hermes-muted leading-relaxed">Recipes, planning, and general advice.</p>
                        </button>
                    </form>

                    <!-- Custom Chat Card -->
                    <div class="md:col-span-2 lg:col-span-3 mt-2">
                        <a href="{{ route('chat.create') }}"
                           class="flex items-center justify-center gap-3 p-6 border-2 border-dashed border-hermes-border hover:border-hermes-accent/50 rounded-xl bg-hermes-surface/50 hover:bg-hermes-hover transition-all group">
                            <div class="p-2 bg-hermes-accent/10 rounded-full group-hover:bg-hermes-accent/20 transition-colors">
                                <i data-lucide="plus" class="w-5 h-5 text-hermes-accent"></i>
                            </div>
                            <span class="font-semibold text-hermes-muted group-hover:text-hermes-text transition-colors">
                                Create Custom Chat & Select AI Model
                            </span>
                        </a>
                    </div>
                </div>

                <!-- Info Section -->
                <div class="mt-10 p-5 hermes-card bg-hermes-accent/5 border-hermes-accent/20 flex items-start gap-4">
                    <div class="p-2 bg-hermes-accent/10 rounded-xl shrink-0">
                        <i data-lucide="info" class="w-5 h-5 text-hermes-accent"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold text-hermes-text mb-1">Quick Tip</h4>
                        <p class="text-sm text-hermes-muted leading-relaxed">
                            You can switch AI models (Ollama, DeepSeek) directly inside any active chat session without losing your conversation context.
                        </p>
                    </div>
                </div>
            </main>
        </div>
    </div>
@endsection
