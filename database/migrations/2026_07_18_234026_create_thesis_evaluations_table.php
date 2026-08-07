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
        Schema::create('thesis_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->onDelete('cascade');
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('evaluation_type');
            $table->decimal('overall_score', 3, 1)->default(0);
            $table->json('chapter_scores');
            $table->json('strengths')->nullable();
            $table->json('weaknesses')->nullable();
            $table->text('recommendations')->nullable();
            $table->text('raw_evaluation')->nullable();
            $table->timestamps();

            $table->index(['room_id', 'evaluation_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('thesis_evaluations');
    }
};
