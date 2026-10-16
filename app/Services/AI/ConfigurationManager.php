<?php

namespace App\Services\AI;

use App\Models\AiConfig;
use Illuminate\Support\Facades\DB;

class ConfigurationManager
{
    public static function getConfiguration(string $modelName): object
    {
        $config = null;

        if ($modelName === 'ollama') {
            $setting = DB::table('settings')->where('key', 'ollama_enabled')->first();
            $isActive = $setting ? (bool)$setting->value : false; 

            $config = (object) [
                'provider'       => 'ollama',
                'model_name_api' => config('services.ollama.model'),
                'is_active'      => $isActive,
            ];
        } elseif ($modelName === 'ollama_cloud') {
            $setting = DB::table('settings')->where('key', 'ollama_cloud_enabled')->first();
            $isActive = $setting ? (bool)$setting->value : false; 

            $config = (object) [
                'provider'       => 'ollama_cloud',
                'model_name_api' => config('services.ollama_cloud.model'),
                'is_active'      => $isActive,
            ];
        } elseif ($modelName === 'deepseek') {
            $setting = DB::table('settings')->where('key', 'deepseek_enabled')->first();
            $isActive = $setting ? (bool)$setting->value : false; 

            $config = (object) [
                'provider'       => 'deepseek',
                'model_name_api' => config('services.deepseek.model'),
                'is_active'      => $isActive,
                'api_key'        => config('services.deepseek.api_key'),
            ];
        } elseif ($modelName === 'anthropic') {
            $setting = DB::table('settings')->where('key', 'anthropic_enabled')->first();
            $isActive = $setting ? (bool)$setting->value : true; // Default to true if not in DB yet

            $config = (object) [
                'provider'       => 'anthropic',
                'model_name_api' => config('services.anthropic.model'),
                'is_active'      => $isActive,
                'api_key'        => config('services.anthropic.api_key'),
            ];
        } elseif (
            $modelName === 'gemini' || 
            $modelName === 'gemini_pro' ||
            str_starts_with($modelName, 'gemini-') ||
            str_starts_with($modelName, 'claude-') ||
            str_starts_with($modelName, 'gpt-')
        ) {
            $setting = DB::table('settings')->where('key', 'gemini_enabled')->first();
            $isActive = $setting ? (bool)$setting->value : true;

            $config = (object) [
                'provider'       => 'gemini',
                'model_name_api' => in_array($modelName, ['gemini', 'gemini_pro']) ? 'gemini-3.8-flash-low' : $modelName,
                'is_active'      => $isActive,
            ];
        } else {
            // Fallback untuk model dari database jika ada
            $config = AiConfig::where('model_name', $modelName)->first();
        }

        if (!$config || !isset($config->is_active) || !$config->is_active) {
            $providerLabel = str_replace('_', ' ', ucfirst($modelName));
            throw new \Exception("Koneksi ke AI {$providerLabel} sedang dimatikan. Silakan aktifkan kembali di Dashboard.");
        }

        return $config;
    }
}