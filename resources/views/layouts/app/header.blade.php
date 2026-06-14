<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="nativephp-safe-area min-h-screen overflow-x-hidden bg-app-bg text-zinc-950 dark:text-zinc-50 antialiased">
        <flux:header container="false" class="border-b border-black/10 bg-app-shell text-white dark:border-zinc-800 dark:bg-app-shell">
            <flux:sidebar.toggle class="lg:hidden mr-2 !text-white translate-y-1 [&_svg]:stroke-[2.5px]" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('dashboard') }}" wire:navigate class="text-white" />

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:navbar.item>

                <flux:navbar.item icon="tag" :href="route('offers')" :current="request()->routeIs('offers')" wire:navigate>
                    {{ __('Offers') }}
                </flux:navbar.item>

                <flux:navbar.item icon="arrows-right-left" :href="route('transactions')" :current="request()->routeIs('transactions')" wire:navigate>
                    {{ __('Transactions') }}
                </flux:navbar.item>

                <flux:navbar.item icon="book-open-text" :href="route('plans')" :current="request()->routeIs('plans')" wire:navigate>
                    {{ __('Subscriptions') }}
                </flux:navbar.item>

                <flux:navbar.item icon="phone" :href="route('quick-dials')" :current="request()->routeIs('quick-dials')" wire:navigate>
                    {{ __('Quick Dial') }}
                </flux:navbar.item>

                <flux:navbar.item icon="calendar-days" :href="route('auto-renewals')" :current="request()->routeIs('auto-renewals')" wire:navigate>
                    {{ __('Auto Renewals') }}
                </flux:navbar.item>

                <flux:navbar.item icon="sparkles" :href="route('auto-replies')" :current="request()->routeIs('auto-replies')" wire:navigate>
                    {{ __('Auto Replies') }}
                </flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            <flux:navbar class="me-1.5 space-x-0.5 rtl:space-x-reverse py-0!">
                <flux:tooltip :content="__('Search')" position="bottom">
                    <flux:navbar.item class="!h-10 [&>div>svg]:size-5" icon="magnifying-glass" href="#" :label="__('Search')" />
                </flux:tooltip>
                <flux:tooltip :content="__('Repository')" position="bottom">
                    <flux:navbar.item
                        class="h-10 max-lg:hidden [&>div>svg]:size-5"
                        icon="folder-git-2"
                        href="https://github.com/laravel/livewire-starter-kit"
                        target="_blank"
                        :label="__('Repository')"
                    />
                </flux:tooltip>
                <flux:tooltip :content="__('Documentation')" position="bottom">
                    <flux:navbar.item
                        class="h-10 max-lg:hidden [&>div>svg]:size-5"
                        icon="book-open-text"
                        href="https://laravel.com/docs/starter-kits#livewire"
                        target="_blank"
                        :label="__('Documentation')"
                    />
                </flux:tooltip>
            </flux:navbar>

            <x-desktop-user-menu />
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-white/5 bg-app-drawer text-zinc-200 dark:border-white/5 dark:bg-app-drawer">
            <flux:sidebar.header class="px-3 py-4">
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="text-zinc-300 hover:text-white in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Workspace')" class="grid text-zinc-400">
                    <flux:sidebar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate class="app-nav-item">
                        {{ __('Dashboard')  }}
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
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>
        </flux:sidebar>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist
    </body>
</html>
