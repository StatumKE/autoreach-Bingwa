<?php

use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public bool $requiresConfirmation;

    #[Locked]
    public string $qrCodeSvg = '';

    #[Locked]
    public string $manualSetupKey = '';

    public bool $showVerificationStep = false;

    public bool $setupComplete = false;

    #[Validate('required|string|size:6', onUpdate: false)]
    public string $code = '';

    /**
     * Mount the component.
     */
    public function mount(bool $requiresConfirmation): void
    {
        $this->requiresConfirmation = $requiresConfirmation;
    }

    #[On('start-two-factor-setup')]
    public function startTwoFactorSetup(): void
    {
        $enableTwoFactorAuthentication = app(EnableTwoFactorAuthentication::class);
        $enableTwoFactorAuthentication(auth()->user());

        $this->loadSetupData();
    }

    /**
     * Load the two-factor authentication setup data for the user.
     */
    private function loadSetupData(): void
    {
        $user = auth()->user()?->fresh();

        try {
            if (! $user || ! $user->two_factor_secret) {
                throw new Exception('Two-factor setup secret is not available.');
            }

            $this->qrCodeSvg = $user->twoFactorQrCodeSvg();
            $this->manualSetupKey = decrypt($user->two_factor_secret);
        } catch (Exception) {
            $this->addError('setupData', 'Failed to fetch setup data.');

            $this->reset('qrCodeSvg', 'manualSetupKey');
        }
    }

    /**
     * Show the two-factor verification step if necessary.
     */
    public function showVerificationIfNecessary(): void
    {
        if ($this->requiresConfirmation) {
            $this->showVerificationStep = true;

            $this->resetErrorBag();

            return;
        }

        $this->closeModal();
        $this->dispatch('two-factor-enabled');
    }

    /**
     * Confirm two-factor authentication for the user.
     */
    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        $this->validate();

        $confirmTwoFactorAuthentication(auth()->user(), $this->code);

        $this->setupComplete = true;

        $this->closeModal();

        $this->dispatch('two-factor-enabled');
    }

    /**
     * Reset two-factor verification state.
     */
    public function resetVerification(): void
    {
        $this->reset('code', 'showVerificationStep');

        $this->resetErrorBag();
    }

    /**
     * Close the two-factor authentication modal.
     */
    public function closeModal(): void
    {
        $this->reset(
            'code',
            'manualSetupKey',
            'qrCodeSvg',
            'showVerificationStep',
            'setupComplete',
        );

        $this->resetErrorBag();
    }

    /**
     * Get the current modal configuration state.
     */
    #[Computed]
    public function modalConfig(): array
    {
        if ($this->setupComplete) {
            return [
                'title' => __('Two-factor authentication enabled'),
                'description' => __('Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.'),
                'buttonText' => __('Close'),
            ];
        }

        if ($this->showVerificationStep) {
            return [
                'title' => __('Verify authentication code'),
                'description' => __('Enter the 6-digit code from your authenticator app.'),
                'buttonText' => __('Continue'),
            ];
        }

        return [
            'title' => __('Enable two-factor authentication'),
            'description' => __('To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app.'),
            'buttonText' => __('Continue'),
        ];
    }
}; ?>

<flux:modal
    name="two-factor-setup-modal"
    class="max-w-md md:min-w-md"
    @close="closeModal"
