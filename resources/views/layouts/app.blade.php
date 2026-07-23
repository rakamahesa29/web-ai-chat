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
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('img/128x128.png') }}">

    <!-- Theme initialization (prevents flash of wrong theme) -->
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
    {{-- Single Alpine scope wrapping sidebar + main content for shared state --}}
    <div x-data="appShell()" x-init="initShell()" @resize.window="onResize()" class="min-h-screen bg-hermes-bg">
        {{-- Sidebar Navigation --}}
        @include('layouts.navigation')

        <!-- Page Content -->
            <main 
            :class="mainClasses()"
            class="flex-1 ml-0 transition-all duration-300"
        >
            <div class="@if(!request()->routeIs('chat.show')) w-full max-w-[1600px] px-4 sm:px-6 lg:px-8 @endif h-full">
                @yield('content')
            </div>
        </main>
    </div>

    <script>
        function appShell() {
            return {
                sidebarCollapsed: false,
                mobileOpen: false,
                isMobile: window.innerWidth < 768,

                initShell() {
                    const saved = localStorage.getItem('sidebar_collapsed');
                    this.sidebarCollapsed = saved === 'true';
                    this.isMobile = window.innerWidth < 768;
                    if (this.isMobile) {
                        this.sidebarCollapsed = true;
                    }
                    this.$nextTick(() => {
                        if (window.lucide) lucide.createIcons();
                    });
                },

                toggleCollapse() {
                    this.sidebarCollapsed = !this.sidebarCollapsed;
                    localStorage.setItem('sidebar_collapsed', this.sidebarCollapsed.toString());
                    this.$nextTick(() => {
                        if (window.lucide) lucide.createIcons();
                    });
                },

                onResize() {
                    this.isMobile = window.innerWidth < 768;
                    if (!this.isMobile) {
                        this.mobileOpen = false;
                    }
                },

                mainClasses() {
                    const base = 'flex-1 transition-all duration-300';
                    if (this.isMobile) {
                        return `${base} ml-0 pt-20`;
                    }
                    const isChatShow = {{ request()->routeIs('chat.show') ? 'true' : 'false' }};
                    const topPad = isChatShow ? ' pt-0' : ' pt-6';
                    const bottomPad = isChatShow ? '' : ' pb-6';
                    const pad = topPad + bottomPad;
                    if (this.sidebarCollapsed) {
                        return `${base} ml-[68px]${pad}`;
                    }
                    return `${base} ml-[260px]${pad}`;
                },
            }
        }

        function initLucide() {
            lucide.createIcons();
        }

        document.addEventListener('DOMContentLoaded', initLucide);
        document.addEventListener('livewire:navigated', initLucide); 
    </script>

</body>

</html>
