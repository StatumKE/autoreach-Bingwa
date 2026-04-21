<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="nativephp-safe-area min-h-screen overflow-x-hidden bg-app-bg antialiased dark:bg-zinc-950">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-10">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-4 font-medium" wire:navigate>
                    <div class="h-16 w-16 flex items-center justify-center rounded-[1.5rem] bg-white shadow-sm shadow-green-500/10 border border-green-50 dark:bg-zinc-900 dark:border-zinc-800">
                        <x-app-logo-icon class="size-10 text-green-600 dark:text-green-400" />
                    </div>

                    <div class="text-center">
                        <h1 class="text-2xl font-black tracking-tighter text-zinc-900 dark:text-white uppercase">{{ config('app.name', 'Bingwa') }}</h1>
                    </div>
                </a>

                <div class="flex flex-col gap-6">
                    <div class="rounded-[1.75rem] border border-zinc-100 bg-white dark:bg-zinc-900 dark:border-zinc-800 text-zinc-900 shadow-sm">
                        <div class="px-8 py-10">{{ $slot }}</div>
                    </div>
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
