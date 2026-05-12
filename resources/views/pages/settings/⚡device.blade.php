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

        $this->operator_identity = $deviceSetting->operator_identity ?? Auth::user()->name;
        $this->primary_transaction_sim = $deviceSetting->primary_transaction_sim ?? 'slot_1';
        $this->sms_auto_reply_sim = $deviceSetting->sms_auto_reply_sim ?? 'slot_1';
        $this->auto_reschedule_rejected = (bool) $deviceSetting->auto_reschedule_rejected;
        $this->retry_tomorrow_at = $deviceSetting->retry_tomorrow_at ?? '12:30 AM';
        $this->ussd_timeout_seconds = (string) ($deviceSetting->ussd_timeout_seconds ?: 30);
        $this->intelligent_auto_retry = (bool) $deviceSetting->intelligent_auto_retry;
        $this->retry_interval_minutes = (string) ($deviceSetting->retry_interval_minutes ?: 1);
        $this->max_attempts = (string) ($deviceSetting->max_attempts ?: 2);
        $this->retry_network_issues = (bool) $deviceSetting->retry_network_issues;
    }

    /**
     * Save all device settings.
     */
    public function save(): void
    {
        $this->validate([
            'operator_identity' => ['required', 'string', 'max:120'],
            'primary_transaction_sim' => ['required', Rule::in(['slot_1', 'slot_2'])],
            'sms_auto_reply_sim' => ['required', Rule::in(['slot_1', 'slot_2'])],
            'auto_reschedule_rejected' => ['boolean'],
            'retry_tomorrow_at' => [
                Rule::requiredIf($this->auto_reschedule_rejected),
                'nullable',
                'string',
            ],
            'ussd_timeout_seconds' => ['required', 'integer', 'min:5', 'max:300'],
            'intelligent_auto_retry' => ['boolean'],
            'retry_interval_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'retry_network_issues' => ['boolean'],
        ]);

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

        Flux::toast(variant: 'success', text: __('Device settings saved.'));
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

        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 xl:grid-cols-2">
                <article class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200">
                    <div class="flex items-center gap-4">
                        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-green-50 text-green-700 shadow-inner ring-1 ring-green-100">
                            <span class="text-lg font-black">{{ strtoupper(substr($this->operator_identity ?: Auth::user()->name, 0, 1)) }}</span>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Operator identity') }}</div>
                            <div class="mt-1 text-base font-bold text-zinc-900">{{ $this->operator_identity ?: Auth::user()->name }}</div>
                        </div>
                    </div>
                    <div class="mt-6">
                        <flux:input wire:model="operator_identity" :label="__('Operator display name')" type="text" required />
                    </div>
                </article>

                <article class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200">
                    <div class="flex items-center gap-4">
                        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-700 shadow-inner ring-1 ring-indigo-100">
                            <flux:icon.shield-check class="size-6" />
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Privacy & hardware') }}</div>
                            <div class="mt-1 text-base font-bold text-zinc-900">{{ __('App Permissions') }}</div>
                        </div>
                    </div>
                    <div class="mt-6">
                        <flux:button variant="ghost" class="h-10 w-full justify-center font-black uppercase tracking-widest text-[10px] !bg-zinc-50 ring-1 ring-zinc-200" wire:click.prevent="requestPermissions">
                            <flux:icon.hand-raised variant="mini" class="mr-2 size-4" />
                            {{ __('Check & Request All') }}
                        </flux:button>
                    </div>
                </article>
            </div>

            <article class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200">
                <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Radio & hardware') }}</div>
                <div class="mt-1 text-base font-bold text-zinc-900">{{ __('SIM Slot Mapping') }}</div>

                <div class="mt-6 space-y-6">
                    <div class="space-y-3">
                        <div class="text-[10px] font-black uppercase tracking-widest text-green-600/70">{{ __('Primary transaction SIM') }}</div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($this->simSlotOptions() as $value => $label)
                                <label class="flex cursor-pointer items-center justify-between rounded-xl border px-4 py-3 transition"
                                    :class="$wire.primary_transaction_sim === '{{ $value }}' ? 'border-green-500 bg-zinc-50 ring-2 ring-green-500/10' : 'border-zinc-200 bg-white'">
                                    <input wire:model="primary_transaction_sim" type="radio" class="sr-only" value="{{ $value }}">
                                    <span class="text-sm font-bold" :class="$wire.primary_transaction_sim === '{{ $value }}' ? 'text-green-700' : 'text-zinc-950'">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="text-[10px] font-black uppercase tracking-widest text-green-600/60">{{ __('SMS auto-reply SIM') }}</div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($this->simSlotOptions() as $value => $label)
                                <label class="flex cursor-pointer items-center justify-between rounded-xl border px-4 py-3 transition"
                                    :class="$wire.sms_auto_reply_sim === '{{ $value }}' ? 'border-green-500 bg-zinc-50 ring-2 ring-green-500/10' : 'border-zinc-200 bg-white'">
                                    <input wire:model="sms_auto_reply_sim" type="radio" class="sr-only" value="{{ $value }}">
                                    <span class="text-sm font-bold" :class="$wire.sms_auto_reply_sim === '{{ $value }}' ? 'text-green-700' : 'text-zinc-950'">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </article>

            <article class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200">
                <div class="flex items-center gap-4">
                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-amber-50 text-amber-700 shadow-inner ring-1 ring-amber-100">
                        <flux:icon.command-line class="size-6" />
                    </div>
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Advanced job logic') }}</div>
                        <div class="mt-1 text-base font-bold text-zinc-900">{{ __('Retry & resilience rules') }}</div>
                    </div>
                </div>

                <div class="mt-6 space-y-6">
                    <div class="rounded-2xl bg-zinc-50 p-4 ring-1 ring-zinc-200 shadow-inner">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-[10px] font-black uppercase tracking-widest text-green-600/70">{{ __('Auto reschedule rejected') }}</div>
                                <div class="mt-1 text-[10px] text-zinc-500 leading-tight">{{ __('Automatically re-queue rejected offers for the next day.') }}</div>
                            </div>
                            <flux:switch wire:model.live="auto_reschedule_rejected" size="sm" />
                        </div>

                        @if ($this->auto_reschedule_rejected)
                            <div class="mt-4 border-t border-zinc-200 pt-4">
                                <flux:select wire:model="retry_tomorrow_at" :label="__('Retry tomorrow at')" size="sm">
                                    @foreach ($this->retryScheduleOptions() as $value => $label)
                                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endif
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="ussd_timeout_seconds" :label="__('USSD timeout (sec)')" type="number" min="5" max="300" size="sm" />
                        
                        <div class="rounded-2xl bg-zinc-50 p-4 ring-1 ring-zinc-200 shadow-inner flex items-center justify-between">
                            <div>
                                <div class="text-[10px] font-black uppercase tracking-widest text-green-600/60">{{ __('Intelligent auto-retry') }}</div>
                                <div class="mt-1 text-[10px] text-zinc-500 leading-tight">{{ __('Auto-recovery on network spikes.') }}</div>
                            </div>
                            <flux:switch wire:model.live="intelligent_auto_retry" size="sm" />
                        </div>
                    </div>

                    @if ($this->intelligent_auto_retry)
                        <div class="grid gap-4 md:grid-cols-2">
                            <flux:input wire:model="retry_interval_minutes" :label="__('Interval (mins)')" type="number" min="1" max="60" size="sm" />
                            <flux:input wire:model="max_attempts" :label="__('Max attempts')" type="number" min="1" max="10" size="sm" />
                        </div>
                    @endif

                    <div class="rounded-2xl bg-zinc-50 p-4 ring-1 ring-zinc-200 shadow-inner flex items-center justify-between">
                        <div>
                            <div class="text-[10px] font-black uppercase tracking-widest text-amber-700/70">{{ __('Retry network issues') }}</div>
                            <div class="mt-1 text-[10px] text-zinc-500 leading-tight">{{ __('Aggressive recovery on local connection failures.') }}</div>
                        </div>
                        <flux:switch wire:model.live="retry_network_issues" size="sm" />
                    </div>
                </div>
            </article>

            <div class="fixed bottom-0 left-0 right-0 border-t border-zinc-200 bg-white/90 p-4 backdrop-blur-md">
                <flux:button type="submit" variant="primary" class="w-full h-12 text-base font-black uppercase tracking-widest shadow-lg shadow-green-500/20">
                    {{ __('Save All Settings') }}
                </flux:button>
            </div>
        </form>
    </div>
</section>
>
