<x-layouts::auth :title="__('Forgot password')">
    <section class="auth-card flex flex-col gap-6">
        <span class="auth-badge">{{ __('Account recovery') }}</span>

        <x-auth-header :title="__('Reset your password')" :description="__('Enter your email and we will send a secure reset link.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
            />

            <flux:button variant="primary" type="submit" class="auth-submit-button" data-test="email-password-reset-link-button" x-bind:disabled="submitting">
                <span x-cloak x-show="!submitting">{{ __('Email password reset link') }}</span>
                <span x-cloak x-show="submitting" class="inline-flex items-center justify-center gap-2">
                    <flux:icon.loading variant="mini" class="size-4" />
                    {{ __('Sending…') }}
                </span>
            </flux:button>
        </form>

        <div class="space-x-1 text-center text-sm font-medium text-zinc-500 rtl:space-x-reverse dark:text-zinc-400">
            <span>{{ __('Or, return to') }}</span>
            <flux:link :href="route('login')" wire:navigate class="auth-link">{{ __('log in') }}</flux:link>
        </div>
    </section>
</x-layouts::auth>
