<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE brains ADD FULLTEXT INDEX ft_brains_title_content (title, content)');
    }

    public function down(): void
    {
        Schema::table('brains', function (Blueprint $table) {
            $table->dropIndex('ft_brains_title_content');
        });
    }
};
