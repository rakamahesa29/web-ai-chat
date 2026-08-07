# Migrasi ke Laravel Octane (Localhost) — Implementation Plan

> **Untuk Hermes:** Gunakan subagent-driven-development skill untuk implement task-by-task.

**Goal:** Migrasi ai-chat-app dari MAMP (nginx + php-fpm) ke Laravel Octane dengan FrankenPHP untuk performa tinggi di localhost.

**Architecture:** FrankenPHP (PHP 8.5 built-in server, worker mode) menggantikan nginx + php-fpm. Laravel boot sekali lalu reuse di memory — request-response jadi jauh lebih cepat. SSE streaming (`response()->stream()`) tetap jalan normal selama tidak pakai `exit/die`.

**Tech Stack:** Laravel 13, PHP 8.5, FrankenPHP (via `laravel/octane`), MySQL/SQLite, Orbstack.

**Current state:**
- PHP 8.5 via MAMP
- `DB_CONNECTION=sqlite`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`
- SSE streaming di `ChatApiController` + `ChatProcessor`
- Cache rate-limit pakai `Cache::put()` (driver: file atau array)
- Belum ada config Octane, belum ada dependency Octane

**⚠️ PENTING — SSE Streaming & Agent Mode di Octane:**
Karena app ini heavy di SSE streaming (`response()->stream()` untuk chat + agent mode `AgentProcessor`), ada beberapa hal kritis:
1. **Worker count harus > 1** — 1 worker untuk SSE + 1 untuk request normal
2. **Request timeout** — atur `--max-requests=500` biar worker di-restart berkala
3. **State di singleton** — pastikan tidak ada state dari request sebelumnya yang bocor (check `AgentProcessor`, `ChatManager`, `ProviderFactory`)
4. **Cache lock dihapus dengan benar** — `Cache::put('user_processing_'.$id, true, 300)` harus di-clear di semua path (termasuk disconnect)

---

## Task 1: Install & Konfigurasi Octane + FrankenPHP

**Objective:** Tambahkan `laravel/octane` dependency, install FrankenPHP, publish config.

**Files:**
- Modify: `composer.json`
- Create: `config/octane.php`
- Modify: `.env`

**Step 1: Install Octane package**

```bash
cd /Applications/MAMP/htdocs/ai-chat-app
composer require laravel/octane
```

**Step 2: Install FrankenPHP binary**

```bash
php artisan octane:install --server=frankenphp
```

Ini akan:
- Download FrankenPHP binary ke project root
- Buat `config/octane.php`
- Tambahkan `OCTANE_SERVER=frankenphp` ke `.env`

**Step 3: Verifikasi install**

```bash
php artisan octane:status
```

Expected: "Octane is installed. Server: frankenphp"

**Commit:**
```bash
git add composer.json composer.lock config/octane.php .env
git commit -m "feat: install laravel octane with frankenphp"
```

---

## Task 2: Audit Singleton & State Leak

**Objective:** Pastikan singleton/services tidak menyimpan state dari request sebelumnya (critical untuk SSE + agent mode).

**Files to audit:**
- `app/Services/AI/ProviderFactory.php`
- `app/Services/AI/ChatManager.php`
- `app/Services/AI/AgentProcessor.php`
- `app/Services/AI/ConfigurationManager.php`
- `app/Services/RAG/RAGManager.php`
- `app/Services/KnowledgeGraph/GraphManager.php`

**Step 1: Cek singleton registration**

```bash
grep -rn "singleton\|scoped\|bind(" app/Providers/AppServiceProvider.php
```

Pastikan tidak ada yang menyimpan user-specific data di properti class singleton.

**Step 2: Cek state di AgentProcessor**

Buka `app/Services/AI/AgentProcessor.php` — pastikan:
- State token di-reset setiap request
- Tidak ada properti yang menyimpan data dari request sebelumnya
- Semua state pakai parameter method, bukan properti class

**Step 3: Cek ProviderFactory**

`ProviderFactory` harus stateless — hanya resolve provider berdasarkan config.

**Step 4: Write octane-specific service provider**

Buat `app/Providers/OctaneServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class OctaneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reset stateful singletons between requests
        $this->app->terminating(function () {
            // Flush any request-specific cache
        });
    }

    public function boot(): void
    {
        // No-op for now
    }
}
```

Register di `bootstrap/app.php`.

**Commit:**
```bash
git add app/Providers/OctaneServiceProvider.php bootstrap/app.php
git commit -m "feat: add octane service provider for state cleanup"
```

---

## Task 3: Konfigurasi Octane untuk Development

**Objective:** Set config Octane yang optimal untuk localhost development.

**Files:**
- Modify: `config/octane.php`

**Step 1: Config `config/octane.php`**

```php
return [
    'server' => env('OCTANE_SERVER', 'frankenphp'),

    'frankenphp' => [
        'host' => env('OCTANE_HOST', '127.0.0.1'),
        'port' => env('OCTANE_PORT', '8000'),
        'admin_port' => env('OCTANE_ADMIN_PORT', '2019'),
        'workers' => [
            'num' => env('OCTANE_WORKERS', 4),        // 4 worker — cukup untuk SSE
            'max_requests' => env('OCTANE_MAX_REQUESTS', 500),
            'request_timeout' => env('OCTANE_REQUEST_TIMEOUT', 300), // 5 menit untuk SSE
        ],
        'https' => env('OCTANE_HTTPS', false),
        'http_redirect' => env('OCTANE_HTTP_REDIRECT', false),
    ],

    'swoole' => [
        // Kosongkan — kita pakai FrankenPHP
    ],

    'roadrunner' => [
        // Kosongkan — kita pakai FrankenPHP
    ],

    'watch' => [
        'enabled' => env('OCTANE_WATCH', true),
        'paths' => ['app', 'config', 'routes', 'resources/views'],
    ],

    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],

    'tables' => [
        // ...
    ],
];
```

**Step 2: Update `.env`**

```env
OCTANE_SERVER=frankenphp
OCTANE_HOST=127.0.0.1
OCTANE_PORT=8000
OCTANE_WORKERS=4
OCTANE_MAX_REQUESTS=500
OCTANE_REQUEST_TIMEOUT=300
OCTANE_WATCH=true
```

**Commit:**
```bash
git add config/octane.php .env
git commit -m "feat: configure octane for localhost with 4 workers & SSE timeout"
```

---

## Task 4: Switch Session & Queue Driver (Optional but Recommended)

**Objective:** Database-driven session/queue lambat di Octane. Ganti ke `file` (local) atau `redis`.

**Files:**
- Modify: `.env`

**Step 1: Pilih driver**

Untuk localhost development, rekomendasi:
- **Session**: `file` (cepat, no dependency)
- **Queue**: `sync` (jalan inline) atau `database` (existing)

**Step 2: Update `.env`**

```env
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
```

> Jika ada job background (`AnalyzeUserBehaviorJob`, `CompressRoomMemory`, dll), pertahankan `QUEUE_CONNECTION=database` dan jalankan `php artisan queue:work` di terminal terpisah.

**Step 3: Clear config cache**

```bash
php artisan config:clear
```

**Commit:**
```bash
git add .env
git commit -m "perf: switch session to file, queue to sync for octane"
```

---

## Task 5: Test Run & Validasi

**Objective:** Jalankan Octane dan pastikan semua fitur jalan.

**Step 1: Start Octane**

```bash
php artisan octane:start --port=8000
```

Expected output:
```
INFO  Server running…
Local: http://127.0.0.1:8000
```

**Step 2: Test endpoint critical**

```bash
# Auth
curl -s http://127.0.0.1:8000/api/auth/user -H "Authorization: Bearer <token>" | head -1

