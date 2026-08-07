<section>
    <header>
        <h2 class="text-lg font-semibold text-hermes-text">
            {{ __('Update Password') }}
        </h2>
        <p class="mt-1 text-sm text-hermes-muted">
            {{ __('Ensure your account is using a long, random password to stay secure.') }}
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('put')

        <div>
            <label for="update_password_current_password" class="block text-sm font-medium text-hermes-text">{{ __('Current Password') }}</label>
            <input id="update_password_current_password" name="current_password" type="password" class="hermes-input mt-1" autocomplete="current-password" />
            @error('current_password', 'updatePassword')
                <p class="mt-2 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="update_password_password" class="block text-sm font-medium text-hermes-text">{{ __('New Password') }}</label>
            <input id="update_password_password" name="password" type="password" class="hermes-input mt-1" autocomplete="new-password" />
            @error('password', 'updatePassword')
                <p class="mt-2 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="update_password_password_confirmation" class="block text-sm font-medium text-hermes-text">{{ __('Confirm Password') }}</label>
            <input id="update_password_password_confirmation" name="password_confirmation" type="password" class="hermes-input mt-1" autocomplete="new-password" />
            @error('password_confirmation', 'updatePassword')
                <p class="mt-2 text-sm text-hermes-danger">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="hermes-btn-primary">{{ __('Save') }}</button>

            @if (session('status') === 'password-updated')
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)" class="text-sm text-hermes-success">
                    {{ __('Saved.') }}
                </p>
            @endif
        </div>
    </form>
</section>
