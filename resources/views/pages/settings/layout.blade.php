<div class="flex flex-col gap-3 bg-app-bg min-h-screen px-4 pb-24 pt-3">
    <div class="sticky top-3 z-20 w-full rounded-xl bg-white p-1.5 shadow-sm ring-1 ring-zinc-200">
        <flux:navlist aria-label="{{ __('Settings') }}" class="flex gap-2 overflow-x-auto no-scrollbar">
            <flux:navlist.item :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate class="shrink-0 rounded-xl px-4 py-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500 transition-colors hover:text-zinc-900 data-current:bg-green-50! data-current:text-zinc-900! data-current:border-green-100!">
                {{ __('Profile') }}
            </flux:navlist.item>
        </flux:navlist>
    </div>

    <div class="w-full">
        <div class="text-xl font-bold text-zinc-900">{{ $heading ?? '' }}</div>
        <div class="mt-1 text-sm text-zinc-500">{{ $subheading ?? '' }}</div>

        <div class="mt-4 w-full">
            {{ $slot }}
        </div>
    </div>

</div>
