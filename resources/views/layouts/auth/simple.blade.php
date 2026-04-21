<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="nativephp-safe-area min-h-screen overflow-x-hidden bg-app-bg antialiased selection:bg-green-500/30">
        <div class="relative flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-green-500/20"></div>

            <div class="relative flex w-full max-w-sm flex-col gap-4">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <div class="flex h-16 w-16 mb-2 items-center justify-center rounded-[1.5rem] bg-white shadow-sm ring-1 ring-zinc-200">
                        <x-app-logo-icon class="size-10 fill-current text-green-600" />
                    </div>
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>
                <div class="flex flex-col gap-6">
                    {{ $slot }}
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
