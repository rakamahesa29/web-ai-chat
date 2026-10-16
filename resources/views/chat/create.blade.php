@extends('layouts.app')
@section('content')
    <div class="w-full max-w-xl mx-auto">
        <div class="hermes-card p-8">
            <div class="mb-8 text-center">
                <h1 class="text-2xl font-bold text-hermes-text mb-2">Prepare your Session</h1>
                <p class="text-hermes-muted text-sm">Set up the initial parameters for this conversation.</p>
            </div>

            <form action="{{ route('chat.store') }}" method="POST" class="space-y-6">
                @csrf

                <div>
                    <label class="block text-[10px] font-bold text-hermes-muted uppercase tracking-widest mb-2 ml-1">Chat
                        Title</label>
                    <input type="text" name="title" value="{{ old('title') }}"
                        placeholder="e.g. Recipe for Padang Food"
                        class="w-full p-4 bg-hermes-surface border border-hermes-border rounded-xl focus:bg-hermes-hover focus:ring-2 focus:ring-hermes-accent focus:border-hermes-accent outline-none transition text-sm text-hermes-text placeholder-hermes-muted">
                </div>

                <div>
                    <label
                        class="block text-[10px] font-bold text-hermes-muted uppercase tracking-widest mb-2 ml-1">Category</label>
                    <div class="relative">
                        <select name="category"
                            class="w-full p-4 pr-12 bg-hermes-surface border border-hermes-border rounded-xl focus:bg-hermes-hover focus:ring-2 focus:ring-hermes-accent focus:border-hermes-accent outline-none transition text-sm text-hermes-text cursor-pointer appearance-none">
                            <option value="General" selected>General</option>
                            <option value="Software Dev">Software Dev</option>
                            <option value="Copywriting">Copywriting</option>
                            <option value="Education">Education</option>
                            <option value="Business">Business</option>
                            <option value="Design & UI/UX">Design & UI/UX</option>
                            <option value="Daily Life">Daily Life</option>
                        </select>
                        <div class="absolute inset-y-0 right-4 flex items-center pointer-events-none text-hermes-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7">
                                </path>
                            </svg>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-hermes-muted uppercase tracking-widest mb-2 ml-1">AI Persona
                        (Tone)</label>
                    <div class="relative">
                        <select name="persona"
                            class="w-full p-4 pr-12 bg-hermes-surface border border-hermes-border rounded-xl focus:bg-hermes-hover focus:ring-2 focus:ring-hermes-accent focus:border-hermes-accent outline-none transition text-sm text-hermes-text cursor-pointer appearance-none">
                            <option value="general" selected>General Assistant (Balanced)</option>
                            <option value="architect">The Architect (Tech & Code Focused)</option>
                            <option value="bestie">The Bestie (Casual & Fun Lifestyle)</option>
                            <option value="sage">The Sage (Empathetic & Meaningful Talk)</option>
                            <option value="executive">The Executive (Formal & Business)</option>
                            <option value="education">The Educator (Academic & Structured)</option>
                            <option value="swift-developer">Swift Architect (SwiftUI & Laravel)</option>
                            <option value="lovable-engineer">Lovable UI/UX Engineer (Frontend & Design)</option>
                        </select>
                        <div class="absolute inset-y-0 right-4 flex items-center pointer-events-none text-hermes-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7">
                                </path>
                            </svg>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-hermes-muted uppercase tracking-widest mb-2 ml-1">Select AI
                        Model</label>
                    <div class="relative">
                        <select name="model_name"
                            class="w-full p-4 pr-12 bg-hermes-surface border border-hermes-border rounded-xl focus:bg-hermes-hover focus:ring-2 focus:ring-hermes-accent focus:border-hermes-accent outline-none transition text-sm text-hermes-text cursor-pointer appearance-none">
                            <optgroup label="Standard Models">
                                <option value="ollama" selected>Ollama (Ornith)</option>
                                <option value="ollama_cloud">Ollama (Gemma4 31B Cloud)</option>
                                <option value="deepseek">DeepSeek API</option>
                            </optgroup>
                            <optgroup label="Gemini 3.8 Flash">
                                <option value="gemini-3.8-flash-low">Gemini 3.8 Flash (Low)</option>
                                <option value="gemini-3.8-flash-medium">Gemini 3.8 Flash (Medium)</option>
                                <option value="gemini-3.8-flash-high">Gemini 3.8 Flash (High)</option>
                            </optgroup>
                            <optgroup label="Claude Sonnet 5.5">
                                <option value="claude-sonnet-5-5-low">Claude Sonnet 5.5 (Low)</option>
                                <option value="claude-sonnet-5-5-medium">Claude Sonnet 5.5 (Medium)</option>
                                <option value="claude-sonnet-5-5-high">Claude Sonnet 5.5 (High)</option>
                            </optgroup>
                            <optgroup label="Claude Opus 5.5">
                                <option value="claude-opus-5-5-low">Claude Opus 5.5 (Low)</option>
                                <option value="claude-opus-5-5-medium">Claude Opus 5.5 (Medium)</option>
                                <option value="claude-opus-5-5-high">Claude Opus 5.5 (High)</option>
                            </optgroup>
                            <optgroup label="GPT-OSS">
                                <option value="gpt-oss-120b-medium">GPT-OSS 120B (Medium)</option>
                            </optgroup>
                        </select>
                        <div class="absolute inset-y-0 right-4 flex items-center pointer-events-none text-hermes-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7">
                                </path>
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="p-4 rounded-xl hermes-card bg-hermes-accent/5 border-hermes-accent/20 flex items-start gap-3">
                    <div class="p-1.5 bg-hermes-accent/10 rounded-lg shrink-0">
                        <svg class="w-4 h-4 text-hermes-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <p class="text-xs text-hermes-muted leading-relaxed">
                        Selecting a model determines speed and accuracy. "Local" is private & offline; "Cloud/Gemini" are
                        faster for complex tasks.
                    </p>
                </div>

                <button type="submit"
                    class="w-full bg-hermes-accent text-white py-4 rounded-xl font-bold hover:bg-indigo-600 transition shadow-lg shadow-hermes-accent/20 flex items-center justify-center gap-2">
                    <span>Start Conversation</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
@endsection