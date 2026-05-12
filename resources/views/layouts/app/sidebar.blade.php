<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="nativephp-safe-area min-h-screen overflow-x-hidden bg-app-bg text-zinc-950 overscroll-y-none touch-manipulation">
        <flux:sidebar name="main" sticky collapsible="mobile" class="bg-app-drawer text-zinc-200 border-e border-white/5">
            <flux:sidebar.header class="px-3 py-4">
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden text-zinc-300 hover:text-white" />
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

        <!-- Mobile User Menu -->
        <flux:header class="fixed inset-x-0 top-0 z-50 h-24 items-center bg-app-shell pt-8 text-white border-b border-black/10 !px-2 lg:hidden" container="false">
            <flux:button variant="subtle" icon="bars-3" class="app-mobile-menu-toggle !text-white lg:hidden [&_svg]:size-7 [&_svg]:stroke-[2.5px] z-[100] relative pointer-events-auto" x-on:click="$flux.sidebar('main').toggle()" />

            <div class="min-w-0 flex-1 ps-2">
                <div class="text-[14px] font-black leading-none tracking-tight text-white sm:text-[15px]">
                    {{ config('app.name') }}
                </div>
            </div>

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                    class="text-white"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <div class="pt-24 lg:pt-0">
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
