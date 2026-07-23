<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login and return a Sanctum token.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Delete old tokens for this device (optional — comment out to allow multi-device)
        // $user->tokens()->delete();

        $token = $user->createToken('omoikane-mobile')->plainTextToken;

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'user'  => $this->formatUser($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Register a new user and return a Sanctum token.
     */
    public function register(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => 'user',
        ]);

        $token = $user->createToken('omoikane-mobile')->plainTextToken;

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'user'  => $this->formatUser($user),
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * Get the authenticated user.
     */
    public function user(Request $request)
    {
        return response()->json([
            'status' => 'ok',
            'data'   => $this->formatUser($request->user()),
        ]);
    }

    /**
     * Logout (revoke current token).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => 'ok',
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Get user profile.
     */
    public function profile(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'name'         => $user->name,
                'email'        => $user->email,
                'phone_number' => $user->phone_number,
                'role'         => $user->role,
                'created_at'   => $user->created_at,
            ],
        ]);
    }

    /**
     * Update user profile.
     */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'name'         => 'sometimes|string|max:255',
            'email'        => 'sometimes|email|unique:users,email,' . $request->user()->id,
            'phone_number' => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        $user->update($request->only(['name', 'email', 'phone_number']));

        return response()->json([
            'status' => 'ok',
            'data'   => $this->formatUser($user),
        ]);
    }

    // ── helpers ──

    private function formatUser(User $user): array
    {
        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'role'       => $user->role,
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
