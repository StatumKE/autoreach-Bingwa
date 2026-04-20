<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="nativephp-safe-area min-h-screen overflow-x-hidden bg-slate-950 antialiased selection:bg-teal-500/30">
        <div class="relative flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <!-- Background Decoration -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-[20%] -left-[10%] h-[50%] w-[50%] rounded-full bg-teal-500/10 blur-[120px]"></div>
                <div class="absolute -bottom-[20%] -right-[10%] h-[50%] w-[50%] rounded-full bg-indigo-500/10 blur-[120px]"></div>
            </div>

            <div class="relative flex w-full max-w-sm flex-col gap-4">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <div class="flex h-16 w-16 mb-2 items-center justify-center rounded-[1.5rem] bg-slate-900 shadow-2xl ring-1 ring-slate-800">
                        <x-app-logo-icon class="size-10 fill-current text-teal-400" />
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
