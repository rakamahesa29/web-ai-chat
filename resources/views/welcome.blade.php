@extends('layouts.app')

@section('content')
<div class="py-16 md:py-24">
    <!-- Hero Section -->
    <div class="text-center max-w-4xl mx-auto">
        <h1 class="text-4xl md:text-5xl font-bold mb-6 text-gradient">
            Experience the Future of Local AI.
        </h1>
        <p class="text-lg text-hermes-muted mb-10 leading-relaxed max-w-2xl mx-auto">
            Interact with powerful models locally using Ollama, or connect directly to global giants like DeepSeek. 
            One interface, infinite possibilities.
        </p>
        
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ route('login') }}" class="hermes-btn-primary px-8 py-3 text-base">
                <i data-lucide="zap" class="w-5 h-5"></i>
                Get Started Now
            </a>
            <a href="#features" class="hermes-btn-ghost px-8 py-3 text-base border border-hermes-border">
                <i data-lucide="info" class="w-5 h-5"></i>
                Learn More
            </a>
        </div>
    </div>

    <!-- Features Grid -->
    <div id="features" class="mt-24 md:mt-32 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="hermes-card p-6 hover:border-blue-500/50 transition-all group">
            <div class="w-12 h-12 bg-blue-500/10 text-blue-400 rounded-xl flex items-center justify-center mb-5 group-hover:bg-blue-500/20 transition-colors">
                <i data-lucide="rocket" class="w-6 h-6"></i>
            </div>
            <h3 class="text-lg font-semibold text-hermes-text mb-2">Fast Performance</h3>
            <p class="text-hermes-muted text-sm leading-relaxed">Optimized to run smoothly on local machines with minimal latency.</p>
        </div>

        <div class="hermes-card p-6 hover:border-purple-500/50 transition-all group">
            <div class="w-12 h-12 bg-purple-500/10 text-purple-400 rounded-xl flex items-center justify-center mb-5 group-hover:bg-purple-500/20 transition-colors">
                <i data-lucide="shield-check" class="w-6 h-6"></i>
            </div>
            <h3 class="text-lg font-semibold text-hermes-text mb-2">Data Privacy</h3>
            <p class="text-hermes-muted text-sm leading-relaxed">Your local data stays on your machine when using Ollama integration.</p>
        </div>

        <div class="hermes-card p-6 hover:border-emerald-500/50 transition-all group">
            <div class="w-12 h-12 bg-emerald-500/10 text-emerald-400 rounded-xl flex items-center justify-center mb-5 group-hover:bg-emerald-500/20 transition-colors">
                <i data-lucide="cloud" class="w-6 h-6"></i>
            </div>
            <h3 class="text-lg font-semibold text-hermes-text mb-2">Cloud Ready</h3>
            <p class="text-hermes-muted text-sm leading-relaxed">Seamless switching between local models and premium Cloud APIs.</p>
        </div>
    </div>

    <!-- Stats Section -->
    <div class="mt-24 grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="hermes-card p-6 text-center">
            <div class="text-3xl font-bold text-hermes-accent mb-1">100%</div>
            <div class="text-sm text-hermes-muted">Private & Secure</div>
        </div>
        <div class="hermes-card p-6 text-center">
            <div class="text-3xl font-bold text-hermes-accent mb-1">Fast</div>
            <div class="text-sm text-hermes-muted">Local Processing</div>
        </div>
        <div class="hermes-card p-6 text-center">
            <div class="text-3xl font-bold text-hermes-accent mb-1">Multi</div>
            <div class="text-sm text-hermes-muted">AI Models</div>
        </div>
        <div class="hermes-card p-6 text-center">
            <div class="text-3xl font-bold text-hermes-accent mb-1">Free</div>
            <div class="text-sm text-hermes-muted">Open Source</div>
        </div>
    </div>
</div>
@endsection
