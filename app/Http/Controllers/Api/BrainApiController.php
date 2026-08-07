<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brain;
use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Storage;

class BrainApiController extends Controller
{
    public function index()
    {
        $brains = Brain::latest()->get()->map(fn($b) => $this->formatBrain($b));

        return response()->json(['status' => 'ok', 'data' => $brains]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title'       => 'required|string|max:255',
            'tag'         => 'required|array',
            'tag.*'       => 'string',
            'custom_tags' => 'nullable|array',
            'custom_tags.*' => 'string|max:100',
            'type'        => 'required|in:text,pdf',
            'content'     => 'required_if:type,text|string',
            'file'        => 'required_if:type,pdf|file|mimes:pdf|max:10240',
        ]);

        // Merge tags
        $allTags = $request->tag ?? [];
        if ($request->has('custom_tags')) {
            $customTags = array_filter($request->custom_tags, fn($t) => !empty(trim($t)));
            $allTags = array_unique(array_merge($allTags, $customTags));
        }

        $brain = new Brain();
        $brain->title = $request->title;
        $brain->tag   = array_values($allTags);
        $brain->type  = $request->type;

        if ($request->type === 'pdf' && $request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('brains', 'public');
            $brain->file_path = $path;

            try {
                $parser = new Parser();
                $pdf    = $parser->parseFile(storage_path('app/public/' . $path));
                $brain->content = $pdf->getText();
            } catch (\Exception $e) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Failed to parse PDF: ' . $e->getMessage(),
                ], 422);
            }
        } else {
            $brain->content = $request->content;
        }

        $brain->is_indexed = false;
        $brain->save();

        return response()->json([
            'status'  => 'ok',
            'message' => 'Knowledge added to Brain.',
            'data'    => $this->formatBrain($brain),
        ], 201);
    }

    public function show(Brain $brain)
    {
        return response()->json([
            'status' => 'ok',
            'data'   => $this->formatBrain($brain),
        ]);
    }

    public function update(Request $request, Brain $brain)
    {
        $request->validate([
            'title'       => 'required|string|max:255',
            'tag'         => 'required|array',
            'tag.*'       => 'string',
            'custom_tags' => 'nullable|array',
            'custom_tags.*' => 'string|max:100',
            'content'     => 'required|string',
        ]);

        $allTags = $request->tag ?? [];
        if ($request->has('custom_tags')) {
            $customTags = array_filter($request->custom_tags, fn($t) => !empty(trim($t)));
            $allTags = array_unique(array_merge($allTags, $customTags));
        }

        $brain->title      = $request->title;
        $brain->tag        = array_values($allTags);
        $brain->content    = $request->content;
        $brain->is_indexed = false;
        $brain->save();

        return response()->json([
            'status'  => 'ok',
            'message' => 'Knowledge updated.',
            'data'    => $this->formatBrain($brain),
        ]);
    }

    public function destroy(Brain $brain)
    {
        if ($brain->file_path) {
            Storage::disk('public')->delete($brain->file_path);
        }
        $brain->delete();

        return response()->json(['status' => 'ok', 'message' => 'Knowledge removed.']);
    }

    private function formatBrain(Brain $brain): array
    {
        return [
            'id'         => $brain->id,
            'title'      => $brain->title,
            'type'       => $brain->type,
            'tag'        => $brain->tag,
            'content'    => $brain->content,
            'file_path'  => $brain->file_path,
            'is_indexed' => $brain->is_indexed,
            'created_at' => $brain->created_at?->toISOString(),
            'updated_at' => $brain->updated_at?->toISOString(),
        ];
    }
}
