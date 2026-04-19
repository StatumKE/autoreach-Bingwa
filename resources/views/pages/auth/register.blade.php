<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if (!($deviceAlreadyRegistered ?? false))
            <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
                @csrf
                <!-- Name -->
                <flux:input
                    name="name"
                    :label="__('Name')"
                    :value="old('name')"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    :placeholder="__('Full name')"
                />

                <!-- Email Address -->
                <flux:input
                    name="email"
                    :label="__('Email address')"
                    :value="old('email')"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="email@example.com"
                />

                <flux:input
                    name="autoreach_connect_id"
                    :label="__('Autoreach Connect ID')"
                    :value="old('autoreach_connect_id')"
                    type="text"
                    required
                    autocomplete="off"
                    :placeholder="__('Autoreach Connect ID')"
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
                    <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                        {{ __('Create account') }}
                    </flux:button>
                </div>
            </form>
        @else
            <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 px-4 py-5 text-sm leading-6 text-amber-50">
                <p class="font-medium text-amber-100">
                    {{ __('This account is already registered on this device.') }}
                </p>
                <p class="mt-2 text-amber-50/90">
                    {{ __('Use the APK on a new device to register another installation.') }}
                </p>
            </div>
        @endif

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
