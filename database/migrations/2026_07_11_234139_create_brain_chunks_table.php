<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This table stores chunked documents with their vector embeddings
     * for semantic RAG search (O'Reilly pattern).
     */
    public function up(): void
    {
        Schema::create('brain_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brain_id')->constrained('brains')->onDelete('cascade');
            $table->integer('chunk_index')->default(0);
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->integer('token_count')->default(0);
            $table->string('chunk_type')->default('text'); // text, code, header
            $table->timestamps();
            
            $table->index(['brain_id', 'chunk_index']);
        });

        // Add embedding column to brains table for document-level embedding
        Schema::table('brains', function (Blueprint $table) {
            $table->json('embedding')->nullable()->after('type');
            $table->boolean('is_indexed')->default(false)->after('embedding');
            $table->timestamp('indexed_at')->nullable()->after('is_indexed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brain_chunks');
        
        Schema::table('brains', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'is_indexed', 'indexed_at']);
        });
    }
};
