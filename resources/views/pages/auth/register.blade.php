<x-layouts::auth :title="__('Register')">
    <section class="auth-card flex flex-col gap-4">
        <span class="auth-badge">{{ __('Device setup') }}</span>

        <x-auth-header :title="__('Create account')" :description="__('Join Autoreach Bingwa in minutes.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if (!($deviceAlreadyRegistered ?? false))
            <form
                method="POST"
                action="{{ route('register.store') }}"
                class="flex flex-col gap-4"
                data-native-method="POST"
                x-data="{ submitting: false }"
                x-on:submit="submitting = true"
            >
                @csrf

                @if ($errors->any())
                    <div class="rounded-2xl bg-red-500/10 p-4 ring-1 ring-red-500/20">
                        <ul class="list-disc list-inside text-xs font-black text-red-700 dark:text-red-400">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                
                <div class="flex flex-col gap-3">
                    <!-- Name -->
                    <flux:input
                        name="name"
                        :label="__('Full name')"
                        :value="old('name')"
                        type="text"
                        required
                        autofocus
                        autocomplete="name"
                        :placeholder="__('Enter your full name')"
                    />

                    <!-- Email Address -->
                    <flux:field>
                        <flux:label>{{ __('Email Address') }}</flux:label>
                        <div class="mb-2 text-xs font-medium text-zinc-500">
                            {{ __('Must be the email address used on your Autoreach Connect app.') }}
                        </div>
                        <flux:input
                            name="email"
                            :value="old('email')"
                            type="email"
                            required
                            autocomplete="email"
                            class="placeholder:text-xs"
                            :placeholder="__('Enter the email used on Autoreach Connect app')"
                        />
                    </flux:field>

                    <!-- Connect ID -->
                    <flux:field>
                        <flux:label>{{ __('Autoreach Connect ID') }}</flux:label>
                        <div class="mb-2 text-xs font-medium text-zinc-500">
                            {{ __('Enter the Connect ID from your Autoreach Connect App.') }}
                        </div>
                        <flux:input
                            name="autoreach_connect_id"
                            :value="old('autoreach_connect_id')"
                            type="text"
                            required
                            autocomplete="off"
                            class="placeholder:text-xs"
                            :placeholder="__('Enter your Connect ID from Autoreach Connect')"
                        />
                    </flux:field>

                    <!-- Password -->
                    <flux:input
                        name="password"
                        :label="__('Password')"
                        type="password"
                        required
                        autocomplete="new-password"
                        :placeholder="__('Create Password')"
                        viewable
                    />

                    <!-- Confirm Password -->
                    <flux:input
                        name="password_confirmation"
                        :label="__('Confirm Password')"
                        type="password"
                        required
                        autocomplete="new-password"
                        :placeholder="__('Confirm Password')"
                        viewable
                    />
                </div>

                <div>
                    <flux:button
                        variant="primary"
                        type="submit"
                        class="auth-submit-button"
                        data-test="register-user-button"
                        x-bind:disabled="submitting"
                    >
                        <span x-cloak x-show="!submitting">{{ __('Create Account') }}</span>
                        <span x-cloak x-show="submitting" class="inline-flex items-center justify-center gap-2">
                            <flux:icon.loading variant="mini" class="size-4" />
                            {{ __('Creating account…') }}
                        </span>
                    </flux:button>
                </div>
            </form>
        @else
            <div class="rounded-[1.5rem] bg-green-500/10 p-6 ring-1 ring-green-500/20">
                <p class="text-sm font-black text-green-700 dark:text-green-300">
                    {{ __('This account is already registered on this device.') }}
                </p>
                <p class="mt-2 text-xs font-medium leading-relaxed text-zinc-500 dark:text-zinc-400">
                    {{ __('Use the APK on a new device to register another installation.') }}
                </p>
            </div>
        @endif

        <div class="mt-2 space-x-1 text-center text-sm font-medium text-zinc-500 rtl:space-x-reverse dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" class="auth-link !no-underline">{{ __('Login') }}</flux:link>
        </div>
    </section>
</x-layouts::auth>
