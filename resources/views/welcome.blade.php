<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <title>{{ __('Welcome') }} - {{ config('app.name', 'Laravel') }}</title>
    </head>
    <body class="nativephp-safe-area min-h-screen bg-app-bg text-zinc-950 dark:text-zinc-50 antialiased flex flex-col items-center justify-center p-6">
        <div class="w-full max-w-sm flex flex-col items-center gap-12">
            <!-- Logo Section -->
            <div class="flex flex-col items-center gap-4">
                <div class="h-20 w-20 flex items-center justify-center rounded-[1.5rem] bg-white shadow-sm shadow-green-500/10 border border-green-50 dark:bg-zinc-900 dark:border-zinc-800">
                    <x-app-logo-icon class="size-12 text-green-600 dark:text-green-400" />
                </div>
                <div class="text-center">
                    <h1 class="text-3xl font-black tracking-tighter text-zinc-900 dark:text-white uppercase">{{ config('app.name', 'Bingwa') }}</h1>
                    <p class="text-sm font-medium text-zinc-400 dark:text-zinc-500 tracking-widest uppercase mt-1">{{ __('Financial Bridge') }}</p>
                </div>
            </div>

            <!-- Content Card -->
            <div class="w-full bg-white rounded-[1.75rem] p-8 shadow-sm border border-zinc-100 dark:bg-zinc-900 dark:border-zinc-800">
                <div class="text-center mb-10">
                    <h2 class="text-xl font-bold text-zinc-900 dark:text-white">{{ __('Welcome Back') }}</h2>
                    <p class="text-sm text-zinc-500 mt-2">{{ __('Manage your USSD transactions and device settings with ease.') }}</p>
                </div>

                <div class="flex flex-col gap-4">
                    @auth
                        <flux:button :href="route('dashboard')" variant="primary" class="h-14 rounded-2xl font-black shadow-lg shadow-green-500/20" wire:navigate>
                            {{ __('Go to Dashboard') }}
                        </flux:button>
                    @else
                        <flux:button :href="route('login')" variant="primary" class="h-14 rounded-2xl font-black shadow-lg shadow-green-500/20" wire:navigate>
                            {{ __('Log In') }}
                        </flux:button>

                        @if (Route::has('register'))
                            <flux:button :href="route('register')" variant="ghost" class="h-14 rounded-2xl font-bold text-zinc-600 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:bg-zinc-800" wire:navigate>
                                {{ __('Create Account') }}
                            </flux:button>
                        @endif
                    @endauth
                </div>
            </div>

            <!-- Footer Section -->
            <div class="flex items-center gap-6 text-[10px] font-black uppercase tracking-[0.2em] text-zinc-400">
                <a href="#" class="hover:text-green-600 transition-colors">{{ __('Privacy') }}</a>
                <span class="h-1 w-1 rounded-full bg-zinc-300"></span>
                <a href="#" class="hover:text-green-600 transition-colors">{{ __('Terms') }}</a>
                <span class="h-1 w-1 rounded-full bg-zinc-300"></span>
                <a href="#" class="hover:text-green-600 transition-colors">{{ __('Support') }}</a>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist
    </body>
</html>
