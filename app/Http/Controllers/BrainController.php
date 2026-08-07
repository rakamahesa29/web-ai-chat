<?php

namespace App\Http\Controllers;

use App\Models\Brain;
use App\Services\RAG\RAGManager;
use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BrainController extends Controller
{
    public function index()
    {
        $brains = Brain::latest()->paginate(10);
        return view('brains.index', compact('brains'));
    }

    public function create()
    {
        return view('brains.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'tag' => 'required|array',
            'tag.*' => 'string',
            'custom_tags' => 'nullable|array',
            'custom_tags.*' => 'string|max:100',
            'type' => 'required|in:text,pdf',
            'content' => 'required_if:type,text',
            'file' => 'required_if:type,pdf|mimes:pdf|max:10240', // max 10MB
        ]);

        // Merge persona tags with custom tags
        $allTags = $request->tag ?? [];
        if ($request->has('custom_tags') && is_array($request->custom_tags)) {
            $customTags = array_filter($request->custom_tags, fn($t) => !empty(trim($t)));
            $allTags = array_unique(array_merge($allTags, $customTags));
        }

        $brain = new Brain();
        $brain->title = $request->title;
        $brain->tag = array_values($allTags);
        $brain->type = $request->type;

        if ($request->type === 'pdf' && $request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('brains', 'public');
            $brain->file_path = $path;

            // Parse PDF
            try {
                $parser = new Parser();
                $pdf = $parser->parseFile(storage_path('app/public/' . $path));
                $text = $pdf->getText();
                $brain->content = $text;
            } catch (\Exception $e) {
                return back()->with('error', 'Failed to parse PDF: ' . $e->getMessage())->withInput();
            }
        } else {
            $brain->content = $request->content;
        }

        $brain->is_indexed = false; // Mark for re-indexing
        $brain->save();
        
        // Auto-index if RAG is enabled
        $this->indexDocument($brain);

        return redirect()->route('brains.index')->with('success', 'Knowledge added to Brain successfully.');
    }

    public function edit(Brain $brain)
    {
        return view('brains.edit', compact('brain'));
    }

    public function update(Request $request, Brain $brain)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'tag' => 'required|array',
            'tag.*' => 'string',
            'custom_tags' => 'nullable|array',
            'custom_tags.*' => 'string|max:100',
            'content' => 'required|string',
        ]);

        // Merge persona tags with custom tags
        $allTags = $request->tag ?? [];
        if ($request->has('custom_tags') && is_array($request->custom_tags)) {
            $customTags = array_filter($request->custom_tags, fn($t) => !empty(trim($t)));
            $allTags = array_unique(array_merge($allTags, $customTags));
        }

        $brain->title = $request->title;
        $brain->tag = array_values($allTags);
        $brain->content = $request->content;
        $brain->is_indexed = false; // Mark for re-indexing
        $brain->save();
        
        // Auto-index if RAG is enabled
        $this->indexDocument($brain);

        return redirect()->route('brains.index')->with('success', 'Knowledge updated successfully.');
    }
    
    /**
     * Index a document for RAG semantic search.
     */
    private function indexDocument(Brain $brain): void
    {
        if (!config('services.rag.enabled', true)) {
            return;
        }
        
        try {
            $rag = new RAGManager();
            
            if (!$rag->isReady()) {
                Log::warning("RAG: Embedding model not available for auto-indexing");
                return;
            }
            
            $rag->indexDocument($brain);
            Log::info("RAG: Auto-indexed document {$brain->id}: {$brain->title}");
            
        } catch (\Exception $e) {
            Log::error("RAG: Failed to auto-index document {$brain->id}: " . $e->getMessage());
        }
    }

    public function destroy(Brain $brain)
    {
        if ($brain->file_path) {
            Storage::disk('public')->delete($brain->file_path);
        }
        $brain->delete();

        return redirect()->route('brains.index')->with('success', 'Knowledge removed from Brain.');
    }
}
