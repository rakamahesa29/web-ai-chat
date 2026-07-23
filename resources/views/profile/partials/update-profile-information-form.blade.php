<section>
    <header>
        <h2 class="text-lg font-semibold text-hermes-text">
            {{ __('Profile Information') }}
        </h2>
        <p class="mt-1 text-sm text-hermes-muted">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <label for="name" class="block text-sm font-medium text-hermes-text">{{ __('Name') }}</label>
            <input id="name" name="name" type="text" class="hermes-input mt-1" value="{{ old('name', $user->name) }}" required autofocus autocomplete="name" />
            @error('name')
                <p class="mt-2 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-hermes-text">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" class="hermes-input mt-1" value="{{ old('email', $user->email) }}" required autocomplete="username" />
            @error('email')
                <p class="mt-2 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-hermes-muted">
                        {{ __('Your email address is unverified.') }}
                        <button form="send-verification" class="underline text-sm text-hermes-accent hover:text-indigo-400">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-hermes-success">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="hermes-btn-primary">{{ __('Save') }}</button>

            @if (session('status') === 'profile-updated')
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)" class="text-sm text-hermes-success">
                    {{ __('Saved.') }}
                </p>
            @endif
        </div>
    </form>
</section>
