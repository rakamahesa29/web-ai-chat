<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'description'];

    /**
     * Helper untuk cek apakah fitur aktif dengan cepat
     * Contoh penggunaan: Setting::isEnabled('ollama_enabled')
     */
    public static function isEnabled(string $key): bool
    {
        $setting = self::where('key', $key)->first();
        return (bool) ($setting && $setting->value);
    }
}