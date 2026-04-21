@props([
    'title',
    'description',
])

<div class="flex w-full flex-col text-center">
    <flux:heading size="xl" class="text-zinc-950 font-black tracking-tight">{{ $title }}</flux:heading>
    <flux:subheading class="text-zinc-500 font-medium mt-1">{{ $description }}</flux:subheading>
</div>
