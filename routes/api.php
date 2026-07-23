<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatApiController;
use App\Http\Controllers\Api\BrainApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\SettingsApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Omoikane AI Chat Mobile App
|--------------------------------------------------------------------------
*/

// ── Public Auth ──
Route::post('/auth/login',    [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

// ── Authenticated Routes ──
Route::middleware('auth:sanctum')->group(function () {

    // User
    Route::get('/auth/user',    [AuthController::class, 'user']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Dashboard
    Route::get('/dashboard/stats',    [DashboardApiController::class, 'stats']);
    Route::get('/dashboard/activity', [DashboardApiController::class, 'activity']);
    Route::post('/dashboard/provider-mode', [DashboardApiController::class, 'updateProviderMode']);
    Route::get('/dashboard/graph-data',    [DashboardApiController::class, 'graphData']);
    Route::get('/dashboard/graph-rooms',   [DashboardApiController::class, 'graphRooms']);
    Route::get('/dashboard/graph-stats',   [DashboardApiController::class, 'graphStats']);

    // Chat Rooms
    Route::get('/chat/rooms',              [ChatApiController::class, 'index']);
    Route::post('/chat/rooms',             [ChatApiController::class, 'store']);
    Route::get('/chat/rooms/{room}',       [ChatApiController::class, 'show']);
    Route::put('/chat/rooms/{room}',       [ChatApiController::class, 'update']);
    Route::delete('/chat/rooms/{room}',    [ChatApiController::class, 'destroy']);
    Route::get('/chat/rooms/{room}/messages', [ChatApiController::class, 'messages']);

    // Chat Send (SSE streaming)
    Route::post('/chat/rooms/{room}/send', [ChatApiController::class, 'send']);

    // Agent continue (client-side tool execution results)
    Route::post('/chat/rooms/{room}/agent-continue', [ChatApiController::class, 'agentContinue']);

    // Messages
    Route::post('/chat/messages/{message}/rate',  [ChatApiController::class, 'rateMessage']);
    Route::delete('/chat/messages/{message}',     [ChatApiController::class, 'destroyMessage']);
    Route::post('/chat/upload-file',              [ChatApiController::class, 'uploadFile']);

    // Brain (Knowledge Base)
    Route::get('/brains',             [BrainApiController::class, 'index']);
    Route::post('/brains',            [BrainApiController::class, 'store']);
    Route::get('/brains/{brain}',     [BrainApiController::class, 'show']);
    Route::put('/brains/{brain}',     [BrainApiController::class, 'update']);
    Route::delete('/brains/{brain}',  [BrainApiController::class, 'destroy']);

    // Settings
    Route::get('/settings',           [SettingsApiController::class, 'index']);
    Route::put('/settings',           [SettingsApiController::class, 'update']);
    
    // Admin
    Route::post('/admin/clear-locks', function () {
        \Illuminate\Support\Facades\Cache::flush();
        return response()->json(['status' => 'ok', 'message' => 'All locks cleared']);
    });

    // User Profile
    Route::get('/profile',            [AuthController::class, 'profile']);
    Route::put('/profile',            [AuthController::class, 'updateProfile']);
});
