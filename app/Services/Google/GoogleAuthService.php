<?php

namespace App\Services\Google;

use Google\Client;
use App\Models\User;
use Illuminate\Support\Carbon;

class GoogleAuthService
{
    /**
     * Create and configure the Google Client instance.
     */
    public function getClient(?User $user = null): Client
    {
        $client = new Client();
        $clientId = config('services.google.client_id') ?: 'dummy-google-client-id';
        $clientSecret = config('services.google.client_secret') ?: 'dummy-google-client-secret';

        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri(config('services.google.redirect'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        // Required Scopes for Google Docs, Sheets, and Drive
        $client->addScope([
            'https://www.googleapis.com/auth/documents',
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/drive.file',
            'email',
            'profile'
        ]);

        if ($user && $user->google_token) {
            $accessToken = json_decode($user->google_token, true) ?: [
                'access_token' => $user->google_token,
                'created' => time(),
                'expires_in' => 3600
            ];

            if ($user->google_refresh_token) {
                $accessToken['refresh_token'] = $user->google_refresh_token;
            }

            $client->setAccessToken($accessToken);

            // Auto-refresh token if expired
            if ($client->isAccessTokenExpired()) {
                if ($user->google_refresh_token) {
                    $newToken = $client->fetchAccessTokenWithRefreshToken($user->google_refresh_token);
                    if (!isset($newToken['error'])) {
                        $this->storeUserTokens($user, $newToken);
                    }
                }
            }
        }

        return $client;
    }

    /**
     * Generate Google OAuth Consent Screen URL.
     */
    public function getAuthUrl(): string
    {
        return $this->getClient()->createAuthUrl();
    }

    /**
     * Store Google tokens to User model.
     */
    public function storeUserTokens(User $user, array $tokenResponse): void
    {
        $user->google_token = json_encode($tokenResponse);

        if (!empty($tokenResponse['refresh_token'])) {
            $user->google_refresh_token = $tokenResponse['refresh_token'];
        }

        if (!empty($tokenResponse['expires_in'])) {
            $user->google_token_expires_at = Carbon::now()->addSeconds($tokenResponse['expires_in']);
        }

        $user->save();
    }

    /**
     * Check if user is connected to Google.
     */
    public function isConnected(User $user): bool
    {
        return !empty($user->google_token) || !empty($user->google_refresh_token);
    }
}
