<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_configs', function (Blueprint $table) {
            $table->id();
            // Menentukan jenis penyedia: 'google' (Gemini), 'anthropic' (Claude), atau 'local' (Ollama/LocalAI)
            $table->string('provider'); 
            
            // Nama model spesifik yang akan dipanggil, misal: 'gemini-1.5-flash' atau 'llama3-8b'
            $table->string('model_name');
            

            $table->string('model_name_api')->nullable(); // Nama model yang digunakan untuk API, misal: 'gemma4:12b' atau 'glm-5.2:cloud'
            
            // Menyimpan API Key (Data sensitif, dienkripsi oleh Laravel jika perlu)
            $table->text('api_key')->nullable(); 
            
            // Status aktif/nonaktif untuk mempermudah manajemen dari dashboard
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_configs');
    }
};