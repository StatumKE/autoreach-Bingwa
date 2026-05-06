<?php

use App\Actions\Autoreach\RecoverBingwaDeviceToken;
use App\Concerns\ProfileValidationRules;
use App\Models\BingwaDeviceRegistration;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public string $autoreach_connect_id = '';
    public string $appVersion = '';
    public int $appVersionCode = 1;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->autoreach_connect_id = Auth::user()->autoreach_connect_id ?? '';
        $this->appVersion = (string) config('nativephp.app_version', 'DEBUG');
        $this->appVersionCode = (int) config('nativephp.app_version_code', 1);
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileUpdateRules());

        $user->name = $validated['name'];

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Recover the stored Bingwa device token from the backend.
     */
    public function recoverDeviceToken(): void
    {
        app(RecoverBingwaDeviceToken::class)->recover(Auth::user());

        Flux::toast(variant: 'success', text: __('Device token recovered.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function hasBingwaDeviceRegistration(): bool
    {
        return Auth::user()->bingwaDeviceRegistration instanceof BingwaDeviceRegistration;
    }
}; ?>

<section class="w-full">
    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and review your registered device details')">
        <form wire:submit="updateProfileInformation" class="rounded-xl bg-white w-full space-y-4 p-4 shadow-sm ring-1 ring-zinc-200 md:p-6">
                <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

                <div>
                    <flux:input
                        wire:model="email"
                        :label="__('Email')"
                        type="email"
                        autocomplete="email"
                        disabled
                        readonly
                    />

                    <flux:text class="mt-3 text-xs font-medium text-zinc-500">
                        {{ __('Email is linked to your device account and cannot be edited here.') }}
                    </flux:text>
                </div>

                <div>
                    <flux:input
                        wire:model="autoreach_connect_id"
                        :label="__('Autoreach Connect ID')"
                        type="text"
                        autocomplete="off"
                        disabled
                        readonly
                    />

                    <flux:text class="mt-3 text-xs font-medium text-zinc-500">
                        {{ __('Autoreach Connect ID is tied to the registered device and cannot be edited here.') }}
                    </flux:text>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <div class="text-[8px] font-bold uppercase tracking-widest text-zinc-500">{{ __('App version') }}</div>
                            <div class="mt-1 text-sm font-bold text-zinc-900">{{ $this->appVersion }}</div>
                        </div>

                        <div class="text-right">
                            <div class="text-[8px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Build number') }}</div>
                            <div class="mt-1 text-sm font-bold text-zinc-900">{{ $this->appVersionCode }}</div>
                        </div>
                    </div>

                    <flux:text class="mt-3 text-xs text-zinc-500">
                        {{ __('Use a higher build number for each APK release. For Play Store bundles, NativePHP can auto-increment during packaging.') }}
                    </flux:text>
                </div>

                <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:items-center">
                    <flux:button variant="ghost" type="submit" data-test="update-profile-button" class="app-primary-button w-full h-9 px-6 font-bold uppercase tracking-widest text-[10px] sm:w-auto" wire:loading.attr="disabled" wire:target="updateProfileInformation">
                        <span wire:loading.remove wire:target="updateProfileInformation">{{ __('Save Changes') }}</span>
                        <span wire:loading wire:target="updateProfileInformation" class="inline-flex items-center justify-center gap-2">
                            <flux:icon.loading variant="mini" class="size-3.5" />
                            {{ __('Saving…') }}
                        </span>
                    </flux:button>

                    @if ($this->hasBingwaDeviceRegistration)
                        <flux:button variant="ghost" type="button" wire:click="recoverDeviceToken" class="app-secondary-button w-full h-9 font-bold uppercase tracking-widest text-[10px] sm:w-auto" wire:loading.attr="disabled" wire:target="recoverDeviceToken">
                            <span wire:loading.remove wire:target="recoverDeviceToken">{{ __('Recover token') }}</span>
                            <span wire:loading wire:target="recoverDeviceToken" class="inline-flex items-center justify-center gap-2">
                                <flux:icon.loading variant="mini" class="size-3.5" />
                                {{ __('Recovering…') }}
                            </span>
                        </flux:button>
                    @endif
                </div>
        </form>

    </x-pages::settings.layout>
</section>
