<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, get all existing brains to convert their tags
        $brains = DB::table('brains')->get();

        // Change column type
        Schema::table('brains', function (Blueprint $table) {
            $table->json('tag')->nullable()->change();
        });

        // Update existing records
        foreach ($brains as $brain) {
            $tag = $brain->tag;
            // If it's not already a JSON array, make it one
            if (!is_array(json_decode($tag, true))) {
                DB::table('brains')
                    ->where('id', $brain->id)
                    ->update(['tag' => json_encode([$tag])]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $brains = DB::table('brains')->get();

        Schema::table('brains', function (Blueprint $table) {
            $table->string('tag')->default('general')->change();
        });

        foreach ($brains as $brain) {
            $tags = json_decode($brain->tag, true);
            $tag = is_array($tags) && count($tags) > 0 ? $tags[0] : 'general';
            DB::table('brains')
                ->where('id', $brain->id)
                ->update(['tag' => $tag]);
        }
    }
};
