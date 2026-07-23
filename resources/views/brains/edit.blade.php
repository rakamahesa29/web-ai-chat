@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center gap-4">
        <a href="{{ route('brains.index') }}" class="hermes-btn-icon border border-hermes-border">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-hermes-text">Edit Knowledge</h1>
            <p class="text-hermes-muted text-sm">Update information in the AI Brain.</p>
        </div>
    </div>

    <!-- Errors -->
    @if($errors->any())
        <div class="bg-hermes-danger/10 text-hermes-danger p-4 rounded-xl border border-hermes-danger/30 text-sm">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Form -->
    <div class="hermes-card p-6 md:p-8">
        <form action="{{ route('brains.update', $brain) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <div>
                <label class="block text-xs font-semibold text-hermes-muted uppercase tracking-wider mb-2">Knowledge Title</label>
                <input type="text" name="title" value="{{ old('title', $brain->title) }}" required class="hermes-input">
            </div>

            <div>
                <label class="block text-xs font-semibold text-hermes-muted uppercase tracking-wider mb-3">Target Persona (Tags)</label>
                @php
                    $selectedTags = is_array($brain->tag) ? $brain->tag : [$brain->tag];
                    $personaTags = ['general', 'architect', 'bestie', 'sage', 'executive', 'education'];
                    $customTags = array_filter($selectedTags, fn($t) => !in_array($t, $personaTags));
                @endphp
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex items-center gap-3 p-3 hermes-card cursor-pointer hover:bg-hermes-hover transition">
                        <input type="checkbox" name="tag[]" value="general" {{ in_array('general', $selectedTags) ? 'checked' : '' }} class="w-4 h-4 rounded bg-hermes-surface border-hermes-border text-hermes-accent focus:ring-hermes-accent focus:ring-offset-hermes-bg">
                        <span class="text-sm font-medium text-hermes-text">General (All Personas)</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 hermes-card cursor-pointer hover:bg-hermes-hover transition">
                        <input type="checkbox" name="tag[]" value="architect" {{ in_array('architect', $selectedTags) ? 'checked' : '' }} class="w-4 h-4 rounded bg-hermes-surface border-hermes-border text-hermes-accent focus:ring-hermes-accent focus:ring-offset-hermes-bg">
                        <span class="text-sm font-medium text-hermes-text">The Architect (Tech & Code)</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 hermes-card cursor-pointer hover:bg-hermes-hover transition">
                        <input type="checkbox" name="tag[]" value="bestie" {{ in_array('bestie', $selectedTags) ? 'checked' : '' }} class="w-4 h-4 rounded bg-hermes-surface border-hermes-border text-hermes-accent focus:ring-hermes-accent focus:ring-offset-hermes-bg">
                        <span class="text-sm font-medium text-hermes-text">The Bestie (Casual)</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 hermes-card cursor-pointer hover:bg-hermes-hover transition">
                        <input type="checkbox" name="tag[]" value="sage" {{ in_array('sage', $selectedTags) ? 'checked' : '' }} class="w-4 h-4 rounded bg-hermes-surface border-hermes-border text-hermes-accent focus:ring-hermes-accent focus:ring-offset-hermes-bg">
                        <span class="text-sm font-medium text-hermes-text">The Sage (Empathetic)</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 hermes-card cursor-pointer hover:bg-hermes-hover transition">
                        <input type="checkbox" name="tag[]" value="executive" {{ in_array('executive', $selectedTags) ? 'checked' : '' }} class="w-4 h-4 rounded bg-hermes-surface border-hermes-border text-hermes-accent focus:ring-hermes-accent focus:ring-offset-hermes-bg">
                        <span class="text-sm font-medium text-hermes-text">The Executive (Business)</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 hermes-card cursor-pointer hover:bg-hermes-hover transition">
                        <input type="checkbox" name="tag[]" value="education" {{ in_array('education', $selectedTags) ? 'checked' : '' }} class="w-4 h-4 rounded bg-hermes-surface border-hermes-border text-hermes-accent focus:ring-hermes-accent focus:ring-offset-hermes-bg">
                        <span class="text-sm font-medium text-hermes-text">The Educator (Academic)</span>
                    </label>
                </div>
                <p class="text-xs text-hermes-muted mt-2">Select one or more personas.</p>
            </div>

            <div x-data="customTagsInput(@js(array_values($customTags)))" class="relative">
                <label class="block text-xs font-semibold text-hermes-muted uppercase tracking-wider mb-2">Custom Tags (Optional)</label>
                <div class="hermes-input p-3">
                    <div class="flex flex-wrap gap-2 mb-2" x-show="tags.length > 0">
                        <template x-for="(tag, index) in tags" :key="index">
                            <span class="hermes-badge bg-hermes-accent/10 text-hermes-accent">
                                <span x-text="tag"></span>
                                <button type="button" @click="removeTag(index)" class="hover:text-hermes-danger transition ml-1">
                                    <i data-lucide="x" class="w-3 h-3"></i>
                                </button>
                                <input type="hidden" name="custom_tags[]" :value="tag">
                            </span>
                        </template>
                    </div>
                    <input type="text" x-model="newTag" 
                        @keydown.enter.prevent="addTag()" 
                        @keydown.comma.prevent="addTag()" 
                        @keydown.tab.prevent="addTag()" 
                        @paste="handlePaste($event)"
                        @blur="addTag()"
                        placeholder="Type tags separated by commas..."
                        class="w-full bg-transparent outline-none text-sm text-hermes-text placeholder-hermes-muted">
                </div>
                <p class="text-xs text-hermes-muted mt-2">Add custom tags for better RAG retrieval.</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-hermes-muted uppercase tracking-wider mb-2">Content (Knowledge)</label>
                <textarea name="content" id="content-editor" rows="15" class="hermes-input font-mono text-sm">{{ old('content', $brain->content) }}</textarea>
            </div>

            <button type="submit" class="w-full hermes-btn-primary py-4 justify-center text-base">
                <i data-lucide="save" class="w-5 h-5"></i>
                Update Knowledge
            </button>
        </form>
    </div>
