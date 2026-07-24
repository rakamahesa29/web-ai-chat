<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->longText('content');
            $table->enum('source_type', ['file_upload', 'manual_input'])->default('manual_input');
            $table->string('original_filename')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['room_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_skills');
    }
};
