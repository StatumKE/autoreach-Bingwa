<?php

use App\Models\DeviceSetting;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Device settings')] class extends Component {
    public string $operator_identity = '';

    public string $primary_transaction_sim = 'slot_1';

    public string $sms_auto_reply_sim = 'slot_1';

    public bool $auto_reschedule_rejected = true;

    public string $retry_tomorrow_at = '12:30 AM';

    public string $ussd_timeout_seconds = '30';

    public bool $intelligent_auto_retry = true;

    public string $retry_interval_minutes = '1';

    public string $max_attempts = '2';

    public bool $retry_network_issues = true;

    public string $deviceId = 'Unknown';
    
    public string $deviceCode = 'Unknown';

    public string $osName = 'Android';

    public string $osVersion = 'Unknown';

    /**
     * Hydrate the form from the stored device settings.
     */
    public function mount(): void
    {
        $this->fetchNativeDeviceInfo();
        $deviceSetting = Auth::user()->deviceSetting;

        if ($deviceSetting === null) {
            $this->operator_identity = Auth::user()->name;

            return;
        }

        $this->operator_identity = $deviceSetting->operator_identity;
        $this->primary_transaction_sim = $deviceSetting->primary_transaction_sim;
        $this->sms_auto_reply_sim = $deviceSetting->sms_auto_reply_sim;
        $this->auto_reschedule_rejected = $deviceSetting->auto_reschedule_rejected;
        $this->retry_tomorrow_at = $deviceSetting->retry_tomorrow_at ?? $this->retry_tomorrow_at;
        $this->ussd_timeout_seconds = (string) $deviceSetting->ussd_timeout_seconds;
        $this->intelligent_auto_retry = $deviceSetting->intelligent_auto_retry;
        $this->retry_interval_minutes = (string) $deviceSetting->retry_interval_minutes;
        $this->max_attempts = (string) $deviceSetting->max_attempts;
        $this->retry_network_issues = $deviceSetting->retry_network_issues;
    }

    /**
     * Save the operator identity section.
     */
    public function saveOperatorIdentity(): void
    {
        $this->validate([
            'operator_identity' => ['required', 'string', 'max:120'],
        ]);

        $this->persistSettings();

        Flux::toast(variant: 'success', text: __('Operator identity saved.'));
    }

    /**
     * Save the SIM slot mapping section.
     */
    public function saveHardwareMapping(): void
    {
        $this->validate([
            'primary_transaction_sim' => ['required', Rule::in(array_keys($this->simSlotOptions()))],
            'sms_auto_reply_sim' => ['required', Rule::in(array_keys($this->simSlotOptions()))],
        ]);

        $this->persistSettings();

        Flux::toast(variant: 'success', text: __('Hardware mapping saved.'));
    }

    /**
     * Save the technical configuration section.
     */
    public function saveTechnicalConfig(): void
    {
        $this->validate([
            'auto_reschedule_rejected' => ['boolean'],
            'retry_tomorrow_at' => [
                Rule::requiredIf($this->auto_reschedule_rejected),
                'nullable',
                'string',
                Rule::in(array_keys($this->retryScheduleOptions())),
            ],
            'ussd_timeout_seconds' => ['required', 'integer', 'min:5', 'max:300'],
            'intelligent_auto_retry' => ['boolean'],
            'retry_interval_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'retry_network_issues' => ['boolean'],
        ]);

        $this->persistSettings();

        Flux::toast(variant: 'success', text: __('Technical config saved.'));
    }

    /**
     * Save all device settings.
     */
    public function save(): void
    {
        $this->saveOperatorIdentity();
        $this->saveHardwareMapping();
        $this->saveTechnicalConfig();
    }

    /**
     * Get the platform label shown in the device summary.
     */
    public function getPlatformLabelProperty(): string
    {
        if ($this->osVersion !== 'Unknown') {
            return trim("{$this->osName} {$this->osVersion}");
        }

        return $this->osName !== '' ? $this->osName : __('Android');
    }

    /**
     * Get the slot options for the hardware mapping cards.
     *
     * @return array<string, string>
     */
    public function simSlotOptions(): array
    {
        return [
            'slot_1' => __('Slot 1'),
            'slot_2' => __('Slot 2'),
        ];
    }



    /**
     * Get the retry schedule options.
     *
     * @return array<string, string>
     */
    public function retryScheduleOptions(): array
    {
        $options = [];

        for ($hour = 0; $hour < 24; $hour++) {
            foreach ([0, 30] as $minute) {
                $time = now()
                    ->copy()
                    ->startOfDay()
                    ->addHours($hour)
                    ->addMinutes($minute)
                    ->format('g:i A');

                $options[$time] = $time;
            }
        }

        return $options;
    }

    /**
     * Persist the current settings to the database.
     */
    private function persistSettings(): void
    {
        DeviceSetting::query()->updateOrCreate(
            ['user_id' => Auth::id()],
            [
                'operator_identity' => $this->operator_identity,
                'primary_transaction_sim' => $this->primary_transaction_sim,
                'sms_auto_reply_sim' => $this->sms_auto_reply_sim,
                'auto_reschedule_rejected' => $this->auto_reschedule_rejected,
                'retry_tomorrow_at' => $this->auto_reschedule_rejected ? $this->retry_tomorrow_at : null,
                'ussd_timeout_seconds' => (int) $this->ussd_timeout_seconds,
                'intelligent_auto_retry' => $this->intelligent_auto_retry,
                'retry_interval_minutes' => (int) $this->retry_interval_minutes,
                'max_attempts' => (int) $this->max_attempts,
                'retry_network_issues' => $this->retry_network_issues,
            ],
        );
    }

    /**
     * Trigger the sequential permission request flow via the native bridge.
     */
    public function requestPermissions(): void
    {
        if (! function_exists('nativephp_call')) {
            Flux::toast(variant: 'warning', text: __('Native features are not available in this environment.'));
            return;
        }

        nativephp_call('RequestSetupPermissions', json_encode([
            'force' => true,
            'openSpecialSettings' => true,
        ]));

        Flux::toast(text: __('Requesting permissions…'));
    }

    /**
     * Fetch native device hardware information.
     */
    private function fetchNativeDeviceInfo(): void
    {
        $user = Auth::user();
        $registration = $user->bingwaDeviceRegistration;
        
        $this->deviceId = $registration?->hardware_id 
            ?? $user->autoreach_connect_id 
            ?? 'Unknown';

        $this->deviceCode = $registration?->bhc_code ?? 'None';

        if (! function_exists('nativephp_call')) {
            return;
        }

        try {
            $idResponse = json_decode(nativephp_call('Device.GetId', '{}'), true);
            if (isset($idResponse['data']['id'])) {
                $this->deviceId = $idResponse['data']['id'];
            }

            $infoResponse = json_decode(nativephp_call('Device.GetInfo', '{}'), true);
            if (isset($infoResponse['data']['os_name'])) {
                $this->osName = $infoResponse['data']['os_name'];
            }
            if (isset($infoResponse['data']['os_version'])) {
                $this->osVersion = $infoResponse['data']['os_version'];
            }
        } catch (\Exception $e) {
            // Log or ignore
        }
    }
}; ?>

