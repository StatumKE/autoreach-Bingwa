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

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->autoreach_connect_id = Auth::user()->autoreach_connect_id ?? '';
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
            <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
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

                    <flux:text class="mt-3 text-xs font-medium text-slate-500">
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

                    <flux:text class="mt-3 text-xs font-medium text-slate-500">
                        {{ __('Autoreach Connect ID is tied to the registered device and cannot be edited here.') }}
                    </flux:text>
                </div>

                <div class="flex items-center gap-4 pt-4">
                    <flux:button variant="primary" type="submit" data-test="update-profile-button" class="bg-indigo-600 hover:bg-indigo-500 text-white shadow-xl shadow-indigo-600/20 px-8 font-black uppercase tracking-widest text-[10px]">
                        {{ __('Save Changes') }}
                    </flux:button>

                    @if ($this->hasBingwaDeviceRegistration)
                        <flux:button variant="ghost" type="button" wire:click="recoverDeviceToken" class="text-slate-500 hover:text-teal-400 font-black uppercase tracking-widest text-[10px]">
                            {{ __('Recover token') }}
                        </flux:button>
                    @endif
                </div>
        </form>

    </x-pages::settings.layout>
</section>
