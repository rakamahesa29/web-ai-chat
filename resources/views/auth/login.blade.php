<x-guest-layout>
    <div class="mb-8 text-center">
        <h1 class="text-2xl font-bold text-hermes-text">Welcome back</h1>
        <p class="text-hermes-muted text-sm mt-1">Sign in to continue to Omoikane AI</p>
    </div>

    <!-- Session Status -->
    @if (session('status'))
        <div class="mb-4 text-sm text-hermes-success bg-hermes-success/10 border border-hermes-success/30 rounded-lg p-3">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <!-- Email Address -->
        <div>
            <label for="email" class="block text-sm font-medium text-hermes-text mb-1">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" 
                   class="hermes-input" placeholder="you@example.com">
            @error('email')
                <p class="mt-1 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror
        </div>

        <!-- Password -->
        <div>
            <label for="password" class="block text-sm font-medium text-hermes-text mb-1">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password" 
                   class="hermes-input" placeholder="Enter your password">
            @error('password')
                <p class="mt-1 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror
        </div>

        <!-- Remember Me -->
        <div class="flex items-center justify-between">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" name="remember" 
                       class="w-4 h-4 rounded bg-hermes-surface border-hermes-border text-hermes-accent focus:ring-hermes-accent focus:ring-offset-hermes-bg">
                <span class="ms-2 text-sm text-hermes-muted">Remember me</span>
            </label>

            @if (Route::has('password.request'))
                <a class="text-sm text-hermes-accent hover:text-indigo-400 transition" href="{{ route('password.request') }}">
                    Forgot password?
                </a>
            @endif
        </div>

        <button type="submit" class="w-full hermes-btn-primary py-3 justify-center">
            <i data-lucide="log-in" class="w-4 h-4"></i>
            Sign in
        </button>

        <p class="text-center text-sm text-hermes-muted">
            Don't have an account? 
            <a href="{{ route('register') }}" class="text-hermes-accent hover:text-indigo-400 transition font-medium">Sign up</a>
        </p>
    </form>
</x-guest-layout>
