<x-layouts::auth :title="__('Reset password')">
    <section class="auth-card flex flex-col gap-6">
        <span class="auth-badge">{{ __('New credentials') }}</span>

        <x-auth-header :title="__('Create a new password')" :description="__('Choose a strong password to restore access to your account.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- Email Address -->
            <flux:input
                name="email"
                value="{{ request('email') }}"
                :label="__('Email')"
                type="email"
                required
                autocomplete="email"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="auth-submit-button" data-test="reset-password-button" x-bind:disabled="submitting">
                    <span x-cloak x-show="!submitting">{{ __('Reset password') }}</span>
                    <span x-cloak x-show="submitting" class="inline-flex items-center justify-center gap-2">
                        <flux:icon.loading variant="mini" class="size-4" />
                        {{ __('Resetting…') }}
                    </span>
                </flux:button>
            </div>
        </form>
    </section>
</x-layouts::auth>
