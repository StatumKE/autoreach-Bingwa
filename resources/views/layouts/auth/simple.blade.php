<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="auth-layout nativephp-safe-area min-h-screen overflow-x-hidden bg-app-bg antialiased selection:bg-green-500/30 dark:bg-zinc-950">
        <div class="relative flex min-h-svh flex-col items-center justify-start gap-4 overflow-y-auto px-5 py-5 sm:justify-center sm:gap-6 md:p-10">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-52 bg-app-shell dark:bg-green-950/30"></div>
            <div class="pointer-events-none absolute inset-x-0 top-52 h-px bg-white/30"></div>

            <div class="relative flex w-full max-w-md flex-col gap-4">
                <a href="{{ route('home') }}" class="flex items-center gap-3 rounded-[1.75rem] bg-white/10 px-4 py-3 text-white ring-1 ring-white/10 backdrop-blur">
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-green-200">{{ __('Autoreach') }}</p>
                        <p class="truncate text-base font-black leading-tight">{{ __('Bingwa') }}</p>
                    </div>
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>
                <main class="flex flex-col gap-5 sm:gap-6">
                    {{ $slot }}
                </main>
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
