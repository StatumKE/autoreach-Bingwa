<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-app-bg text-zinc-950 dark:text-zinc-50 antialiased">
        <div class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="relative hidden h-full flex-col p-12 text-zinc-900 lg:flex border-e border-zinc-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="absolute inset-0 bg-white dark:bg-zinc-900"></div>
                <div class="absolute inset-x-0 top-0 h-px bg-green-500/20"></div>
                
                <a href="{{ route('home') }}" class="relative z-20 flex items-center gap-3 text-xl font-black uppercase tracking-tighter" wire:navigate>
                    <div class="h-12 w-12 flex items-center justify-center rounded-2xl bg-white shadow-sm shadow-green-500/10 border border-green-50 dark:bg-zinc-800 dark:border-zinc-700">
                        <x-app-logo-icon class="h-8 text-green-600 dark:text-green-400" />
                    </div>
                    {{ config('app.name', 'Bingwa') }}
                </a>

                @php
                    [$message, $author] = str(Illuminate\Foundation\Inspiring::quotes()->random())->explode('-');
                @endphp

                <div class="relative z-20 mt-auto">
                    <blockquote class="space-y-4">
                        <flux:heading size="xl" class="font-black tracking-tight leading-tight text-zinc-900 dark:text-white">&ldquo;{{ trim($message) }}&rdquo;</flux:heading>
                        <footer><flux:heading class="text-green-600 font-bold uppercase tracking-widest text-xs">{{ trim($author) }}</flux:heading></footer>
                    </blockquote>
                </div>
            </div>
            <div class="w-full lg:p-8">
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <a href="{{ route('home') }}" class="z-20 flex flex-col items-center gap-4 font-medium lg:hidden mb-6" wire:navigate>
                        <div class="h-16 w-16 flex items-center justify-center rounded-[1.5rem] bg-white shadow-sm shadow-green-500/10 border border-green-50 dark:bg-zinc-900 dark:border-zinc-800">
                            <x-app-logo-icon class="size-10 text-green-600 dark:text-green-400" />
                        </div>

                        <div class="text-center">
                            <h1 class="text-2xl font-black tracking-tighter text-zinc-900 dark:text-white uppercase">{{ config('app.name', 'Bingwa') }}</h1>
                        </div>
                    </a>
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist
    </body>
</html>
