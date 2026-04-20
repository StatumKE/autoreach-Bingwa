<?php

use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadRecoveryCodes();
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $generateNewRecoveryCodes(auth()->user());

        $this->loadRecoveryCodes();
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            } catch (Exception) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }
}; ?>

<div
    class="py-8 space-y-6 border shadow-sm rounded-[2rem] border-zinc-100 bg-white dark:border-zinc-800 dark:bg-zinc-900"
    wire:cloak
    x-data="{ showRecoveryCodes: false }"
>
    <div class="px-8 space-y-2">
        <div class="flex items-center gap-2">
            <flux:icon.lock-closed variant="outline" class="size-4 text-emerald-600 dark:text-emerald-400"/>
            <flux:heading size="lg" level="3" class="font-black uppercase tracking-tight">{{ __('2FA recovery codes') }}</flux:heading>
        </div>
        <flux:text variant="subtle" class="font-medium text-zinc-500">
            {{ __('Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.') }}
        </flux:text>
    </div>

    <div class="px-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
            <flux:button
                x-show="!showRecoveryCodes"
                icon="eye"
                icon:variant="outline"
                variant="primary"
                @click="showRecoveryCodes = true;"
                class="shadow-lg shadow-emerald-500/10 rounded-2xl h-11"
                aria-expanded="false"
                aria-controls="recovery-codes-section"
            >
                {{ __('View recovery codes') }}
            </flux:button>

            <flux:button
                x-show="showRecoveryCodes"
                icon="eye-slash"
                icon:variant="outline"
                variant="primary"
                @click="showRecoveryCodes = false"
                class="shadow-lg shadow-emerald-500/10 rounded-2xl h-11"
                aria-expanded="true"
                aria-controls="recovery-codes-section"
            >
                {{ __('Hide recovery codes') }}
            </flux:button>

            @if (filled($recoveryCodes))
                <flux:button
                    x-show="showRecoveryCodes"
                    icon="arrow-path"
                    variant="ghost"
                    class="rounded-2xl h-11 text-zinc-500"
                    wire:click="regenerateRecoveryCodes"
                >
                    {{ __('Regenerate codes') }}
                </flux:button>
            @endif
        </div>

        <div
            x-show="showRecoveryCodes"
            x-transition
            id="recovery-codes-section"
            class="relative overflow-hidden"
            x-bind:aria-hidden="!showRecoveryCodes"
        >
            <div class="space-y-4">
                @error('recoveryCodes')
                    <flux:callout variant="danger" icon="x-circle" heading="{{$message}}"/>
                @enderror

                @if (filled($recoveryCodes))
                    <div
                        class="grid gap-2 p-6 font-mono text-xs rounded-2xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-100 dark:border-zinc-800 text-center"
                        role="list"
                        aria-label="{{ __('Recovery codes') }}"
                    >
                        @foreach($recoveryCodes as $code)
                            <div
                                role="listitem"
                                class="select-text text-zinc-600 dark:text-zinc-400 font-bold tracking-widest"
                                wire:loading.class="opacity-50 animate-pulse"
                            >
                                {{ $code }}
                            </div>
                        @endforeach
                    </div>
                    <flux:text variant="subtle" class="text-xs font-medium text-zinc-400">
                        {{ __('Each recovery code can be used once to access your account and will be removed after use.') }}
                    </flux:text>
                @endif
            </div>
        </div>
    </div>
</div>
