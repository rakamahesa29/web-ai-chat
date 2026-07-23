@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold text-hermes-text">AI Brain</h1>
            <p class="text-hermes-muted text-sm mt-1">Manage knowledge injected into the local AI model.</p>
        </div>
        <a href="{{ route('brains.create') }}" class="hermes-btn-primary">
            <i data-lucide="plus" class="w-4 h-4"></i>
            Add Knowledge
        </a>
    </div>

    <!-- Alert -->
    @if(session('success'))
        <div class="bg-hermes-success/10 text-hermes-success p-4 rounded-xl border border-hermes-success/30 text-sm font-medium flex items-center gap-3">
            <i data-lucide="check-circle" class="w-5 h-5"></i>
            {{ session('success') }}
        </div>
    @endif

    <!-- Table -->
    <div class="hermes-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-hermes-surface text-hermes-muted font-medium border-b border-hermes-border">
                    <tr>
                        <th class="px-6 py-4">Title</th>
                        <th class="px-6 py-4">Type</th>
                        <th class="px-6 py-4">Target Persona</th>
                        <th class="px-6 py-4">Date Added</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hermes-border">
                    @forelse($brains as $brain)
                        <tr class="hover:bg-hermes-hover transition">
                            <td class="px-6 py-4 font-medium text-hermes-text">
                                {{ $brain->title }}
                            </td>
                            <td class="px-6 py-4">
                                @if($brain->type === 'pdf')
                                    <span class="hermes-badge bg-red-500/10 text-red-400 border border-red-500/30">
                                        <i data-lucide="file-text" class="w-3 h-3"></i> PDF
                                    </span>
                                @else
                                    <span class="hermes-badge bg-blue-500/10 text-blue-400 border border-blue-500/30">
                                        <i data-lucide="type" class="w-3 h-3"></i> Text
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $tags = is_array($brain->tag) ? $brain->tag : [$brain->tag];
                                @endphp
                                <div class="flex flex-wrap gap-1">
                                    @foreach($tags as $t)
                                        <span class="hermes-badge bg-hermes-hover text-hermes-muted">
                                            {{ ucfirst($t) }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-6 py-4 text-hermes-muted text-xs">
                                {{ $brain->created_at->format('d M Y, H:i') }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('brains.edit', $brain) }}" class="hermes-btn-icon">
                                        <i data-lucide="edit-2" class="w-4 h-4"></i>
                                    </a>
                                    <form action="{{ route('brains.destroy', $brain) }}" method="POST" onsubmit="return confirm('Delete this knowledge?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="hermes-btn-icon text-hermes-muted hover:text-hermes-danger hover:bg-hermes-danger/10">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-16 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-16 h-16 bg-hermes-surface rounded-full flex items-center justify-center mb-4">
                                        <i data-lucide="brain" class="w-8 h-8 text-hermes-border"></i>
                                    </div>
                                    <p class="font-medium text-hermes-text mb-1">No knowledge added yet</p>
                                    <p class="text-sm text-hermes-muted">Add text or PDF documents to make your AI smarter.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($brains->hasPages())
            <div class="px-6 py-4 border-t border-hermes-border bg-hermes-surface">
                {{ $brains->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
