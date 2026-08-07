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
        Schema::table('user_analyses', function (Blueprint $table) {
            $table->string('model_used', 50)->default('deepseek')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('user_analyses', function (Blueprint $table) {
            $table->dropColumn('model_used');
        });
    }
};
