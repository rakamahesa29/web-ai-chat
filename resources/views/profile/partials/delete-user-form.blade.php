<section class="space-y-6" x-data="{ showModal: false }">
    <header>
        <h2 class="text-lg font-semibold text-hermes-danger">
            {{ __('Delete Account') }}
        </h2>
        <p class="mt-1 text-sm text-hermes-muted">
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
        </p>
    </header>

    <button type="button" @click="showModal = true" class="hermes-btn bg-hermes-danger/10 text-hermes-danger hover:bg-hermes-danger/20">
        {{ __('Delete Account') }}
    </button>

    <!-- Delete Modal -->
    <div x-show="showModal" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70">
        
        <div @click.away="showModal = false" 
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="hermes-card p-6 max-w-md w-full">
            
            <form method="post" action="{{ route('profile.destroy') }}">
                @csrf
                @method('delete')

                <h2 class="text-lg font-semibold text-hermes-text">
                    {{ __('Are you sure you want to delete your account?') }}
                </h2>

                <p class="mt-2 text-sm text-hermes-muted">
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm.') }}
                </p>

                <div class="mt-6">
                    <label for="password" class="block text-sm font-medium text-hermes-text">{{ __('Password') }}</label>
                    <input id="password" name="password" type="password" class="hermes-input mt-1" placeholder="{{ __('Enter your password') }}" />
                    @error('password', 'userDeletion')
                        <p class="mt-2 text-sm text-hermes-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" @click="showModal = false" class="hermes-btn-ghost">
                        {{ __('Cancel') }}
                    </button>
                    <button type="submit" class="hermes-btn bg-hermes-danger text-white hover:bg-red-600">
                        {{ __('Delete Account') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</section>
