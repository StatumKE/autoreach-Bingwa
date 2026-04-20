<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="nativephp-safe-area min-h-screen overflow-x-hidden bg-slate-950 text-slate-100 overscroll-y-none touch-manipulation">
        <flux:sidebar sticky collapsible="mobile" class="bg-slate-900 border-e border-slate-800">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid text-teal-400/50">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate class="text-slate-300 hover:text-white">
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="tag" :href="route('offers')" :current="request()->routeIs('offers')" wire:navigate class="text-slate-300 hover:text-white">
                        {{ __('Offers') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="arrows-right-left" :href="route('transactions')" :current="request()->routeIs('transactions')" wire:navigate class="text-slate-300 hover:text-white">
                        {{ __('Transactions') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="book-open-text" :href="route('plans')" :current="request()->routeIs('plans')" wire:navigate class="text-slate-300 hover:text-white">
                        {{ __('Plans') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="phone" :href="route('quick-dials')" :current="request()->routeIs('quick-dials')" wire:navigate class="text-slate-300 hover:text-white">
                        {{ __('Quick Dial') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="cog" :href="route('device.edit')" :current="request()->routeIs('device.edit')" wire:navigate class="text-slate-300 hover:text-white">
                        {{ __('Device settings') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden !px-4 bg-slate-950 border-b border-slate-900" container="false">
            <flux:sidebar.toggle class="lg:hidden text-teal-400" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
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

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
