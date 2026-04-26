@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-2">
    <flux:heading size="xl" class="text-3xl font-black leading-tight tracking-tight text-zinc-950 sm:text-4xl dark:text-white">{{ $title }}</flux:heading>
    <flux:subheading class="text-base font-semibold leading-snug text-zinc-500 sm:text-lg dark:text-zinc-400">{{ $description }}</flux:subheading>
</div>
