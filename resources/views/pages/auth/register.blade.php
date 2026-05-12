<x-layouts::auth :title="__('Register')">
    <section class="auth-card flex flex-col gap-5 sm:gap-6">
        <span class="auth-badge">{{ __('Device setup') }}</span>

        <x-auth-header :title="__('Create account')" :description="__('Join Autoreach Bingwa in minutes.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if (!($deviceAlreadyRegistered ?? false))
            <form
                method="POST"
                action="{{ route('register.store') }}"
                class="flex flex-col gap-4 sm:gap-6"
                data-native-method="POST"
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
                
                <div class="flex flex-col gap-4">
                    <!-- Name -->
                    <div class="flex flex-col gap-1.5">
                        <input
                            name="name"
                            value="{{ old('name') }}"
                            type="text"
                            required
                            autofocus
                            autocomplete="name"
                            placeholder="{{ __('Full name') }}"
                            class="block w-full rounded-2xl border-zinc-200 bg-white px-4 py-3.5 text-sm font-medium focus:border-green-500 focus:ring-green-500 dark:border-zinc-800 dark:bg-zinc-900"
                        >
                        <flux:error name="name" />
                    </div>

                    <!-- Email Address -->
                    <div class="flex flex-col gap-1.5">
                        <input
                            name="email"
                            value="{{ old('email') }}"
                            type="email"
                            required
                            autocomplete="email"
                            placeholder="{{ __('Email Address') }}"
                            class="block w-full rounded-2xl border-zinc-200 bg-white px-4 py-3.5 text-sm font-medium focus:border-green-500 focus:ring-green-500 dark:border-zinc-800 dark:bg-zinc-900"
                        >
                        <flux:error name="email" />
                    </div>

                    <!-- Connect ID -->
                    <div class="flex flex-col gap-1.5">
                        <input
                            name="autoreach_connect_id"
                            value="{{ old('autoreach_connect_id') }}"
                            type="text"
                            required
                            autocomplete="off"
                            placeholder="{{ __('Autoreach Connect ID') }}"
                            class="block w-full rounded-2xl border-zinc-200 bg-white px-4 py-3.5 text-sm font-medium focus:border-green-500 focus:ring-green-500 dark:border-zinc-800 dark:bg-zinc-900"
                        >
                        <flux:error name="autoreach_connect_id" />
                    </div>

                    <!-- Password -->
                    <div class="flex flex-col gap-1.5">
                        <input
                            name="password"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="{{ __('Create Password') }}"
                            class="block w-full rounded-2xl border-zinc-200 bg-white px-4 py-3.5 text-sm font-medium focus:border-green-500 focus:ring-green-500 dark:border-zinc-800 dark:bg-zinc-900"
                        >
                        <flux:error name="password" />
                    </div>

                    <!-- Confirm Password -->
                    <div class="flex flex-col gap-1.5">
                        <input
                            name="password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="{{ __('Confirm Password') }}"
                            class="block w-full rounded-2xl border-zinc-200 bg-white px-4 py-3.5 text-sm font-medium focus:border-green-500 focus:ring-green-500 dark:border-zinc-800 dark:bg-zinc-900"
                        >
                        <flux:error name="password_confirmation" />
                    </div>
                </div>

                <div class="mt-2">
                    <button
                        type="submit"
                        class="w-full rounded-2xl bg-green-600 px-4 py-4 text-sm font-black uppercase tracking-widest text-white transition-all hover:bg-green-700 active:scale-[0.98]"
                        data-loading-text="Creating account..."
                        data-test="register-user-button"
                    >
                        {{ __('Create Account') }}
                    </button>
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
