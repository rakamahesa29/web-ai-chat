<x-guest-layout>
    <div class="mb-8 text-center">
        <h1 class="text-2xl font-bold text-hermes-text">Create an account</h1>
        <p class="text-hermes-muted text-sm mt-1">Join Omoikane AI to start exploring</p>
    </div>

    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        <!-- Name -->
        <div>
            <label for="name" class="block text-sm font-medium text-hermes-text mb-1">Full Name</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" 
                   class="hermes-input" placeholder="John Doe">
            @error('name')
                <p class="mt-1 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror
        </div>

        <!-- Email Address -->
        <div>
            <label for="email" class="block text-sm font-medium text-hermes-text mb-1">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" 
                   class="hermes-input" placeholder="you@example.com">
            @error('email')
                <p class="mt-1 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror
        </div>

        <!-- Phone Number -->
        <div>
            <label for="phone" class="block text-sm font-medium text-hermes-text mb-1">Phone Number</label>
            <input id="phone" type="tel" name="phone_number" value="{{ old('phone_number') }}" required 
                   class="hermes-input" placeholder="+62...">
            @error('phone_number')
                <p class="mt-1 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror
        </div>

        <!-- Password -->
        <div>
            <label for="password" class="block text-sm font-medium text-hermes-text mb-1">Password</label>
            <input id="password" type="password" name="password" required autocomplete="new-password" 
                   class="hermes-input" placeholder="Create a password">
            @error('password')
                <p class="mt-1 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror
        </div>

        <!-- Confirm Password -->
        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-hermes-text mb-1">Confirm Password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" 
                   class="hermes-input" placeholder="Confirm your password">
            @error('password_confirmation')
                <p class="mt-1 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror
        </div>

        <p class="text-xs text-hermes-muted">
            By creating an account, you agree to our Terms of Service and Privacy Policy.
        </p>

        <button type="submit" class="w-full hermes-btn-primary py-3 justify-center">
            <i data-lucide="user-plus" class="w-4 h-4"></i>
            Create Account
        </button>

        <p class="text-center text-sm text-hermes-muted">
            Already have an account? 
            <a href="{{ route('login') }}" class="text-hermes-accent hover:text-indigo-400 transition font-medium">Sign in</a>
        </p>
    </form>
</x-guest-layout>