<section class="min-h-screen bg-app-bg px-4 pb-24 pt-3">
    <div class="flex flex-col gap-3">
        <div class="px-1">
            <div class="text-xl font-bold text-zinc-900">{{ __('Device Settings') }}</div>
        </div>

            <div class="grid grid-cols-2 gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-zinc-200 md:grid-cols-4">
                <div class="flex flex-col">
                    <span class="text-[8px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Hardware ID') }}</span>
                    <span class="mt-1 font-mono text-[10px] font-bold text-zinc-700 truncate">{{ $this->deviceId }}</span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[8px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Platform') }}</span>
                    <span class="mt-1 text-[11px] font-bold text-zinc-700">{{ $this->platformLabel }}</span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[8px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Device Code') }}</span>
                    <span class="mt-1 font-mono text-[11px] font-bold text-zinc-700 truncate">{{ $this->deviceCode }}</span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[8px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Power') }}</span>
                    <span class="mt-1 text-[11px] font-bold text-zinc-700">{{ __('AC Supply') }}</span>
                </div>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <article class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200 xl:col-span-1">
                <form wire:submit="saveOperatorIdentity">
                    <div class="flex items-center gap-4">
                        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-green-50 text-green-700 shadow-inner ring-1 ring-green-100">
                            <span class="text-lg font-black">{{ strtoupper(substr($this->operator_identity ?: Auth::user()->name, 0, 1)) }}</span>
                        </div>

                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">
                                {{ __('Operator identity') }}
                            </div>
                            <div class="mt-1 text-base font-bold text-zinc-900">{{ $this->operator_identity ?: Auth::user()->name }}</div>
                        </div>
                    </div>

                    <div class="mt-8 space-y-5">
                        <flux:input
                            wire:model="operator_identity"
                            :label="__('Operator display name')"
                            type="text"
                            required
                            placeholder="{{ __('Bob Mwenda') }}"
                        />

                        <div class="flex justify-end">
                            <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                        </div>
                    </div>
                </form>
            </article>

            <article class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200 xl:col-span-1">
                <div class="flex items-center gap-4">
                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-700 shadow-inner ring-1 ring-indigo-100">
                        <flux:icon.shield-check class="size-6" />
                    </div>

                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">
                            {{ __('Privacy & hardware') }}
                        </div>
                        <div class="mt-1 text-base font-bold text-zinc-900">{{ __('App Permissions') }}</div>
                    </div>
                </div>

                <div class="mt-8 space-y-4">
                    <p class="text-xs leading-relaxed text-zinc-500">
                        {{ __('Bingwa requires Phone, Contacts, and Notification permissions to automate USSD tasks and sync balances.') }}
                    </p>

                    <flux:button variant="ghost" class="h-12 w-full justify-center font-black uppercase tracking-widest text-[10px] !bg-zinc-50 hover:!bg-zinc-100 ring-1 ring-zinc-200" wire:click="requestPermissions" wire:loading.attr="disabled">
                        <flux:icon.hand-raised variant="mini" class="mr-2 size-4" />
                        {{ __('Check & Request All') }}
                    </flux:button>
                </div>
            </article>


            <article class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200 xl:col-span-2">
                <div>
                    <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">
                        {{ __('Radio & hardware') }}
                    </div>
                    <div class="mt-1 text-base font-bold text-zinc-900">{{ __('SIM Slot Mapping') }}</div>
                </div>

                <form wire:submit="saveHardwareMapping" class="mt-8 space-y-8">
                    <div class="space-y-4" x-data="{ sim: @entangle('primary_transaction_sim') }">
                        <div class="text-[10px] font-black uppercase tracking-widest text-green-600/70">
                            {{ __('Primary transaction SIM') }}
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($this->simSlotOptions() as $value => $label)
                                <label class="group relative flex cursor-pointer items-center justify-between rounded-2xl border px-6 py-5 transition active:scale-[0.98]"
                                    :class="sim === '{{ $value }}' ? 'border-green-500/50 bg-zinc-50 shadow-inner ring-4 ring-green-500/5' : 'border-zinc-200 bg-white'">
                                    <input x-model="sim" name="primary_transaction_sim" type="radio" class="sr-only" value="{{ $value }}">
                                    <div class="flex items-center gap-4">
                                        <div class="h-2.5 w-2.5 rounded-full transition-colors"
                                             :class="sim === '{{ $value }}' ? 'bg-green-400 shadow-[0_0_10px_rgba(52,211,153,0.6)]' : 'bg-zinc-300'"></div>
                                        <span class="text-base font-black transition-colors"
                                              :class="sim === '{{ $value }}' ? 'text-green-700' : 'text-zinc-950'">{{ $label }}</span>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-4" x-data="{ sim: @entangle('sms_auto_reply_sim') }">
                        <div class="text-[10px] font-black uppercase tracking-widest text-green-600/60">
                            {{ __('SMS auto-reply SIM') }}
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($this->simSlotOptions() as $value => $label)
                                <label class="group relative flex cursor-pointer items-center justify-between rounded-2xl border px-6 py-5 transition active:scale-[0.98]"
                                    :class="sim === '{{ $value }}' ? 'border-green-500/50 bg-zinc-50 shadow-inner ring-4 ring-green-500/5' : 'border-zinc-200 bg-white'">
                                    <input x-model="sim" name="sms_auto_reply_sim" type="radio" class="sr-only" value="{{ $value }}">
                                    <div class="flex items-center gap-4">
                                        <div class="h-2.5 w-2.5 rounded-full transition-colors"
                                             :class="sim === '{{ $value }}' ? 'bg-green-500 shadow-[0_0_10px_rgba(58,163,53,0.6)]' : 'bg-zinc-300'"></div>
                                        <span class="text-base font-black transition-colors"
                                              :class="sim === '{{ $value }}' ? 'text-green-700' : 'text-zinc-950'">{{ $label }}</span>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex justify-end pt-4 border-t border-zinc-100">
                        <flux:button type="submit" variant="primary">{{ __('Save SIM Mapping') }}</flux:button>
                    </div>
                </form>
            </article>
        </div>

        <article class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200">
            <div class="flex items-center gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-700 shadow-inner ring-1 ring-amber-100">
                    <flux:icon.command-line class="size-6" />
                </div>

                <div>
                    <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">
                        {{ __('Advanced job logic') }}
                    </div>
                    <div class="mt-1 text-base font-bold text-zinc-900">{{ __('Retry & resilience rules') }}</div>
                </div>
            </div>

            <form wire:submit="saveTechnicalConfig" class="mt-10 space-y-10">
                <div class="rounded-[1.5rem] bg-zinc-50 p-8 ring-1 ring-zinc-200 shadow-inner">
                    <div class="flex items-start justify-between gap-6">
                        <div>
                            <div class="text-[10px] font-black uppercase tracking-widest text-green-600/70">
                                {{ __('Auto reschedule rejected') }}
                            </div>
                            <div class="mt-3 text-sm font-medium text-zinc-500 leading-relaxed max-w-md">
                                {{ __('Automatically re-queue rejected offers for the next day to maintain payout flow.') }}
                            </div>
                        </div>

                        <label class="relative inline-flex cursor-pointer items-center">
                            <input wire:model.live="auto_reschedule_rejected" type="checkbox" class="peer sr-only">
                            <span class="h-8 w-14 rounded-full bg-zinc-100 transition peer-checked:bg-green-500 ring-1 ring-zinc-300"></span>
                            <span class="absolute left-1.5 top-1.5 h-5 w-5 rounded-full bg-white shadow-md transition peer-checked:translate-x-6"></span>
                        </label>
                    </div>

                    @if ($this->auto_reschedule_rejected)
                        <div class="mt-8 border-t border-zinc-200 pt-8">
                            <flux:select wire:model="retry_tomorrow_at" :label="__('Retry tomorrow at')">
                                @foreach ($this->retryScheduleOptions() as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    @endif
                </div>

                <div class="grid gap-8 md:grid-cols-2">
                    <flux:input wire:model="ussd_timeout_seconds" :label="__('USSD timeout (sec)')" type="number" min="5" max="300" step="1" required />

                    <div class="rounded-[1.5rem] bg-zinc-50 p-8 ring-1 ring-zinc-200 shadow-inner">
                        <div class="flex items-start justify-between gap-6">
                            <div>
                                <div class="text-[10px] font-black uppercase tracking-widest text-green-600/60">
                                    {{ __('Intelligent auto-retry') }}
                                </div>
                                <div class="mt-3 text-sm font-medium text-zinc-500 leading-relaxed">
                                    {{ __('Auto-recovery on transient network or hardware spikes.') }}
                                </div>
                            </div>

                            <label class="relative inline-flex cursor-pointer items-center">
                                <input wire:model.live="intelligent_auto_retry" type="checkbox" class="peer sr-only">
                                <span class="h-8 w-14 rounded-full bg-zinc-100 transition peer-checked:bg-green-500 ring-1 ring-zinc-300"></span>
                                <span class="absolute left-1.5 top-1.5 h-5 w-5 rounded-full bg-white shadow-md transition peer-checked:translate-x-6"></span>
                            </label>
                        </div>
                    </div>
                </div>

                @if ($this->intelligent_auto_retry)
                    <div class="grid gap-8 md:grid-cols-2">
                        <flux:input wire:model="retry_interval_minutes" :label="__('Interval (mins)')" type="number" min="1" max="60" step="1" required />
                        <flux:input wire:model="max_attempts" :label="__('Max attempts')" type="number" min="1" max="10" step="1" required />
                    </div>
                @endif

                <div class="rounded-[1.5rem] bg-zinc-50 p-8 ring-1 ring-zinc-200 shadow-inner">
                    <div class="flex items-start justify-between gap-6">
                        <div>
                            <div class="text-[10px] font-black uppercase tracking-widest text-amber-700/70">
                                {{ __('Retry network issues') }}
                            </div>
                            <div class="mt-3 text-sm font-medium text-zinc-500 leading-relaxed max-w-md">
                                {{ __('Aggressive recovery on local socket or connection failures.') }}
                            </div>
                        </div>

                        <label class="relative inline-flex cursor-pointer items-center">
                            <input wire:model.live="retry_network_issues" type="checkbox" class="peer sr-only">
                            <span class="h-8 w-14 rounded-full bg-zinc-100 transition peer-checked:bg-amber-500 ring-1 ring-zinc-300"></span>
                            <span class="absolute left-1.5 top-1.5 h-5 w-5 rounded-full bg-white shadow-md transition peer-checked:translate-x-6"></span>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end mt-4">
                    <flux:button type="submit" variant="primary" class="w-full md:w-auto">{{ __('Save Advanced Configuration') }}</flux:button>
                </div>
            </form>
        </article>
    </div>
</section>
