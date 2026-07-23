# Omoikane AI Chat

A full-featured, self-hosted AI chat application built with Laravel 12 and a dark-themed UI (codename **Omoikane**). It supports multiple LLM providers, Retrieval-Augmented Generation (RAG) with Just-In-Time local folder indexing, a Knowledge Graph memory layer, intelligent query classification, DeepSeek Pro reasoning mode, and a dedicated academic/thesis assistant mode.

## Tech Stack

- **Backend:** PHP 8.2+ / Laravel 12
- **Frontend:** Blade + Tailwind CSS + Alpine.js
- **Icons:** Lucide Icons (CDN)
- **Database:** MySQL 8
- **LLM Providers:** Ollama (local), Ollama Cloud, DeepSeek API (Flash + Pro)
- **Embeddings:** Ollama `nomic-embed-text`
- **Web Search:** Tavily API
- **PDF Parsing:** smalot/pdfparser

## Features

### Multi-Provider AI Chat
- **Three LLM backends** switchable per-message: Ollama local (Gemma4 12B), Ollama Cloud (Gemma4 31B), and DeepSeek API.
- **DeepSeek Pro Mode:** Toggle to switch from DeepSeek V4 Flash to DeepSeek V4 Pro with chain-of-thought reasoning enabled. Thinking/reasoning steps are rendered in a collapsible `<details>` block in the UI.
- **Real-time SSE streaming** for all providers with token-by-token rendering and markdown/code highlighting.
- Provider toggles on the Dashboard to enable/disable each backend.

### AI Personas
Six built-in persona modes that modify the AI's tone and behavior per chat room:

| Persona | Style |
|---|---|
| **General** | Balanced, neutral, adaptive |
| **The Architect** | Technical, dense, code-focused |
| **The Bestie** | Casual, friendly, modern slang |
| **The Sage** | Empathetic, reflective, philosophical |
| **The Executive** | Professional, strategic, data-driven |
| **The Educator** | Formal, academic, structured |

### Education & Thesis Assistant (Skripsi Mode)
- **The Educator** persona for structured academic guidance.
- **Skripsi Mode** toggle (visible in education rooms) activates *The Academic Strategist / Zero-Similarity Engine* — a specialized persona for thesis writing that applies N-gram destruction, AI detection manipulation, and semantic reconstruction to produce original academic text.
- **Thesis Evaluation Auto-Extract:** When the AI evaluates thesis chapters, analyzes "benang merah" (coherence), or simulates a defense, it outputs structured evaluation data (`[THESIS_EVAL]` marker) that is automatically stripped from the displayed response and saved to the `thesis_evaluations` table.
- **Chapter-Level Scoring:** Per-bab scores (Bab 1-5 + coherence), strengths, weaknesses, and recommendations are persisted per room.
- **Thesis Progress Record:** Historical evaluation data is injected into the AI's context automatically, giving it long-term memory of the student's thesis progress across conversations.
- **Dedicated Brain Documents:** Knowledge base entries covering paraphrasing strategies, Turnitin algorithm deconstruction, research gap validation, literature review architecture, methodology protocols, thesis coherence ("benang merah"), critical analysis frameworks, and defense simulation.

### Knowledge Base (Brain)
- CRUD management for knowledge documents with a rich text editor (CKEditor 5).
- Documents are tagged to specific personas (General, Architect, Bestie, Sage, Executive, Educator) and/or custom tags.
- Supports **raw text** and **PDF upload** (auto-extracted via smalot/pdfparser).
- **File hash tracking** (`file_hash` column) for smart cache validation during JIT indexing.

### Retrieval-Augmented Generation (RAG)
- **Dual search strategy:** Vector search (embeddings via Ollama `nomic-embed-text`) with automatic fallback to keyword search.
- **Hybrid search** combining vector similarity + keyword matching for better recall.
- **MMR (Maximal Marginal Relevance)** for diversity in retrieved chunks.
- **Smart document selection** with relevance scoring — title match (15pts), tag match (20pts), content density scoring, and dynamic document limits based on score confidence.
- **Bilingual support** with Indonesian-English synonym mapping and topic-boosted keywords.
- **Chunking** with configurable size, overlap, and minimum thresholds.
- **Anti-hallucination rules** enforced in the system prompt when RAG context is active.
- **Scoped vector search** for restricting retrieval to specific brain document IDs (used by JIT RAG).

