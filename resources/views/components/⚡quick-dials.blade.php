<?php

use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Quick Dials')] class extends Component {
    public string $selectedPhone = '';

    public string $selectedName = '';

    /**
     * Select a phone number from the search results.
     */
    public function selectContact(string $phone, string $name = ''): void
    {
        $this->selectedPhone = trim($phone);
        $this->selectedName = trim($name);
    }

    /**
     * Clear the selected contact.
     */
    public function clearSelectedContact(): void
    {
        $this->selectedPhone = '';
        $this->selectedName = '';
    }

    #[Computed]
    public function hasSelection(): bool
    {
        return $this->selectedPhone !== '';
    }

    #[Computed]
    public function dialUri(): string
    {
        return $this->selectedPhone !== '' ? 'tel:'.$this->selectedPhone : '#';
    }
}; ?>

<section class="w-full p-4 md:p-6" x-data="contactPicker()">
    <div class="flex flex-col gap-4">
        <div class="relative overflow-hidden rounded-3xl border border-emerald-800 bg-gradient-to-br from-emerald-950 via-emerald-900 to-zinc-900 p-5 text-white shadow-lg dark:border-emerald-700 md:p-6">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -top-12 -right-12 h-36 w-36 rounded-full bg-emerald-400/10 blur-3xl motion-safe:animate-pulse"></div>
                <div class="absolute -bottom-16 -left-12 h-40 w-40 rounded-full bg-zinc-400/10 blur-3xl motion-safe:animate-pulse" style="animation-delay: 240ms;"></div>
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent"></div>
            </div>

            <div class="relative flex items-start justify-between gap-4">
                <div>
                    <div class="text-[10px] font-bold uppercase tracking-[0.3em] text-emerald-300/80">
                        {{ __('Quick Dial') }}
                    </div>
                    <flux:heading size="xl" class="mt-2 text-white">{{ __('Phone Book') }}</flux:heading>
                    <flux:text class="mt-2 max-w-2xl text-emerald-100/80">
                        {{ __('Search your device contacts to quickly perform manual dials or transfers.') }}
                    </flux:text>
                </div>
            </div>
        </div>

        <div class="rounded-3xl border border-zinc-100 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/50 text-center flex flex-col items-center justify-center min-h-[40vh]">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                <flux:icon.users class="h-8 w-8" />
            </div>
            
            <flux:heading size="lg" class="mt-4 text-zinc-900 dark:text-zinc-100">{{ __('Open Phone Book') }}</flux:heading>
            <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400 max-w-xs">
                {{ __('Securely browse your native contacts and select a customer.') }}
            </flux:text>

            <div class="mt-8 w-full max-w-xs">
                <flux:button variant="primary" type="button" @click="openPicker" class="w-full h-12 rounded-2xl text-base shadow-lg shadow-emerald-500/20" x-text="buttonText">
                    {{ __('Open Phone Book') }}
                </flux:button>
            </div>
            
            <p x-show="error" x-text="error" style="display: none;" class="mt-4 text-sm text-red-500 dark:text-red-400"></p>
        </div>

        @if ($this->hasSelection)
            <div class="sticky bottom-4 z-20 rounded-3xl border border-zinc-200 bg-white p-4 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-xs font-semibold uppercase tracking-[0.3em] text-zinc-500 dark:text-zinc-400">
                            {{ __('Selected') }}
                        </div>
                        <div class="truncate text-base font-semibold text-zinc-950 dark:text-zinc-50">
                            {{ $this->selectedName !== '' ? $this->selectedName : $this->selectedPhone }}
                        </div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $this->selectedPhone }}
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button variant="ghost" type="button" wire:click="clearSelectedContact">
                            {{ __('Clear') }}
                        </flux:button>

                        <flux:button variant="primary" type="button" :href="$this->dialUri" wire:navigate>
                            {{ __('Dial') }}
                        </flux:button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('contactPicker', () => ({
                error: '',
                buttonText: 'Open Phone Book',
                
                async openPicker() {
                    const supported = ('contacts' in navigator && 'ContactsManager' in window);
                    
                    if (!supported) {
                        this.error = 'Your device or browser does not support the native Web Contact Picker API.';
                        return;
                    }
                    
                    try {
                        this.buttonText = 'Opening...';
                        const props = ['name', 'tel'];
                        const opts = { multiple: false };
                        
                        const contacts = await navigator.contacts.select(props, opts);
                        
                        if (contacts.length > 0) {
                            const name = (contacts[0].name && contacts[0].name.length > 0) ? contacts[0].name[0] : '';
                            let phone = (contacts[0].tel && contacts[0].tel.length > 0) ? contacts[0].tel[0] : '';
                            
                            // Clean phone number: remove spaces and dashes
                            phone = phone.replace(/[\s-]/g, '');
                            
                            this.$wire.selectContact(phone, name);
                        }
                    } catch (ex) {
                        console.error(ex);
                        // Handle user cancellation gracefully
                        if (ex.name !== 'NotAllowedError') {
                            this.error = 'An error occurred while opening the contacts: ' + ex.message;
                        }
                    } finally {
                        this.buttonText = 'Open Phone Book';
                    }
                }
            }));
        });
    </script>
</section>
