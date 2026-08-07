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
        Schema::table('messages', function (Blueprint $table) {
            // Menambahkan kolom search_context setelah kolom context_code
            // Tipe data text (atau longText) dan nullable agar kompatibel dengan pesan lama
            $table->text('search_context')->nullable()->after('context_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('search_context');
        });
    }
};