### JIT Agentic RAG (Local Folder Indexing)
On-demand retrieval from local directories — no pre-indexing required. When a user message contains a macOS folder path (e.g., `/Users/.../my-docs`), the system automatically triggers a multi-phase pipeline:

| Phase | Description |
|---|---|
| **1. Scan** | Recursively discovers `.txt`, `.md`, `.pdf` files in the folder (skips hidden/empty/>10MB). |
| **2. Pre-filter (Grep)** | Extracts keywords from the user question, then scores and ranks files by filename + content keyword density. Top 7 files are selected. |
| **3. Smart Cache** | Computes `md5` file hash and checks the `brains.file_hash` column. Unchanged files are served from cache; only new/modified files are re-embedded. |
| **4. JIT Embed** | Creates/updates Brain records, chunks the content, batch-embeds via Ollama, and bulk-inserts into `brain_chunks`. |
| **5. Scoped Search** | Performs cosine-similarity vector search restricted to the JIT-indexed `brain_ids` only, with a fallback to first-chunk document context. |

- **Real-time SSE status events** are streamed to the UI at each phase (scanning, filtering, embedding, searching).
- **Cache-efficient:** Repeated queries against the same folder skip embedding entirely for unchanged files.
- **Isolated context:** JIT RAG context is injected directly into the system prompt, bypassing the normal RAG pipeline.

### Intelligent Query Classification
Every user message is classified before processing:

| Type | Behavior |
|---|---|
| `general` | Uses AI model's built-in knowledge directly, skips RAG |
| `domain_specific` | Searches internal knowledge base via RAG |
| `latest_data` | Checks internal data first, then suggests or recommends web search |
| `jit_rag` | Local folder path detected — triggers JIT Agentic RAG pipeline |

### Web Search Integration
- Toggle-based web search via **Tavily API**.
- Smart suggestions: when a query needs latest data but no internal source exists, the UI prompts the user to confirm web search before executing.
- Post-response recommendations: after answering from internal data, the AI may recommend a web search for fresher results.

### Knowledge Graph
- Automatic entity and relationship extraction from every conversation (async job).
- Interactive **vis.js** graph visualization on the Dashboard with room filtering.
- Node types: Topic, Concept, Person, Action, Entity — with extracted and inferred edges.
- Graph context is injected into the AI prompt for richer, context-aware responses.
- Configurable max nodes, hops, and auto-cleanup.

### Memory Management
- **Sliding window:** Last 10 messages (5 pairs) kept in active context.
- **Auto-compression:** When the window fills, older messages are summarized into long-term memory (`room_summaries`).
- **Long-term memory injection:** Up to 5 latest summaries are injected as system context.
- **Smart truncation:** Character-based limits adapted per provider (32K local, 150K cloud).
- **Memory cycle indicator** in the chat UI shows current window position.

### Dashboard & Analytics
- **Provider toggles** to enable/disable AI backends.
- **Stats cards:** Average memory usage, bot/user tokens per message, success rate.
- **Charts:** Satisfaction analytics (doughnut), content dynamics (radar), 7-day chat activity (bar).
- **Knowledge Graph visualization** with interactive filtering.
- **User Behavior Analysis:** AI-powered profiling that analyzes chat history and knowledge graph data, with support for renewal and model selection.

### Chat UX
- **Conversation sidebar** with room list, new chat creation, and delete.
- **File attachments:** Paste code or upload files (PDF, DOCX, TXT, PHP, JS, Python, HTML, CSS, JSON, XML, CSV, MD).
- **Message actions:** Thumbs up/down rating, delete, continue generation.
- **DeepSeek Pro toggle:** In-chat switch to enable reasoning/thinking mode on DeepSeek provider.
- **JIT RAG status indicators:** Real-time progress display during local folder indexing (scanning, filtering, embedding, searching).
- **Knowledge Gap detection:** When the AI lacks information, it offers Web Search, DeepSeek, or Force Local alternatives.
- **Rate limiting** to prevent concurrent message processing.
- **Responsive design** with mobile sidebar toggle.

## Project Structure

