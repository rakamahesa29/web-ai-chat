<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates tables for Knowledge Graph storage:
     * - knowledge_nodes: Stores extracted entities (topics, concepts, persons)
     * - knowledge_edges: Stores relationships between entities
     */
    public function up(): void
    {
        Schema::create('knowledge_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
            $table->foreignId('message_id')->nullable()->constrained('messages')->onDelete('set null');
            $table->string('content', 500);
            $table->string('node_type', 50)->default('concept');
            $table->json('embedding')->nullable();
            $table->integer('frequency')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            
            $table->index(['room_id', 'node_type']);
            $table->index(['room_id', 'content']);
            $table->index('frequency');
        });

        Schema::create('knowledge_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
            $table->foreignId('source_node_id')->constrained('knowledge_nodes')->onDelete('cascade');
            $table->foreignId('target_node_id')->constrained('knowledge_nodes')->onDelete('cascade');
            $table->string('edge_type', 50)->default('EXTRACTED');
            $table->string('relation', 255)->nullable();
            $table->float('weight')->default(1.0);
            $table->text('context')->nullable();
            $table->timestamps();
            
            $table->index(['room_id', 'edge_type']);
            $table->index(['source_node_id', 'target_node_id']);
            $table->unique(['source_node_id', 'target_node_id', 'relation'], 'unique_edge');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('graph_indexed')->default(false)->after('search_context');
            $table->integer('graph_tokens_saved')->nullable()->after('graph_indexed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['graph_indexed', 'graph_tokens_saved']);
        });
        
        Schema::dropIfExists('knowledge_edges');
        Schema::dropIfExists('knowledge_nodes');
    }
};
