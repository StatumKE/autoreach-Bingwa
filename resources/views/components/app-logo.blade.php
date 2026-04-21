@props([
    'sidebar' => false,
    'href' => route('dashboard'),
])

@if($sidebar)
    <a
        href="{{ $href }}"
        {{ $attributes->merge(['class' => 'flex h-10 items-center gap-2 px-2 text-zinc-100 font-black tracking-tight']) }}
        data-flux-sidebar-brand
    >
        <span class="flex aspect-square size-9 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/10 shrink-0">
            <x-app-logo-icon class="size-5 fill-current text-zinc-100" />
        </span>

        <span class="text-sm leading-none whitespace-nowrap in-data-flux-sidebar-collapsed-desktop:hidden">
            {{ config('app.name') }}
        </span>
    </a>
@else
    <a
        href="{{ $href }}"
        {{ $attributes->merge(['class' => 'flex h-10 items-center gap-2 text-zinc-950 font-black tracking-tight']) }}
        data-flux-brand
    >
        <span class="flex aspect-square size-8 items-center justify-center rounded-xl bg-green-50 ring-1 ring-green-100 shrink-0">
            <x-app-logo-icon class="size-5 fill-current text-green-600" />
        </span>

        <span class="text-[14px] sm:text-[15px] leading-none whitespace-nowrap">
            {{ config('app.name') }}
        </span>
    </a>
@endif
