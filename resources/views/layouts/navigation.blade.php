{{-- Sidebar Navigation — uses parent appShell() scope --}}
<div>

    {{-- ============================================ --}}
    {{-- MOBILE: Thin top bar with hamburger          --}}
    {{-- ============================================ --}}
    <div class="md:hidden fixed top-0 inset-x-0 z-30 h-12 bg-hermes-surface border-b border-hermes-border flex items-center px-3">
        <button @click="mobileOpen = !mobileOpen" class="hermes-btn-icon mr-2" aria-label="Toggle menu">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path :class="mobileOpen ? 'hidden' : 'block'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                <path :class="mobileOpen ? 'block' : 'hidden'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
        <a href="{{ request()->user() ? route('dashboard') : url('/') }}" class="flex items-center gap-2">
            <img src="{{ asset('img/32x32.png') }}" alt="Omoikane AI" class="w-7 h-7 rounded-lg">
            <span class="text-sm font-bold text-hermes-text">Omoikane AI</span>
        </a>

        {{-- Mobile right-side actions --}}
        <div class="ml-auto flex items-center gap-1">
            @auth
                <button onclick="toggleTheme()" class="hermes-btn-icon" title="Toggle theme">
                    <i data-lucide="sun" class="w-4 h-4 hidden dark:block"></i>
                    <i data-lucide="moon" class="w-4 h-4 block dark:hidden"></i>
                </button>
            @endauth
            @guest
                <a href="{{ route('login') }}" class="text-xs font-medium text-hermes-accent px-2">Login</a>
            @endguest
        </div>
    </div>

    {{-- ============================================ --}}
    {{-- MOBILE: Overlay                                 --}}
    {{-- ============================================ --}}
    <div x-show="mobileOpen" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="mobileOpen = false"
         class="sidebar-overlay"
         x-cloak>
    </div>

    {{-- ============================================ --}}
    {{-- SIDEBAR (shared between mobile & desktop)     --}}
    {{-- ============================================ --}}
    <aside 
        :class="{
            'sidebar sidebar-expanded': !sidebarCollapsed || mobileOpen,
            'sidebar sidebar-collapsed': sidebarCollapsed && !mobileOpen,
            '-translate-x-full': !mobileOpen,
            'translate-x-0': mobileOpen,
            'md:translate-x-0': true,
            'shadow-2xl md:shadow-none': mobileOpen,
        }"
        class="sidebar sidebar-expanded md:translate-x-0"
    >

        {{-- === TOP SECTION: Logo + Collapse Toggle === --}}
        <div class="flex items-center px-4 h-14 shrink-0"
             :class="sidebarCollapsed ? 'justify-center' : 'justify-between'">
            {{-- Logo --}}
            <a href="{{ request()->user() ? route('dashboard') : url('/') }}" 
               x-show="!sidebarCollapsed"
               class="flex items-center gap-3">
                <img src="{{ asset('img/32x32.png') }}" alt="Omoikane AI" class="w-8 h-8 rounded-lg shrink-0">
                <span class="sidebar-label text-lg font-bold text-hermes-text">Omoikane AI</span>
            </a>

            {{-- macOS-style collapse toggle --}}
            <button @click="toggleCollapse()" 
                    class="sidebar-collapse-btn"
                    :aria-label="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'">
                <i data-lucide="panel-left-close" x-show="!sidebarCollapsed" class="w-4 h-4"></i>
                <i data-lucide="panel-left-open" x-show="sidebarCollapsed" class="w-4 h-4"></i>
            </button>
        </div>

        {{-- Divider after logo --}}
        <div class="px-3 mb-2">
            <div class="border-t border-hermes-border"></div>
        </div>

        {{-- === NAV LINKS === --}}
        @auth
            <nav class="flex flex-col gap-0.5 px-1 flex-1 overflow-y-auto">
                <a href="{{ route('dashboard') }}" 
                   @click="mobileOpen = false"
                   class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <i data-lucide="layout-dashboard"></i>
                    <span>Dashboard</span>
                </a>
                <a href="{{ route('chat.index') }}" 
                   @click="mobileOpen = false"
                   class="sidebar-link {{ request()->routeIs('chat.*') ? 'active' : '' }}">
                    <i data-lucide="message-square"></i>
                    <span>Chat</span>
                </a>
                <a href="{{ route('brains.index') }}" 
                   @click="mobileOpen = false"
                   class="sidebar-link {{ request()->routeIs('brains.*') ? 'active' : '' }}">
                    <i data-lucide="brain"></i>
                    <span>AI Brain</span>
                </a>
            </nav>
        @endauth

        @guest
            <nav class="flex flex-col gap-0.5 px-1 flex-1 overflow-y-auto">
                <a href="/" @click="mobileOpen = false" class="sidebar-link {{ request()->is('/') ? 'active' : '' }}">
                    <i data-lucide="home"></i>
                    <span>Home</span>
                </a>
            </nav>
        @endguest

        {{-- === BOTTOM SECTION: Theme, Settings, Profile === --}}
        <div class="sidebar-bottom px-1">
            @auth
                {{-- Theme Toggle --}}
                <button onclick="toggleTheme()" class="sidebar-link w-[-webkit-fill-available]" title="Toggle theme">
                    <i data-lucide="sun-moon"></i>
                    <span x-show="!sidebarCollapsed">Theme</span>
                </button>

                {{-- Settings Dropdown --}}
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="sidebar-link w-[-webkit-fill-available] !py-2" title="Settings">
                        <i data-lucide="settings"></i>
                        <span x-show="!sidebarCollapsed">Settings</span>
                    </button>
                    {{-- Flyout to the right in collapsed mode, pop-up above in expanded mode --}}
                    <div x-show="open" 
                         @click.away="open = false"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         :class="sidebarCollapsed
                             ? 'absolute left-full top-0 ml-3 w-56 py-2 bg-hermes-card border border-hermes-border rounded-xl shadow-2xl z-50'
                             : 'absolute bottom-full left-3 right-3 mb-2 py-2 bg-hermes-card border border-hermes-border rounded-xl shadow-2xl z-50'"
                         x-cloak>
                        <div class="px-4 py-2 border-b border-hermes-border">
                            <p class="text-xs font-semibold text-hermes-muted uppercase tracking-wider">Settings</p>
                        </div>
                        <a href="{{ route('dashboard') }}" class="hermes-dropdown-item">
                            <i data-lucide="sliders" class="w-4 h-4"></i>
                            <span>AI Providers</span>
                        </a>
                        <a href="{{ route('brains.index') }}" class="hermes-dropdown-item">
                            <i data-lucide="database" class="w-4 h-4"></i>
                            <span>Knowledge Base</span>
                        </a>
                        <div class="border-t border-hermes-border my-1"></div>
                        <button onclick="toggleTheme()" class="hermes-dropdown-item w-full">
                            <i data-lucide="sun-moon" class="w-4 h-4"></i>
                            <span>Toggle Theme</span>
                        </button>
                    </div>
                </div>

                {{-- Profile Dropdown --}}
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" 
                            class="sidebar-link w-[-webkit-fill-available] !py-2" 
                            title="Profile">
                        <div class="w-7 h-7 bg-hermes-accent/20 rounded-full flex items-center justify-center shrink-0">
                            <span class="text-xs font-semibold text-hermes-accent">{{ substr(Auth::user()->name, 0, 1) }}</span>
                        </div>
                        <span x-show="!sidebarCollapsed" class="sidebar-user-info text-sm font-medium text-hermes-text truncate">
                            {{ Auth::user()->name }}
                        </span>
                    </button>
                    {{-- Flyout to the right in collapsed mode, pop-up above in expanded mode --}}
                    <div x-show="open" 
                         @click.away="open = false"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         :class="sidebarCollapsed w-[-webkit-fill-available]
                             ? 'absolute left-full bottom-0 ml-3 w-56 py-2 bg-hermes-card border border-hermes-border rounded-xl shadow-2xl z-50'
                             : 'absolute bottom-full left-3 right-3 mb-2 py-2 bg-hermes-card border border-hermes-border rounded-xl shadow-2xl z-50'"
                         x-cloak>
                        <div class="px-4 py-3 border-b border-hermes-border">
                            <p class="text-sm font-semibold text-hermes-text">{{ Auth::user()->name }}</p>
                            <p class="text-xs text-hermes-muted truncate">{{ Auth::user()->email }}</p>
                        </div>
                        <a href="{{ route('profile.edit') }}" class="hermes-dropdown-item">
                            <i data-lucide="user" class="w-4 h-4"></i>
                            <span>Profile</span>
                        </a>
                        <div class="border-t border-hermes-border my-1"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="hermes-dropdown-item w-full text-left text-hermes-danger hover:text-hermes-danger">
                                <i data-lucide="log-out" class="w-4 h-4"></i>
                                <span>Log Out</span>
                            </button>
                        </form>
                    </div>
                </div>
            @endauth

            @guest
                <div class="space-y-1">
                    <button onclick="toggleTheme()" class="sidebar-link" title="Toggle theme">
                        <i data-lucide="sun-moon"></i>
                        <span x-show="!sidebarCollapsed">Theme</span>
                    </button>
                    <a href="{{ route('login') }}" class="sidebar-link">
                        <i data-lucide="log-in"></i>
                        <span x-show="!sidebarCollapsed">Login</span>
                    </a>
                    <a href="{{ route('register') }}" class="sidebar-link active">
                        <i data-lucide="user-plus"></i>
                        <span x-show="!sidebarCollapsed">Join Now</span>
                    </a>
                </div>
            @endguest
        </div>
    </aside>
</div>

<script>
    function toggleTheme() {
        const html = document.documentElement;
        const isDark = html.classList.contains('dark');
        
        if (isDark) {
            html.classList.remove('dark');
            localStorage.setItem('theme', 'light');
        } else {
            html.classList.add('dark');
            localStorage.setItem('theme', 'dark');
        }

        if (window.lucide) lucide.createIcons();
    }
</script>
