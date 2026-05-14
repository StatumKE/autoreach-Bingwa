<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <style>
            body::after {
                content: "WEBVIEW ALIVE - TOP: " var(--inset-top, 'N/A');
                position: fixed;
                bottom: 20px;
                left: 20px;
                right: 20px;
                background: rgba(255, 255, 0, 0.8);
                color: black;
                text-align: center;
                z-index: 1000000;
                padding: 10px;
                border-radius: 10px;
                font-weight: bold;
                pointer-events: none;
            }
        </style>
    </head>
    <body class="nativephp-safe-area min-h-screen overflow-x-hidden bg-app-bg text-zinc-950 overscroll-y-none">
        @php
            $navigationLinks = [
                ['label' => __('Dashboard'), 'href' => route('dashboard')],
                ['label' => __('Offers'), 'href' => route('offers')],
                ['label' => __('Transactions'), 'href' => route('transactions')],
                ['label' => __('Subscriptions'), 'href' => route('plans')],
                ['label' => __('Quick Dial'), 'href' => route('quick-dials')],
                ['label' => __('Auto Renewals'), 'href' => route('auto-renewals')],
                ['label' => __('Auto Replies'), 'href' => route('auto-replies')],
                ['label' => __('Device settings'), 'href' => route('device.edit')],
            ];
        @endphp

        <flux:sidebar name="main" sticky class="hidden bg-app-drawer text-zinc-200 border-e border-white/5 lg:block">
            <flux:sidebar.header class="px-3 py-4">
                <div class="px-2">
                    <div class="text-sm font-black uppercase tracking-[0.28em] text-white">
                        {{ config('app.name') }}
                    </div>
                </div>
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Workspace')" class="grid text-zinc-400">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate class="app-nav-item">
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="tag" :href="route('offers')" :current="request()->routeIs('offers')" wire:navigate class="app-nav-item">
                        {{ __('Offers') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="arrows-right-left" :href="route('transactions')" :current="request()->routeIs('transactions')" wire:navigate class="app-nav-item">
                        {{ __('Transactions') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="book-open-text" :href="route('plans')" :current="request()->routeIs('plans')" wire:navigate class="app-nav-item">
                        {{ __('Subscriptions') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="phone" :href="route('quick-dials')" :current="request()->routeIs('quick-dials')" wire:navigate class="app-nav-item">
                        {{ __('Quick Dial') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="calendar-days" :href="route('auto-renewals')" :current="request()->routeIs('auto-renewals')" wire:navigate class="app-nav-item">
                        {{ __('Auto Renewals') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="sparkles" :href="route('auto-replies')" :current="request()->routeIs('auto-replies')" wire:navigate class="app-nav-item">
                        {{ __('Auto Replies') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="cog" :href="route('device.edit')" :current="request()->routeIs('device.edit')" wire:navigate class="app-nav-item">
                        {{ __('Device settings') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

        </flux:sidebar>

        @persist('mobile-nav')
            <div x-data="{ open: false }" x-on:keydown.escape.window="open = false" class="lg:hidden">
                <div
                    class="fixed inset-x-0 top-0 z-[9999] flex items-center gap-3 border-b border-black/10 bg-app-shell !px-3 text-white"
                    style="height: calc(112px + var(--inset-top, 0px)); padding-top: var(--inset-top, 0px);"
                >
                    <button
                        type="button"
                        class="app-mobile-menu-toggle !text-white z-[999999] translate-y-1"
                        x-on:click="open = true"
                        aria-controls="mobile-navigation-drawer"
                        :aria-expanded="open.toString()"
                        aria-label="{{ __('Open navigation menu') }}"
                    >
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" class="size-6">
                            <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-linecap="round" stroke-width="2.5" />
                        </svg>
                    </button>

                    <div class="min-w-0 flex-1">
                        <div class="text-[14px] font-black leading-none tracking-tight text-white sm:text-[15px]">
                            {{ config('app.name') }}
                        </div>
                    </div>
                </div>

                <div
                    x-cloak
                    x-show="open"
                    x-transition.opacity.duration.150ms
                    class="fixed inset-x-0 bottom-0 top-[calc(112px+var(--inset-top,0px))] z-[10000] lg:hidden"
                >
                    <div class="absolute inset-0 bg-black/60" x-on:click="open = false"></div>

                    <aside
                        id="mobile-navigation-drawer"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="-translate-x-full"
                        x-transition:enter-end="translate-x-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="translate-x-0"
                        x-transition:leave-end="-translate-x-full"
                        class="relative flex h-full w-[min(20rem,85vw)] flex-col overflow-y-auto bg-app-drawer text-zinc-100 shadow-2xl ring-1 ring-black/10"
                    >
                        <div class="flex items-center justify-between gap-4 border-b border-white/10 px-4 py-4">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-black leading-none tracking-tight text-white">
                                    {{ config('app.name') }}
                                </div>
                            </div>

                            <button
                                type="button"
                                class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-zinc-300 transition hover:bg-white/10 hover:text-white"
                                x-on:click="open = false"
                                aria-label="{{ __('Close navigation menu') }}"
                            >
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" class="size-5">
                                    <path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-linecap="round" stroke-width="2.5" />
                                </svg>
                            </button>
                        </div>

                        <nav class="flex-1 px-3 py-4">
                            <div class="mb-3 px-3 text-[10px] font-black uppercase tracking-[0.3em] text-zinc-400">
                                {{ __('Workspace') }}
                            </div>

                            <div class="grid gap-1">
                                @foreach ($navigationLinks as $link)
                                    <a
                                        href="{{ $link['href'] }}"
                                        wire:navigate
                                        class="app-nav-item flex items-center gap-3 px-4 py-3 text-sm font-semibold transition"
                                        x-on:click="open = false"
                                    >
                                        {{ $link['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </nav>

                        <div class="border-t border-white/10 p-4">
                            <div class="rounded-2xl bg-white/5 p-4">
                                <div class="text-sm font-bold text-white">{{ auth()->user()->name }}</div>
                                <div class="mt-1 text-xs text-zinc-400">{{ auth()->user()->email }}</div>
                            </div>

                            <div class="mt-3 grid gap-2">
                                <a
                                    href="{{ route('profile.edit') }}"
                                    wire:navigate
                                    class="app-nav-item flex items-center gap-3 px-4 py-3 text-sm font-semibold transition"
                                    x-on:click="open = false"
                                >
                                    {{ __('Settings') }}
                                </a>

                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="app-mobile-menu-toggle flex w-full justify-start gap-3 rounded-2xl bg-white/5 px-4 py-3 text-sm font-semibold text-zinc-100 transition hover:bg-white/10"
                                    >
                                        {{ __('Log out') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        @endpersist

        <div class="lg:pt-0" style="padding-top: calc(112px + var(--inset-top, 0px));">
            {{ $slot }}
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.hook('request', ({ fail }) => {
                fail(({ status, preventDefault }) => {
                    if (status === 419) {
                        preventDefault();
                        window.location.reload();
                    }
                });
            });
        });
    </script>

    @fluxScripts
</body>
</html>
