<?php

namespace App\Console\Commands;

use App\Services\RAG\RAGManager;
use Illuminate\Console\Command;

class IndexBrainDocuments extends Command
{
    protected $signature = 'rag:index 
                            {--force : Re-index all documents, even if already indexed}
                            {--id= : Index a specific document by ID}';

    protected $description = 'Index brain documents for RAG semantic search';

    public function handle(): int
    {
        $rag = new RAGManager();

        // Check if embedding model is available
        if (!$rag->isReady()) {
            $model = config('services.ollama.embedding_model', 'bge-m3:567m');
            $this->error('Embedding model not available!');
            $this->line('');
            $this->line('Please ensure Ollama is running and the embedding model is pulled:');
            $this->line("  ollama pull {$model}");
            $this->line('');
            return Command::FAILURE;
        }

        $this->info('RAG Indexing Started');
        $this->line('');

        // Show current stats
        $stats = $rag->getStats();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Documents', $stats['total_documents']],
                ['Already Indexed', $stats['indexed_documents']],
                ['Total Chunks', $stats['total_chunks']],
                ['Embedding Model', $stats['embedding_model']],
            ]
        );
        $this->line('');

        // Index specific document
        if ($id = $this->option('id')) {
            $brain = \App\Models\Brain::find($id);
            
            if (!$brain) {
                $this->error("Document with ID {$id} not found!");
                return Command::FAILURE;
            }

            $this->info("Indexing document: {$brain->title}");
            
            $bar = $this->output->createProgressBar(1);
            $bar->start();

            $success = $rag->indexDocument($brain);
            
            $bar->finish();
            $this->line('');
            $this->line('');

            if ($success) {
                $chunks = $brain->chunks()->count();
                $this->info("✓ Successfully indexed with {$chunks} chunks");
            } else {
                $this->error('✗ Failed to index document');
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        // Index all documents
        $force = $this->option('force');
        
        $query = \App\Models\Brain::query();
        if (!$force) {
            $query->where('is_indexed', false)->orWhereNull('is_indexed');
        }
        $total = $query->count();

        if ($total === 0) {
            $this->info('All documents are already indexed!');
            $this->line('Use --force to re-index all documents.');
            return Command::SUCCESS;
        }

        $this->info($force ? "Re-indexing all {$total} documents..." : "Indexing {$total} new documents...");
        $this->line('');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $results = ['success' => 0, 'failed' => 0];
        
        $documents = $force 
            ? \App\Models\Brain::all() 
            : \App\Models\Brain::where('is_indexed', false)->orWhereNull('is_indexed')->get();

        foreach ($documents as $brain) {
            if ($rag->indexDocument($brain)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->line('');

        // Final stats
        $this->info('Indexing Complete!');
        $this->table(
            ['Result', 'Count'],
            [
                ['✓ Success', $results['success']],
                ['✗ Failed', $results['failed']],
            ]
        );

        // Show updated stats
        $newStats = $rag->getStats();
        $this->line('');
        $this->info("Total chunks created: {$newStats['total_chunks']}");

        return Command::SUCCESS;
    }
}
