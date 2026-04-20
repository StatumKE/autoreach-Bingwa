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
     * Fetch native device hardware information.
     */
    private function fetchNativeDeviceInfo(): void
    {
        $user = Auth::user();
        $registration = $user->bingwaDeviceRegistration;
        
        $this->deviceId = $registration?->hardware_id 
            ?? $user->autoreach_connect_id 
            ?? 'Unknown';

        if (! function_exists('nativephp_call')) {
            return;
        }

        try {
            $idResponse = json_decode(nativephp_call('Device.GetId', '{}'), true);
            if (isset($idResponse['data']['id'])) {
                $this->deviceId = $idResponse['data']['id'];
            }

            $infoResponse = json_decode(nativephp_call('Device.GetInfo', '{}'), true);
            if (isset($infoResponse['data']['os_version'])) {
                $this->osVersion = $infoResponse['data']['os_version'];
            }
        } catch (\Exception $e) {
            // Log or ignore
        }
    }
}; ?>

<section class="w-full p-4 md:p-6 bg-slate-950 min-h-screen">
    <div class="flex flex-col gap-4">
        <div class="relative overflow-hidden rounded-[2.5rem] bg-slate-900 p-6 shadow-2xl ring-1 ring-slate-800 md:p-8">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -top-12 -right-12 h-48 w-48 rounded-full bg-teal-500/5 blur-3xl"></div>
                <div class="absolute -bottom-16 -left-12 h-44 w-44 rounded-full bg-indigo-500/5 blur-3xl"></div>
            </div>

            <div class="relative flex items-center justify-between gap-4">
                <div>
                    <span class="text-[10px] font-black uppercase tracking-[0.3em] text-teal-400/40">{{ __('Hardware Interface') }}</span>
                    <flux:heading size="xl" class="mt-1 text-white font-black tracking-tight text-3xl">{{ __('Device Settings') }}</flux:heading>
                </div>

                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-950 text-indigo-400 shadow-inner ring-1 ring-slate-800">
                    <flux:icon.cpu-chip class="size-6" />
                </div>
            </div>

            <div class="mt-8 grid grid-cols-2 gap-6 border-t border-slate-800/50 pt-8 md:grid-cols-4">
                <div class="flex flex-col">
                    <span class="text-[9px] font-black uppercase tracking-widest text-slate-500">{{ __('Hardware ID') }}</span>
                    <span class="mt-1 font-mono text-[10px] font-black text-white/80 truncate">{{ $this->deviceId }}</span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[9px] font-black uppercase tracking-widest text-slate-500">{{ __('Platform') }}</span>
                    <span class="mt-1 text-[11px] font-black text-white/80">Android {{ $this->osVersion }}</span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[9px] font-black uppercase tracking-widest text-slate-500">{{ __('Status') }}</span>
                    <span class="mt-1 flex items-center gap-1.5 text-[11px] font-black text-teal-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-teal-400 shadow-[0_0_8px_rgba(45,212,191,0.5)] animate-pulse"></span>
                        {{ __('Online') }}
                    </span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[9px] font-black uppercase tracking-widest text-slate-500">{{ __('Power') }}</span>
                    <span class="mt-1 text-[11px] font-black text-white/80">{{ __('AC Supply') }}</span>
                </div>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <article class="rounded-[2.5rem] bg-slate-900 p-8 shadow-2xl ring-1 ring-slate-800 xl:col-span-1">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10 text-teal-400 shadow-inner ring-1 ring-teal-500/20">
                        <span class="text-xl font-black">{{ strtoupper(substr($this->operator_identity ?: Auth::user()->name, 0, 1)) }}</span>
                    </div>

                    <div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-500">
                            {{ __('Operator identity') }}
                        </div>
                        <flux:heading size="lg" class="mt-1 font-black tracking-tight text-white">{{ $this->operator_identity ?: Auth::user()->name }}</flux:heading>
                    </div>
                </div>

                <form wire:submit="saveOperatorIdentity" class="mt-8 space-y-5">
                    <flux:input
                        wire:model="operator_identity"
                        :label="__('Operator display name')"
                        type="text"
                        required
                        placeholder="{{ __('Bob Mwenda') }}"
                    />

                    <flux:button variant="primary" class="h-12 w-full rounded-2xl font-black uppercase tracking-widest text-[10px] shadow-xl shadow-indigo-600/20 bg-indigo-600 hover:bg-indigo-500 text-white" type="submit">
                        {{ __('Update Identity') }}
                    </flux:button>
                </form>
            </article>

            <article class="rounded-[2.5rem] bg-slate-900 p-8 shadow-2xl ring-1 ring-slate-800 xl:col-span-2">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-500">
                            {{ __('Radio & hardware') }}
                        </div>
                        <flux:heading size="lg" class="mt-1 font-black tracking-tight text-white">{{ __('SIM Slot Mapping') }}</flux:heading>
                    </div>
                </div>

                <form wire:submit="saveHardwareMapping" class="mt-8 space-y-8">
                    <div class="space-y-4">
                        <div class="text-[10px] font-black uppercase tracking-widest text-teal-400/40">
                            {{ __('Primary transaction SIM') }}
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($this->simSlotOptions() as $value => $label)
                                <label @class([
                                    'flex cursor-pointer items-center justify-between rounded-2xl border px-6 py-5 transition-all active:scale-[0.98]',
                                    'border-teal-500/50 bg-slate-950 shadow-inner ring-4 ring-teal-500/5' => $this->primary_transaction_sim === $value,
                                    'border-slate-800 bg-slate-900' => $this->primary_transaction_sim !== $value,
                                ])>
                                    <div class="flex items-center gap-4">
                                        <div @class([
                                            'h-2.5 w-2.5 rounded-full',
                                            'bg-teal-400 shadow-[0_0_10px_rgba(45,212,191,0.6)]' => $this->primary_transaction_sim === $value,
                                            'bg-slate-700' => $this->primary_transaction_sim !== $value,
                                        ])></div>
                                        <span class="text-base font-black text-white">{{ $label }}</span>
                                    </div>
                                    <input wire:model="primary_transaction_sim" type="radio" class="sr-only" value="{{ $value }}">
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="text-[10px] font-black uppercase tracking-widest text-indigo-400/40">
                            {{ __('SMS auto-reply SIM') }}
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($this->simSlotOptions() as $value => $label)
                                <label @class([
                                    'flex cursor-pointer items-center justify-between rounded-2xl border px-6 py-5 transition-all active:scale-[0.98]',
                                    'border-indigo-500/50 bg-slate-950 shadow-inner ring-4 ring-indigo-500/5' => $this->sms_auto_reply_sim === $value,
                                    'border-slate-800 bg-slate-900' => $this->sms_auto_reply_sim !== $value,
                                ])>
                                    <div class="flex items-center gap-4">
                                        <div @class([
                                            'h-2.5 w-2.5 rounded-full',
                                            'bg-indigo-500 shadow-[0_0_10px_rgba(99,102,241,0.6)]' => $this->sms_auto_reply_sim === $value,
                                            'bg-slate-700' => $this->sms_auto_reply_sim !== $value,
                                        ])></div>
                                        <span class="text-base font-black text-white">{{ $label }}</span>
                                    </div>
                                    <input wire:model="sms_auto_reply_sim" type="radio" class="sr-only" value="{{ $value }}">
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <flux:button variant="primary" class="h-12 w-full rounded-2xl font-black uppercase tracking-widest text-[10px] bg-slate-800 hover:bg-slate-700 ring-1 ring-slate-700 text-white" type="submit">
                        {{ __('Save Hardware Mapping') }}
                    </flux:button>
                </form>
            </article>
        </div>

        <article class="rounded-[2.5rem] bg-slate-900 p-8 shadow-2xl ring-1 ring-slate-800">
            <div class="flex items-center gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-500/10 text-violet-400 shadow-inner ring-1 ring-violet-500/20">
                    <flux:icon.command-line class="size-6" />
                </div>

                <div>
                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-500">
                        {{ __('Advanced job logic') }}
                    </div>
                    <flux:heading size="lg" class="mt-1 font-black tracking-tight text-white">{{ __('Retry & resilience rules') }}</flux:heading>
                </div>
            </div>

            <form wire:submit="saveTechnicalConfig" class="mt-10 space-y-10">
                <div class="rounded-[2rem] bg-slate-950 p-8 ring-1 ring-slate-800 shadow-inner">
                    <div class="flex items-start justify-between gap-6">
                        <div>
                            <div class="text-[10px] font-black uppercase tracking-widest text-teal-400/40">
                                {{ __('Auto reschedule rejected') }}
                            </div>
                            <div class="mt-3 text-sm font-medium text-slate-500 leading-relaxed max-w-md">
                                {{ __('Automatically re-queue rejected offers for the next day to maintain payout flow.') }}
                            </div>
                        </div>

                        <label class="relative inline-flex cursor-pointer items-center">
                            <input wire:model="auto_reschedule_rejected" type="checkbox" class="peer sr-only">
                            <span class="h-8 w-14 rounded-full bg-slate-800 transition-all peer-checked:bg-teal-500 ring-1 ring-slate-700"></span>
                            <span class="absolute left-1.5 top-1.5 h-5 w-5 rounded-full bg-white shadow-md transition-all peer-checked:translate-x-6"></span>
                        </label>
                    </div>

                    @if ($this->auto_reschedule_rejected)
                        <div class="mt-8 border-t border-slate-900 pt-8">
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

                    <div class="rounded-[2rem] bg-slate-950 p-8 ring-1 ring-slate-800 shadow-inner">
                        <div class="flex items-start justify-between gap-6">
                            <div>
                                <div class="text-[10px] font-black uppercase tracking-widest text-indigo-400/40">
                                    {{ __('Intelligent auto-retry') }}
                                </div>
                                <div class="mt-3 text-sm font-medium text-slate-500 leading-relaxed">
                                    {{ __('Auto-recovery on transient network or hardware spikes.') }}
                                </div>
                            </div>

                            <label class="relative inline-flex cursor-pointer items-center">
                                <input wire:model="intelligent_auto_retry" type="checkbox" class="peer sr-only">
                                <span class="h-8 w-14 rounded-full bg-slate-800 transition-all peer-checked:bg-indigo-500 ring-1 ring-slate-700"></span>
                                <span class="absolute left-1.5 top-1.5 h-5 w-5 rounded-full bg-white shadow-md transition-all peer-checked:translate-x-6"></span>
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

                <div class="rounded-[2rem] bg-slate-950 p-8 ring-1 ring-slate-800 shadow-inner">
                    <div class="flex items-start justify-between gap-6">
                        <div>
                            <div class="text-[10px] font-black uppercase tracking-widest text-violet-400/40">
                                {{ __('Retry network issues') }}
                            </div>
                            <div class="mt-3 text-sm font-medium text-slate-500 leading-relaxed max-w-md">
                                {{ __('Aggressive recovery on local socket or connection failures.') }}
                            </div>
                        </div>

                        <label class="relative inline-flex cursor-pointer items-center">
                            <input wire:model="retry_network_issues" type="checkbox" class="peer sr-only">
                            <span class="h-8 w-14 rounded-full bg-slate-800 transition-all peer-checked:bg-violet-500 ring-1 ring-slate-700"></span>
                            <span class="absolute left-1.5 top-1.5 h-5 w-5 rounded-full bg-white shadow-md transition-all peer-checked:translate-x-6"></span>
                        </label>
                    </div>
                </div>

                <flux:button variant="primary" class="h-14 w-full rounded-2xl font-black uppercase tracking-widest text-[10px] bg-indigo-600 hover:bg-indigo-500 text-white shadow-xl shadow-indigo-600/20" type="submit">
                    {{ __('Apply Technical Configuration') }}
                </flux:button>
            </form>
        </article>
    </div>
</section>
