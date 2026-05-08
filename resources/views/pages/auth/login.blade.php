<x-layouts::auth :title="__('Log in')">
    <section class="auth-card flex flex-col gap-6">
        <span class="auth-badge">{{ __('Secure access') }}</span>

        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0 text-green-600 hover:text-green-700 transition-colors" :href="route('password.request')">
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <p class="text-sm font-medium leading-relaxed text-zinc-500 dark:text-zinc-400">
                {{ __('You will stay signed in on this device until you log out.') }}
            </p>

            <div class="flex items-center justify-end">
                <flux:button
                    variant="primary"
                    type="submit"
                    class="auth-submit-button"
                    data-test="login-button"
                    x-bind:disabled="submitting"
                >
                    <span x-cloak x-show="!submitting">{{ __('Log in') }}</span>
                    <span x-cloak x-show="submitting" class="inline-flex items-center justify-center gap-2">
                        <flux:icon.loading variant="mini" class="size-4" />
                        {{ __('Logging in…') }}
                    </span>
                </flux:button>
            </div>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 text-center text-sm font-medium text-zinc-500 rtl:space-x-reverse dark:text-zinc-400">
                <span>{{ __('Don\'t have an account?') }}</span>
                <flux:link :href="route('register')" class="auth-link">{{ __('Sign up') }}</flux:link>
            </div>
        @endif
    </section>
</x-layouts::auth>
