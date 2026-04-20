@props([
    'title',
    'description',
])

<div class="flex w-full flex-col text-center">
    <flux:heading size="xl" class="text-white font-black tracking-tight">{{ $title }}</flux:heading>
    <flux:subheading class="text-slate-500 font-medium mt-1">{{ $description }}</flux:subheading>
</div>