</div>

<script>
    function customTagsInput(initialTags = []) {
        return {
            tags: initialTags,
            newTag: '',
            addTag() {
                const parts = this.newTag.split(',');
                parts.forEach(part => {
                    const tag = part.trim().toLowerCase()
                        .replace(/[^a-z0-9_-]/g, '_')
                        .replace(/_+/g, '_')
                        .replace(/^_|_$/g, '');
                    if (tag && tag.length >= 2 && !this.tags.includes(tag)) {
                        this.tags.push(tag);
                    }
                });
                this.newTag = '';
                this.$nextTick(() => lucide.createIcons());
            },
            handlePaste(event) {
                setTimeout(() => this.addTag(), 10);
            },
            removeTag(index) {
                this.tags.splice(index, 1);
            }
        }
    }
</script>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/super-build/ckeditor.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        CKEDITOR.ClassicEditor
            .create(document.querySelector('#content-editor'), {
                toolbar: {
                    items: [
                        'heading', '|',
                        'bold', 'italic', 'underline', 'code', '|',
                        'bulletedList', 'numberedList', '|',
                        'outdent', 'indent', '|',
                        'link', 'blockQuote', 'codeBlock', '|',
                        'undo', 'redo'
                    ],
                    shouldNotGroupWhenFull: true
                },
                codeBlock: {
                    languages: [
                        { language: 'plaintext', label: 'Plain text' },
                        { language: 'php', label: 'PHP' },
                        { language: 'javascript', label: 'JavaScript' },
                        { language: 'html', label: 'HTML' },
                        { language: 'css', label: 'CSS' },
                        { language: 'bash', label: 'Bash' },
                        { language: 'json', label: 'JSON' },
                        { language: 'sql', label: 'SQL' }
                    ]
                },
                removePlugins: [
                    'CKBox', 'CKFinder', 'EasyImage', 'RealTimeCollaborativeComments', 'RealTimeCollaborativeTrackChanges', 'RealTimeCollaborativeRevisionHistory', 'PresenceList', 'Comments', 'TrackChanges', 'TrackChangesData', 'RevisionHistory', 'Pagination', 'WProofreader', 'MathType', 'SlashCommand', 'Template', 'DocumentOutline', 'FormatPainter', 'TableOfContents', 'PasteFromOfficeEnhanced', 'CaseChange'
                ]
            })
            .catch(error => console.error(error));
    });
</script>

<style>
    .ck-editor__editable_inline {
        min-height: 250px;
        background: rgb(var(--hermes-surface)) !important;
        color: rgb(var(--hermes-text)) !important;
        border-color: rgb(var(--hermes-border)) !important;
        border-bottom-left-radius: 0.75rem !important;
        border-bottom-right-radius: 0.75rem !important;
    }
    .ck-toolbar {
        background: rgb(var(--hermes-card)) !important;
        border-color: rgb(var(--hermes-border)) !important;
        border-top-left-radius: 0.75rem !important;
        border-top-right-radius: 0.75rem !important;
    }
    .ck-toolbar .ck-button { color: rgb(var(--hermes-muted)) !important; }
    .ck-toolbar .ck-button:hover { background: rgb(var(--hermes-hover)) !important; color: rgb(var(--hermes-text)) !important; }
    .ck-toolbar .ck-button.ck-on { background: rgb(var(--hermes-accent)) !important; color: white !important; }
    .ck-dropdown__panel { background: rgb(var(--hermes-card)) !important; border-color: rgb(var(--hermes-border)) !important; }
    .ck-list__item { color: rgb(var(--hermes-text)) !important; }
    .ck-list__item:hover { background: rgb(var(--hermes-hover)) !important; }
    .ck-content ul { list-style-type: disc !important; margin-left: 1.5rem !important; }
    .ck-content ol { list-style-type: decimal !important; margin-left: 1.5rem !important; }
    .ck-content pre { background: rgb(var(--hermes-bg)) !important; color: rgb(var(--hermes-text)) !important; padding: 1rem !important; border-radius: 0.5rem !important; }
    .ck-content code { background: rgb(var(--hermes-hover)) !important; color: #d946ef !important; padding: 0.125rem 0.25rem !important; border-radius: 0.25rem !important; }
    .dark .ck-content code { color: #f472b6 !important; }
    .ck-content pre code { background: transparent !important; color: inherit !important; }
    .ck-content blockquote { border-left: 3px solid rgb(var(--hermes-accent)) !important; background: rgb(var(--hermes-accent) / 0.1) !important; }
    .ck-content a { color: rgb(var(--hermes-accent)) !important; }
</style>
@endsection
