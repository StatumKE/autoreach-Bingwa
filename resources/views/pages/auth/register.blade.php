<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if (!($deviceAlreadyRegistered ?? false))
            <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6" x-data="{ submitting: false }" x-on:submit="submitting = true">
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
                    <flux:button
                        type="submit"
                        variant="primary"
                        class="w-full bg-green-600 hover:bg-green-500 text-white shadow-sm shadow-green-600/20 h-12 rounded-2xl font-black uppercase tracking-widest text-[10px]"
                        data-test="register-user-button"
                        x-bind:disabled="submitting"
                    >
                        <span x-cloak x-show="!submitting">{{ __('Create account') }}</span>
                        <span x-cloak x-show="submitting" class="inline-flex items-center justify-center gap-2">
                            <flux:icon.loading variant="mini" class="size-4" />
                            {{ __('Creating…') }}
                        </span>
                    </flux:button>
                </div>
            </form>
        @else
            <div class="rounded-[1.5rem] bg-green-500/10 p-6 ring-1 ring-green-500/20">
                <p class="font-black text-green-600 text-sm">
                    {{ __('This account is already registered on this device.') }}
                </p>
                <p class="mt-2 text-xs font-medium text-zinc-500 leading-relaxed">
                    {{ __('Use the APK on a new device to register another installation.') }}
                </p>
            </div>
        @endif

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-500 font-medium">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate class="text-green-600 hover:text-green-700 transition-colors font-black">{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
