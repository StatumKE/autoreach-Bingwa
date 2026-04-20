<div class="flex items-start max-md:flex-col bg-slate-950 min-h-screen p-6 md:p-10">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate class="text-slate-400 hover:text-white transition-colors">{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate class="text-slate-400 hover:text-white transition-colors">{{ __('Appearance') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator vertical class="max-md:hidden bg-slate-900" />
    <flux:separator class="md:hidden bg-slate-900" />

    <div class="flex-1 self-stretch max-md:pt-8 md:ps-12">
        <flux:heading class="text-white font-black tracking-tight text-2xl">{{ $heading ?? '' }}</flux:heading>
        <flux:subheading class="text-slate-500 font-medium mt-1">{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-8 w-full max-w-3xl">
            {{ $slot }}
        </div>
    </div>
</div>