```
app/
├── Http/Controllers/
│   ├── ChatController.php         # Chat CRUD, send, retry, streaming
│   ├── DashboardController.php    # Analytics, provider toggles, analysis
│   └── BrainController.php        # Knowledge base CRUD
├── Models/
│   ├── Room.php                   # Chat rooms with persona & category
│   ├── Message.php                # Chat messages with tokens & ratings
│   ├── Brain.php                  # Knowledge documents with tags & file_hash
│   ├── BrainChunk.php             # RAG chunks with embeddings
│   ├── RoomSummary.php            # Compressed memory summaries
│   ├── ThesisEvaluation.php       # Thesis evaluation scores per room
│   ├── KnowledgeNode.php          # Graph nodes
│   ├── KnowledgeEdge.php          # Graph edges
│   └── UserAnalysis.php           # Behavior analysis results
├── Services/
│   ├── AI/
│   │   ├── ChatProcessor.php      # Main orchestrator: memory → JIT RAG → prompt → stream
│   │   ├── PromptBuilder.php      # System prompt assembly with RAG, JIT & context
│   │   ├── QueryClassifier.php    # Classifies queries into types
│   │   ├── MemoryManager.php      # Auto-compression & sliding window
│   │   ├── ProviderFactory.php    # Adapter resolution (incl. DeepSeek Pro)
│   │   ├── ConfigurationManager.php
│   │   └── Adapters/
│   │       ├── OllamaAdapter.php
│   │       ├── OllamaCloudAdapter.php
│   │       └── DeepseekAdapter.php  # Flash + Pro mode with reasoning
│   ├── RAG/
│   │   ├── RAGManager.php         # Vector + hybrid retrieval
│   │   ├── EmbeddingService.php   # Ollama embedding generation
│   │   ├── VectorSearchService.php # Includes scoped search for JIT RAG
│   │   ├── ChunkingService.php
│   │   └── JitRagService.php      # Just-In-Time local folder RAG pipeline
│   ├── KnowledgeGraph/
│   │   ├── GraphManager.php       # Graph context retrieval
│   │   ├── GraphBuilder.php       # Node/edge creation
│   │   ├── EntityExtractor.php    # Entity extraction from messages
│   │   └── GraphQueryService.php
│   └── Search/
│       └── WebSearchAgent.php     # Tavily web search
├── Jobs/
│   ├── ExtractMessageEntities.php  # Async knowledge graph extraction
│   ├── ExtractThesisEvaluation.php # Sync thesis eval parsing & storage
│   ├── CompressRoomMemory.php      # Memory compression job
│   └── AnalyzeUserBehaviorJob.php  # User behavior analysis
```

## Setup

### Prerequisites
- PHP 8.2+
- Composer
- MySQL 8
- Node.js & npm
- Ollama (for local LLM and embeddings)

### Installation

```bash
git clone <repository-url> ai-chat-app
cd ai-chat-app

composer install
npm install

cp .env.example .env
php artisan key:generate
```

### Environment Variables

```env
# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ai-chat-app
DB_USERNAME=root
DB_PASSWORD=

# Ollama (Local)
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_GEMMA4_MODEL=gemma4:12b
OLLAMA_EMBEDDING_MODEL=nomic-embed-text

# Ollama (Cloud)
OLLAMA_CLOUD_BASE_URL=http://<cloud-ip>:11434
OLLAMA_GEMMA4_CLOUD_MODEL=gemma4:31b-cloud

# DeepSeek API
DEEPSEEK_API_KEY=your-deepseek-api-key
DEEPSEEK_MODEL=deepseek-v4-flash
DEEPSEEK_PRO_MODEL=deepseek-v4-pro
DEEPSEEK_PRO_REASONING_EFFORT=high

# Tavily Web Search
TAVILY_API_KEY=your-tavily-api-key

# RAG
RAG_ENABLED=true
RAG_CHUNK_SIZE=1000
RAG_TOP_K=6

# Knowledge Graph
KNOWLEDGE_GRAPH_ENABLED=true
```

### Database & Build

```bash
php artisan migrate
npm run build
```

### Running

```bash
# All-in-one dev mode (server + queue + logs + vite)
composer dev

# Or individually
php artisan serve
php artisan queue:listen --tries=1
npm run dev
```

## License

This project is proprietary software.
