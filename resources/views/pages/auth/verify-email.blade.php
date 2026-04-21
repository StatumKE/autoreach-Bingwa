<x-layouts::auth :title="__('Email verification')">
    <div class="mt-4 flex flex-col gap-6">
        <flux:text class="text-center">
            {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
        </flux:text>

        @if (session('status') == 'verification-link-sent')
            <flux:text class="text-center font-medium text-green-600 dark:text-green-400">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </flux:text>
        @endif

        <div class="flex flex-col items-center justify-between space-y-3">
            <form method="POST" action="{{ route('verification.send') }}" x-data="{ submitting: false }" x-on:submit="submitting = true">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full shadow-lg shadow-green-500/10" x-bind:disabled="submitting">
                    <span x-cloak x-show="!submitting">{{ __('Resend verification email') }}</span>
                    <span x-cloak x-show="submitting" class="inline-flex items-center justify-center gap-2">
                        <flux:icon.loading variant="mini" class="size-4" />
                        {{ __('Sending…') }}
                    </span>
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}" x-data="{ submitting: false }" x-on:submit="submitting = true">
                @csrf
                <flux:button variant="ghost" type="submit" class="text-sm cursor-pointer" data-test="logout-button" x-bind:disabled="submitting">
                    <span x-cloak x-show="!submitting">{{ __('Log out') }}</span>
                    <span x-cloak x-show="submitting" class="inline-flex items-center justify-center gap-2">
                        <flux:icon.loading variant="mini" class="size-4" />
                        {{ __('Logging out…') }}
                    </span>
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
