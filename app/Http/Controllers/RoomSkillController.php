<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomSkill;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RoomSkillController extends Controller
{
    /**
     * List all skills for a room.
     */
    public function index(Room $room): JsonResponse
    {
        $this->authorizeRoom($room);

        $skills = $room->skills()->latest()->get();

        return response()->json(['status' => 'success', 'skills' => $skills]);
    }

    /**
     * Store a new skill (file upload or manual input via WYSIWYG).
     */
    public function store(Request $request, Room $room): JsonResponse
    {
        $this->authorizeRoom($room);

        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required_without:file|nullable|string',
            'file' => 'required_without:content|nullable|file|max:2048',
        ]);

        if ($request->hasFile('file')) {
            $ext = strtolower($request->file('file')->getClientOriginalExtension());
            if (!in_array($ext, ['md', 'txt'])) {
                return response()->json(['error' => 'Only .md and .txt files are allowed.'], 422);
            }
        }

        $content = '';
        $sourceType = 'manual_input';
        $originalFilename = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $content = file_get_contents($file->getPathname());
            $sourceType = 'file_upload';
            $originalFilename = $file->getClientOriginalName();
        } else {
            $content = $request->input('content');
        }

        if (empty(trim($content))) {
            return response()->json(['error' => 'Skill content cannot be empty.'], 422);
        }

        $skill = $room->skills()->create([
            'title' => $request->input('title'),
            'content' => trim($content),
            'source_type' => $sourceType,
            'original_filename' => $originalFilename,
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Skill added successfully.',
            'skill' => $skill,
        ], 201);
    }

    /**
     * Toggle skill active state.
     */
    public function toggle(Room $room, RoomSkill $skill): JsonResponse
    {
        $this->authorizeRoom($room);
        $this->authorizeSkillBelongsToRoom($room, $skill);

        $skill->update(['is_active' => !$skill->is_active]);

        return response()->json([
            'status' => 'success',
            'is_active' => $skill->is_active,
            'message' => $skill->is_active ? 'Skill enabled.' : 'Skill disabled.',
        ]);
    }

    /**
     * Delete a skill.
     */
    public function destroy(Room $room, RoomSkill $skill): JsonResponse
    {
        $this->authorizeRoom($room);
        $this->authorizeSkillBelongsToRoom($room, $skill);

        $skill->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Skill removed.',
        ]);
    }

    private function authorizeRoom(Room $room): void
    {
        if ($room->user_id !== auth()->id()) {
            abort(403, 'Unauthorized access.');
        }
    }

    private function authorizeSkillBelongsToRoom(Room $room, RoomSkill $skill): void
    {
        if ($skill->room_id !== $room->id) {
            abort(404, 'Skill not found in this room.');
        }
    }
}
