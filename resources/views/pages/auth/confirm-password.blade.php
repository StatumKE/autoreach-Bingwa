<x-layouts::auth :title="__('Confirm password')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirm password')"
            :description="__('This is a secure area of the application. Please confirm your password before continuing.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full shadow-lg shadow-green-500/10" data-test="confirm-password-button" x-bind:disabled="submitting">
                <span x-cloak x-show="!submitting">{{ __('Confirm') }}</span>
                <span x-cloak x-show="submitting" class="inline-flex items-center justify-center gap-2">
                    <flux:icon.loading variant="mini" class="size-4" />
                    {{ __('Confirming…') }}
                </span>
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
