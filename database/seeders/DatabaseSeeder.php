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
            'name' => 'User',
            'email' => 'Email@gmail.com',
            'password' => Hash::make('Password'),
            'phone_number' => '+6280000',
            'role' => 'admin',
        ]);

     
        $settings = [
            ['key' => 'ollama_enabled', 'value' => true, 'description' => 'Enable Ollama (Gemma4 12B) Local AI'],
            ['key' => 'ollama_cloud_enabled', 'value' => true, 'description' => 'Enable Ollama (Gemma4 31B Cloud) Local AI'],
            ['key' => 'deepseek_enabled', 'value' => false, 'description' => 'Enable DeepSeek API'],
        ];

        foreach ($settings as $setting) {
            Setting::create($setting);
        }

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