<div class="relative overflow-hidden rounded-[2.5rem] border border-emerald-50 bg-white p-5 text-zinc-900 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 md:p-8 mb-10">
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute -top-12 -right-12 h-36 w-36 rounded-full bg-emerald-500/5 blur-3xl"></div>
        <div class="absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-emerald-500/10 to-transparent"></div>
    </div>

    <div class="relative">
        <div class="text-[10px] font-black uppercase tracking-[0.3em] text-emerald-600 dark:text-emerald-400">
            {{ __('Account & System') }}
        </div>
        <flux:heading size="xl" class="mt-2 text-zinc-900 dark:text-white font-black uppercase tracking-tight">{{ __('Settings') }}</flux:heading>
        <flux:text class="mt-2 max-w-2xl text-zinc-500 dark:text-zinc-400 font-medium">
            {{ __('Manage your profile, device security, and platform appearance.') }}
        </flux:text>
    </div>
</div>