>
        <div class="space-y-6">
            <div class="flex flex-col items-center space-y-4">
                <div class="p-0.5 w-auto rounded-full border border-green-50 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm">
                    <div class="p-2.5 rounded-full border border-zinc-200 dark:border-zinc-700 overflow-hidden bg-zinc-50 dark:bg-zinc-800 relative">
                        <div class="flex items-stretch absolute inset-0 w-full h-full divide-x [&>div]:flex-1 divide-zinc-200 dark:divide-zinc-700 justify-around opacity-50">
                            @for ($i = 1; $i <= 5; $i++)
                                <div></div>
                            @endfor
                        </div>

                        <div class="flex flex-col items-stretch absolute w-full h-full divide-y [&>div]:flex-1 inset-0 divide-zinc-200 dark:divide-zinc-700 justify-around opacity-50">
                            @for ($i = 1; $i <= 5; $i++)
                                <div></div>
                            @endfor
                        </div>

                        <flux:icon.qr-code class="relative z-20 text-green-600 dark:text-green-400"/>
                    </div>
                </div>

                <div class="space-y-2 text-center">
                    <flux:heading size="lg" class="font-black uppercase tracking-tight">{{ $this->modalConfig['title'] }}</flux:heading>
                    <flux:text class="font-medium text-zinc-500">{{ $this->modalConfig['description'] }}</flux:text>
                </div>
            </div>

            @if ($showVerificationStep)
                <div class="space-y-6">
                    <div class="flex flex-col items-center space-y-3 justify-center">
                        <flux:otp
                            name="code"
                            wire:model="code"
                            length="6"
                            label="OTP Code"
                            label:sr-only
                            class="mx-auto"
                        />
                    </div>

                    <div class="flex items-center space-x-3">
                        <flux:button
                            variant="outline"
                            class="flex-1 rounded-2xl"
                            wire:click="resetVerification"
                        >
                            {{ __('Back') }}
                        </flux:button>

                        <flux:button
                            variant="primary"
                            class="flex-1 rounded-2xl shadow-lg shadow-green-500/10"
                            wire:click="confirmTwoFactor"
                            x-bind:disabled="$wire.code.length < 6"
                            wire:loading.attr="disabled"
                            wire:target="confirmTwoFactor"
                        >
                            <span wire:loading.remove wire:target="confirmTwoFactor">{{ __('Confirm') }}</span>
                            <span wire:loading wire:target="confirmTwoFactor" class="inline-flex items-center justify-center gap-2">
                                <flux:icon.loading variant="mini" class="size-4" />
                                {{ __('Confirming…') }}
                            </span>
                        </flux:button>
                    </div>
                </div>
            @else
                @error('setupData')
                    <flux:callout variant="danger" icon="x-circle" heading="{{ $message }}"/>
                @enderror

                <div class="flex justify-center">
                    <div class="relative w-64 overflow-hidden border rounded-[1.5rem] border-zinc-100 dark:border-zinc-800 aspect-square shadow-sm">
                        @empty($qrCodeSvg)
                            <div class="absolute inset-0 flex items-center justify-center bg-white dark:bg-zinc-800 animate-pulse">
                                <flux:icon.loading/>
                            </div>
                        @else
                            <div x-data class="flex items-center justify-center h-full p-6">
                                <div
                                    class="bg-white p-3 rounded-2xl"
                                    :style="($flux.appearance === 'dark' || ($flux.appearance === 'system' && $flux.dark)) ? 'filter: invert(1) brightness(1.5)' : ''"
                                >
                                    {!! $qrCodeSvg !!}
                                </div>
                            </div>
                        @endempty
                    </div>
                </div>

                <div>
                    <flux:button
                        :disabled="$errors->has('setupData')"
                        variant="primary"
                        class="w-full rounded-2xl shadow-lg shadow-green-500/10 h-12"
                        wire:click="showVerificationIfNecessary"
                        wire:loading.attr="disabled"
                        wire:target="showVerificationIfNecessary"
                    >
                        <span wire:loading.remove wire:target="showVerificationIfNecessary">{{ $this->modalConfig['buttonText'] }}</span>
                        <span wire:loading wire:target="showVerificationIfNecessary" class="inline-flex items-center justify-center gap-2">
                            <flux:icon.loading variant="mini" class="size-4" />
                            {{ __('Loading…') }}
                        </span>
                    </flux:button>
                </div>

                <div class="space-y-4">
                    <div class="relative flex items-center justify-center w-full">
                        <div class="absolute inset-0 w-full h-px top-1/2 bg-zinc-100 dark:bg-zinc-800"></div>
                        <span class="relative px-4 text-[10px] font-black uppercase tracking-widest bg-white dark:bg-zinc-900 text-zinc-400">
                            {{ __('Manual Entry') }}
                        </span>
                    </div>

                    <div
                        class="flex items-center space-x-2"
                        x-data="{
                            copied: false,
                            async copy() {
                                try {
                                    await navigator.clipboard.writeText('{{ $manualSetupKey }}');
                                    this.copied = true;
                                    setTimeout(() => this.copied = false, 1500);
                                } catch (e) {
                                    console.warn('Could not copy to clipboard');
                                }
                            }
                        }"
                    >
                        <div class="flex items-stretch w-full border border-zinc-100 rounded-2xl dark:border-zinc-800 overflow-hidden bg-zinc-50 dark:bg-zinc-800/50">
                            @empty($manualSetupKey)
                                <div class="flex items-center justify-center w-full p-3">
                                    <flux:icon.loading variant="mini"/>
                                </div>
                            @else
                                <input
                                    type="text"
                                    readonly
                                    value="{{ $manualSetupKey }}"
                                    class="w-full p-3 bg-transparent outline-none text-zinc-600 dark:text-zinc-400 font-mono text-xs text-center"
                                />

                                <button
                                    @click="copy()"
                                    class="px-4 transition-colors border-l cursor-pointer border-zinc-100 dark:border-zinc-800 hover:bg-white dark:hover:bg-zinc-800"
                                >
                                    <flux:icon.document-duplicate x-show="!copied" variant="outline" class="size-4 text-zinc-400"></flux:icon>
                                    <flux:icon.check
                                        x-show="copied"
                                        variant="solid"
                                        class="text-green-500 size-4"
                                    ></flux:icon>
                                </button>
                            @endempty
                        </div>
                    </div>
                </div>

            @endif
        </div>
</flux:modal>
