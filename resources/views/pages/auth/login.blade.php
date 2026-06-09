<x-layouts::auth :title="__('Log in')">
    <section class="auth-card flex flex-col gap-4">
        <span class="auth-badge">{{ __('Secure access') }}</span>

        <x-auth-header :title="__('Welcome back')" :description="__('Sign in to continue.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf

            <div class="flex flex-col gap-3">
                <!-- Email Address -->
                <flux:input
                    name="email"
                    :label="__('Email Address')"
                    :value="old('email')"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    :placeholder="__('Enter your email')"
                />

                <!-- Password -->
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Enter your password')"
                    viewable
                />
            </div>

            <div class="flex items-center justify-end -mt-2">
                @if (Route::has('password.request'))
                    <flux:link class="text-xs font-semibold text-green-600 hover:text-green-700 transition-colors" :href="route('password.request')">
                        {{ __('Forgot?') }}
                    </flux:link>
                @endif
            </div>

            <div>
                <flux:button
                    variant="primary"
                    type="submit"
                    class="auth-submit-button"
                    data-test="login-button"
                    x-bind:disabled="submitting"
                >
                    <span x-cloak x-show="!submitting">{{ __('Sign In') }}</span>
                    <span x-cloak x-show="submitting" class="inline-flex items-center justify-center gap-2">
                        <flux:icon.loading variant="mini" class="size-4" />
                        {{ __('Signing in…') }}
                    </span>
                </flux:button>
            </div>
        </form>

        <div class="mt-2 flex items-center justify-between text-sm font-medium text-zinc-500 dark:text-zinc-400">
            @if (Route::has('password.request'))
                <flux:link :href="route('password.request')" class="auth-link font-medium !no-underline">{{ __('Forgot password?') }}</flux:link>
            @endif
            @if (Route::has('register'))
                <flux:link :href="route('register')" class="auth-link font-medium !no-underline">{{ __('Create account') }}</flux:link>
            @endif
        </div>
    </section>
</x-layouts::auth>
