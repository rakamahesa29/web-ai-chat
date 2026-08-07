<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\Google\GoogleAuthService;
use App\Services\Google\GoogleDocsService;
use App\Services\Google\GoogleSheetsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleController extends Controller
{
    protected GoogleAuthService $authService;
    protected GoogleDocsService $docsService;
    protected GoogleSheetsService $sheetsService;

    public function __construct(
        GoogleAuthService $authService,
        GoogleDocsService $docsService,
        GoogleSheetsService $sheetsService
    ) {
        $this->authService = $authService;
        $this->docsService = $docsService;
        $this->sheetsService = $sheetsService;
    }

    /**
     * Redirect user to Google OAuth Screen.
     */
    public function redirect(Request $request)
    {
        if ($request->has('redirect_to')) {
            session(['google_auth_redirect' => $request->redirect_to]);
        }
        
        return redirect()->away($this->authService->getAuthUrl());
    }

    /**
     * Handle Callback from Google OAuth.
     */
    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return redirect()->route('chat.index')->with('error', 'Gagal menghubungkan Google Account: ' . $request->error);
        }

        if ($request->has('code')) {
            try {
                $client = $this->authService->getClient();
                $token = $client->fetchAccessTokenWithAuthCode($request->code);

                if (isset($token['error'])) {
                    throw new \Exception($token['error_description'] ?? 'Token error');
                }

                $user = auth()->user();
                $this->authService->storeUserTokens($user, $token);

                $redirectUrl = session()->pull('google_auth_redirect', route('chat.index'));
                return redirect($redirectUrl)->with('success', 'Berhasil menghubungkan akun Google!');

            } catch (\Exception $e) {
                Log::error('Google OAuth Callback Error: ' . $e->getMessage());
                return redirect()->route('chat.index')->with('error', 'Terjadi kesalahan saat otentikasi Google: ' . $e->getMessage());
            }
        }

        return redirect()->route('chat.index');
    }

    /**
     * Get connection status.
     */
    public function status()
    {
        $user = auth()->user();
        return response()->json([
            'connected' => $this->authService->isConnected($user),
            'email' => $user->email
        ]);
    }

    /**
     * Disconnect Google Account.
     */
    public function disconnect()
    {
        $user = auth()->user();
        $user->google_token = null;
        $user->google_refresh_token = null;
        $user->google_token_expires_at = null;
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Koneksi akun Google berhasil dilepas.'
        ]);
    }

    /**
     * Export Message Content to Google Docs.
     */
    public function exportDocs(Message $message)
    {
        $user = auth()->user();

        if ($message->room->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized access.'], 403);
        }

        if (!$this->authService->isConnected($user)) {
            return response()->json([
                'status' => 'unauthenticated',
                'auth_url' => route('google.redirect', ['redirect_to' => url()->previous()]),
                'message' => 'Akun Google belum terhubung. Silakan hubungkan terlebih dahulu.'
            ], 401);
        }

        try {
            $docTitle = 'Draf Skripsi - ' . ($message->room->title ?: 'Dokumen AI');
            $docUrl = $this->docsService->createDocumentFromMarkdown($user, $docTitle, $message->content);

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil membuat Google Doc!',
                'url' => $docUrl
            ]);
        } catch (\Exception $e) {
            Log::error('Google Docs Export Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal membuat Google Doc: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export Message Table/Data to Google Sheets.
     */
    public function exportSheets(Message $message)
    {
        $user = auth()->user();

        if ($message->room->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized access.'], 403);
        }

        if (!$this->authService->isConnected($user)) {
            return response()->json([
                'status' => 'unauthenticated',
                'auth_url' => route('google.redirect', ['redirect_to' => url()->previous()]),
                'message' => 'Akun Google belum terhubung. Silakan hubungkan terlebih dahulu.'
            ], 401);
        }

        try {
            $sheetTitle = 'Matriks Data Skripsi - ' . ($message->room->title ?: 'Spreadsheet AI');
            $sheetUrl = $this->sheetsService->createSheetFromMarkdown($user, $sheetTitle, $message->content);

            return response()->json([
                'status' => 'success',
                'message' => 'Berhasil membuat Google Sheet!',
                'url' => $sheetUrl
            ]);
        } catch (\Exception $e) {
            Log::error('Google Sheets Export Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal membuat Google Sheet: ' . $e->getMessage()
            ], 500);
        }
    }
}
