@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name')" {{ $attributes->merge(['class' => 'text-white font-black']) }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-xl bg-slate-950 ring-1 ring-slate-800">
            <x-app-logo-icon class="size-5 fill-current text-teal-400" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name')" {{ $attributes->merge(['class' => 'text-white font-black']) }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-xl bg-slate-950 ring-1 ring-slate-800">
            <x-app-logo-icon class="size-5 fill-current text-teal-400" />
        </x-slot>
    </flux:brand>
@endif
