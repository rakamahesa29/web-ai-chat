<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Omoikane AI') }}</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('img/16x16.png') }}">

    <!-- Theme initialization -->
    <script>
        (function() {
            const stored = localStorage.getItem('theme');
            if (stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-hermes-bg text-hermes-text">
    <div class="min-h-screen flex flex-col sm:justify-center items-center py-12 sm:py-16 px-4">
        <!-- Logo -->
        <a href="/" class="flex items-center gap-2 mb-8">
            <img src="{{ asset('img/64x64.png') }}" alt="Omoikane AI" class="w-10 h-10 rounded-xl">
            <span class="text-xl font-bold text-hermes-text">Omoikane AI</span>
        </a>
        
        <!-- Card Container -->
        <div class="w-full sm:max-w-md px-8 py-10 hermes-card">
            {{ $slot }}
        </div>
        
        <!-- Footer -->
        <p class="mt-8 text-xs text-hermes-muted">
            &copy; {{ date('Y') }} Omoikane AI. All rights reserved.
        </p>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => lucide.createIcons());
    </script>
</body>
</html>
