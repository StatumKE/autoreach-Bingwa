<div class="flex flex-col gap-4 bg-app-bg min-h-screen p-4 pb-24 md:p-6">
    <div class="sticky top-3 z-20 w-full rounded-[1.5rem] bg-white p-2 shadow-sm ring-1 ring-zinc-200">
        <flux:navlist aria-label="{{ __('Settings') }}" class="flex gap-2 overflow-x-auto no-scrollbar">
            <flux:navlist.item :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate class="shrink-0 rounded-full px-4 py-3 text-[10px] font-black uppercase tracking-[0.2em] text-zinc-500 transition-colors hover:text-zinc-950 data-current:bg-green-50! data-current:text-zinc-950! data-current:border-green-100!">
                {{ __('Profile') }}
            </flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" :current="request()->routeIs('appearance.edit')" wire:navigate class="shrink-0 rounded-full px-4 py-3 text-[10px] font-black uppercase tracking-[0.2em] text-zinc-500 transition-colors hover:text-zinc-950 data-current:bg-green-50! data-current:text-zinc-950! data-current:border-green-100!">
                {{ __('Appearance') }}
            </flux:navlist.item>
        </flux:navlist>
    </div>

    <div class="w-full">
        <div class="app-kicker">{{ __('Account') }}</div>
        <flux:heading class="text-zinc-950 font-black tracking-tight text-[1.65rem] leading-none">{{ $heading ?? '' }}</flux:heading>
        <flux:subheading class="mt-1 text-zinc-500 font-medium text-sm leading-relaxed">{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full">
            {{ $slot }}
        </div>
    </div>
</div>