# Chat rooms
curl -s http://127.0.0.1:8000/api/chat/rooms -H "Authorization: Bearer <token>" | head -1

# Dashboard
curl -s http://127.0.0.1:8000/api/dashboard/stats -H "Authorization: Bearer <token>" | head -1
```

**Step 3: Test SSE streaming (critical)**

```bash
curl -N -X POST http://127.0.0.1:8000/api/chat/rooms/1/send \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"message":"halo","model":"deepseek","chat_mode":"ask"}'
```

Expected: SSE events streaming dengan `data: {"content":"..."}`

**Step 4: Test web UI**

Buka `http://127.0.0.1:8000` di browser. Login, kirim chat, verifikasi fitur lengkap:
- [ ] Chat dengan streaming
- [ ] Agent mode
- [ ] DeepSeek thinking
- [ ] Web search
- [ ] File upload
- [ ] Knowledge graph
- [ ] Dashboard

**Step 5: Test concurrent**

Buka 2 tab chat room berbeda → kirim chat bersamaan → pastikan tidak ada "429 rate limited" yang salah.

**Commit:**
```bash
git commit --allow-empty -m "test: octane integration verified"
```

---

## Task 6: MAMP Cleanup (Opsional)

**Objective:** Setelah Octane stabil, matikan MAMP nginx/php-fpm.

**Step 1: Stop MAMP services**

Lewati jika masih butuh MAMP untuk project lain.

**Step 2: Update SwiftUI app URL**

Di `Omoikane/Core/APIClient.swift`, ubah base URL:

```swift
static let baseURL = "http://localhost:8000/api"
```

**Commit:**
```bash
git add Omoikane/Core/APIClient.swift
git commit -m "chore: point api client to octane port 8000"
```

---

## Risiko & Pitfalls

| Risiko | Mitigasi |
|---|---|
| SSE connection blocking worker | 4 worker, 300s timeout — cukup |
| Singleton state leak antar request | `OctaneServiceProvider` reset di `terminating` |
| `Cache::put()` rate-limit lock stuck | Sudah ada `POST /api/admin/clear-locks` — pastikan dipanggil di `stopStreaming` SwiftUI |
| File-based session di multi-worker | Untuk localhost ok, untuk production ganti ke Redis |
| `php artisan octane:reload` perlu tiap code change | File watcher (`OCTANE_WATCH=true`) auto-reload |

## Quick Reference Commands (Setelah Migrasi)

```bash
# Start dev server
php artisan octane:start --port=8000

# Start dengan hot-reload (watch mode)
php artisan octane:start --port=8000 --watch

# Restart workers
php artisan octane:reload

# Stop server
php artisan octane:stop

# Check status
php artisan octane:status
```
