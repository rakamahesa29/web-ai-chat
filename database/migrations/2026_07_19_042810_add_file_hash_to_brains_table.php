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
        Schema::table('brains', function (Blueprint $table) {
            $table->string('file_hash', 64)->nullable()->after('file_path');
            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::table('brains', function (Blueprint $table) {
            $table->dropIndex(['file_hash']);
            $table->dropColumn('file_hash');
        });
    }
};
