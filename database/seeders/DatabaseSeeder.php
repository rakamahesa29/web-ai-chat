<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Setting;
use App\Models\Room;
use App\Models\Message;
use App\Models\AiConfig; 
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Membuat Akun Admin Utama
        $admin = User::create([
            'name' => 'Raka Mahesa',
            'email' => 'rakamahesa29@gmail.com',
            'password' => Hash::make('@Raka3298'),
            'phone_number' => '+6285179572185',
            'role' => 'admin',
        ]);

        // 2. Menyiapkan Konfigurasi Fitur (Toggle System)
        $settings = [
            ['key' => 'ollama_enabled', 'value' => true, 'description' => 'Enable Ollama (Gemma4 12B) Local AI'],
            ['key' => 'ollama_cloud_enabled', 'value' => true, 'description' => 'Enable Ollama (Gemma4 31B Cloud) Local AI'],
            ['key' => 'deepseek_enabled', 'value' => false, 'description' => 'Enable DeepSeek API'],
        ];

        foreach ($settings as $setting) {
            Setting::create($setting);
        }

        // 3. Menyiapkan Konfigurasi Model AI (Kritikal untuk mencegah Error 500)
        // Data ini yang dibaca oleh ConfigurationManager::getConfiguration()
        $aiConfigs = [
            [
                'model_name' => 'ollama', 
                'provider' => 'ollama', 
                'model_name_api' => 'gemma4:12b-mlx', 
                'api_key' => null,
            ],
            [
                'model_name' => 'ollama_cloud', 
                'provider' => 'ollama_cloud', 
                'model_name_api' => 'gemma4:31b-cloud', 
                'api_key' => null,
            ],
            [
                'model_name' => 'deepseek', 
                'provider' => 'deepseek', 
                'model_name_api' => 'deepseek-v4-flash', 
                'api_key' => 'YOUR_DEEPSEEK_KEY',
            ],
        ];

        foreach ($aiConfigs as $config) {
            AiConfig::create($config);
        }

        // 4. Menyiapkan 5 Top Kategori AI
        $categories = ['Software Dev', 'Copywriting', 'Education', 'Business', 'Daily Life'];
    }
}