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

    public string $app_interface_mode = 'express';

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
        $this->app_interface_mode = $deviceSetting->app_interface_mode;
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
            'app_interface_mode' => ['required', Rule::in(array_keys($this->interfaceModeOptions()))],
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
     * Get the interface mode options.
     *
     * @return array<string, string>
     */
    public function interfaceModeOptions(): array
    {
        return [
            'express' => __('Express'),
            'advanced' => __('Advanced'),
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
                'app_interface_mode' => $this->app_interface_mode,
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
        if (! function_exists('nativephp_call')) {
            return;
        }

        try {
            $idResponse = json_decode(nativephp_call('Device.GetId', '{}'), true);
            $this->deviceId = $idResponse['data']['id'] ?? 'Unknown';

            $infoResponse = json_decode(nativephp_call('Device.GetInfo', '{}'), true);
            $this->osVersion = $infoResponse['data']['os_version'] ?? 'Unknown';
        } catch (\Exception $e) {
            // Log or ignore
        }
    }
}; ?>

<section class="w-full p-4 md:p-6">
    <div class="flex flex-col gap-4">
        <div class="relative overflow-hidden rounded-[2rem] border border-emerald-800 bg-gradient-to-br from-emerald-950 via-emerald-900 to-zinc-900 p-6 text-white shadow-xl dark:border-emerald-700/50">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -top-12 -right-12 h-48 w-48 rounded-full bg-emerald-400/10 blur-3xl motion-safe:animate-pulse"></div>
                <div class="absolute -bottom-16 -left-12 h-44 w-44 rounded-full bg-zinc-400/5 blur-3xl motion-safe:animate-pulse" style="animation-delay: 240ms;"></div>
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent"></div>
            </div>

            <div class="relative flex items-center justify-between gap-4">
                <div>
                    <span class="text-[10px] font-black uppercase tracking-[0.2em] text-emerald-300/60">{{ __('Hardware Interface') }}</span>
                    <flux:heading size="xl" class="mt-1 text-white font-bold tracking-tight">{{ __('Device Settings') }}</flux:heading>
                </div>

                <div class="flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-emerald-400 shadow-sm backdrop-blur-sm">
                    <flux:icon.cpu-chip class="size-6" />
                </div>
            </div>

            <div class="mt-8 grid grid-cols-2 gap-4 border-t border-white/10 pt-6 md:grid-cols-4">
                <div class="flex flex-col">
                    <span class="text-[9px] font-black uppercase tracking-widest text-emerald-300/40">{{ __('Hardware ID') }}</span>
                    <span class="mt-1 font-mono text-[10px] font-bold text-white/80 truncate">{{ $this->deviceId }}</span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[9px] font-black uppercase tracking-widest text-emerald-300/40">{{ __('Platform') }}</span>
                    <span class="mt-1 text-[11px] font-bold text-white/80">Android {{ $this->osVersion }}</span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[9px] font-black uppercase tracking-widest text-emerald-300/40">{{ __('Status') }}</span>
                    <span class="mt-1 flex items-center gap-1.5 text-[11px] font-bold text-emerald-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        {{ __('Online') }}
                    </span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[9px] font-black uppercase tracking-widest text-emerald-300/40">{{ __('Power') }}</span>
                    <span class="mt-1 text-[11px] font-bold text-white/80">{{ __('AC Supply') }}</span>
                </div>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <article class="rounded-[2rem] border border-zinc-100 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/50 xl:col-span-1">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400 shadow-sm">
                        <span class="text-xl font-black">{{ strtoupper(substr($this->operator_identity ?: Auth::user()->name, 0, 1)) }}</span>
                    </div>

                    <div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400/80">
                            {{ __('Operator identity') }}
                        </div>
                        <flux:heading size="lg" class="mt-1 font-bold tracking-tight">{{ $this->operator_identity ?: Auth::user()->name }}</flux:heading>
                    </div>
                </div>

                <form wire:submit="saveOperatorIdentity" class="mt-6 space-y-4">
                    <flux:input
                        wire:model="operator_identity"
                        :label="__('Operator display name')"
                        type="text"
                        required
                        class="rounded-2xl"
                        placeholder="{{ __('Bob Mwenda') }}"
                    />

                    <flux:button variant="primary" class="h-12 w-full rounded-2xl font-bold shadow-lg shadow-emerald-500/10" type="submit">
                        {{ __('Update Identity') }}
                    </flux:button>
                </form>
            </article>

            <article class="rounded-[2rem] border border-zinc-100 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/50 xl:col-span-2">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400/80">
                            {{ __('Radio & hardware') }}
                        </div>
                        <flux:heading size="lg" class="mt-1 font-bold tracking-tight">{{ __('SIM Slot Mapping') }}</flux:heading>
                    </div>
                </div>

                <form wire:submit="saveHardwareMapping" class="mt-6 space-y-6">
                    <div class="space-y-4">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400/60">
                            {{ __('Primary transaction SIM') }}
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($this->simSlotOptions() as $value => $label)
                                <label @class([
                                    'flex cursor-pointer items-center justify-between rounded-2xl border px-5 py-4 transition-all active:scale-[0.98]',
                                    'border-emerald-500 bg-emerald-50/50 shadow-sm ring-4 ring-emerald-500/5 dark:bg-emerald-500/10 dark:border-emerald-500/50' => $this->primary_transaction_sim === $value,
                                    'border-zinc-100 bg-zinc-50/50 dark:border-zinc-800 dark:bg-zinc-800/30' => $this->primary_transaction_sim !== $value,
                                ])>
                                    <div class="flex items-center gap-3">
                                        <div @class([
                                            'h-2 w-2 rounded-full',
                                            'bg-emerald-500' => $this->primary_transaction_sim === $value,
                                            'bg-zinc-300 dark:bg-zinc-700' => $this->primary_transaction_sim !== $value,
                                        ])></div>
                                        <span class="text-base font-bold text-zinc-900 dark:text-zinc-100">{{ $label }}</span>
                                    </div>
                                    <input wire:model="primary_transaction_sim" type="radio" class="sr-only" value="{{ $value }}">
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400/60">
                            {{ __('SMS auto-reply SIM') }}
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($this->simSlotOptions() as $value => $label)
                                <label @class([
                                    'flex cursor-pointer items-center justify-between rounded-2xl border px-5 py-4 transition-all active:scale-[0.98]',
                                    'border-indigo-500 bg-indigo-50/50 shadow-sm ring-4 ring-indigo-500/5 dark:bg-indigo-500/10 dark:border-indigo-500/50' => $this->sms_auto_reply_sim === $value,
                                    'border-zinc-100 bg-zinc-50/50 dark:border-zinc-800 dark:bg-zinc-800/30' => $this->sms_auto_reply_sim !== $value,
                                ])>
                                    <div class="flex items-center gap-3">
                                        <div @class([
                                            'h-2 w-2 rounded-full',
                                            'bg-indigo-500' => $this->sms_auto_reply_sim === $value,
                                            'bg-zinc-300 dark:bg-zinc-700' => $this->sms_auto_reply_sim !== $value,
                                        ])></div>
                                        <span class="text-base font-bold text-zinc-900 dark:text-zinc-100">{{ $label }}</span>
                                    </div>
                                    <input wire:model="sms_auto_reply_sim" type="radio" class="sr-only" value="{{ $value }}">
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <flux:button variant="primary" class="h-12 w-full rounded-2xl font-bold" type="submit">
                        {{ __('Save Hardware Mapping') }}
                    </flux:button>
                </form>
            </article>
        </div>

        <article class="rounded-[2rem] border border-zinc-100 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/50">
            <div class="flex items-center gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-500/10 text-violet-700 dark:text-violet-300 shadow-sm">
                    <flux:icon.command-line class="size-6" />
                </div>

                <div>
                    <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400/80">
                        {{ __('Advanced job logic') }}
                    </div>
                    <flux:heading size="lg" class="mt-1 font-bold tracking-tight">{{ __('Retry & resilience rules') }}</flux:heading>
                </div>
            </div>

            <form wire:submit="saveTechnicalConfig" class="mt-8 space-y-8">
                <div class="space-y-4">
                    <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400/60">
                        {{ __('App interface mode') }}
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($this->interfaceModeOptions() as $value => $label)
                            <label @class([
                                'flex cursor-pointer items-center justify-between rounded-2xl border px-5 py-4 transition-all active:scale-[0.98]',
                                'border-violet-500 bg-violet-50/50 shadow-sm ring-4 ring-violet-500/5 dark:bg-violet-500/10 dark:border-violet-500/50' => $this->app_interface_mode === $value,
                                'border-zinc-100 bg-zinc-50/50 dark:border-zinc-800 dark:bg-zinc-800/30' => $this->app_interface_mode !== $value,
                            ])>
                                <span>
                                    <span class="block text-base font-bold text-zinc-900 dark:text-zinc-100">{{ $label }}</span>
                                    <span class="block text-xs font-bold text-zinc-400 uppercase tracking-wide mt-0.5">
                                        {{ $value === 'express' ? __('Simplified UI') : __('Fine grain info') }}
                                    </span>
                                </span>
                                <input wire:model="app_interface_mode" type="radio" class="sr-only" value="{{ $value }}">
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-3xl border border-zinc-100 bg-zinc-50/50 p-6 dark:border-zinc-800 dark:bg-zinc-800/30">
                    <div class="flex items-start justify-between gap-6">
                        <div>
                            <div class="text-[10px] font-black uppercase tracking-widest text-zinc-500 dark:text-zinc-400">
                                {{ __('Auto reschedule rejected') }}
                            </div>
                            <div class="mt-2 text-sm font-medium text-zinc-500 leading-relaxed max-w-md">
                                {{ __('Automatically re-queue rejected offers for the next day to maintain payout flow.') }}
                            </div>
                        </div>

                        <label class="relative inline-flex cursor-pointer items-center">
                            <input wire:model="auto_reschedule_rejected" type="checkbox" class="peer sr-only">
                            <span class="h-7 w-12 rounded-full bg-zinc-200 transition-all peer-checked:bg-emerald-500 dark:bg-zinc-700"></span>
                            <span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow-md transition-all peer-checked:translate-x-5"></span>
                        </label>
                    </div>

                    @if ($this->auto_reschedule_rejected)
                        <div class="mt-6">
                            <flux:select wire:model="retry_tomorrow_at" :label="__('Retry tomorrow at')" class="rounded-2xl">
                                @foreach ($this->retryScheduleOptions() as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    @endif
                </div>

                <div class="grid gap-6 md:grid-cols-2">
                    <flux:input wire:model="ussd_timeout_seconds" :label="__('USSD timeout (sec)')" type="number" min="5" max="300" step="1" required class="rounded-2xl" />

                    <div class="rounded-3xl border border-zinc-100 bg-zinc-50/50 p-6 dark:border-zinc-800 dark:bg-zinc-800/30">
                        <div class="flex items-start justify-between gap-6">
                            <div>
                                <div class="text-[10px] font-black uppercase tracking-widest text-zinc-500 dark:text-zinc-400">
                                    {{ __('Intelligent auto-retry') }}
                                </div>
                                <div class="mt-2 text-sm font-medium text-zinc-500 leading-relaxed">
                                    {{ __('Auto-recovery on transient network or hardware spikes.') }}
                                </div>
                            </div>

                            <label class="relative inline-flex cursor-pointer items-center">
                                <input wire:model="intelligent_auto_retry" type="checkbox" class="peer sr-only">
                                <span class="h-7 w-12 rounded-full bg-zinc-200 transition-all peer-checked:bg-violet-500 dark:bg-zinc-700"></span>
                                <span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow-md transition-all peer-checked:translate-x-5"></span>
                            </label>
                        </div>
                    </div>
                </div>

                @if ($this->intelligent_auto_retry)
                    <div class="grid gap-6 md:grid-cols-2">
                        <flux:input wire:model="retry_interval_minutes" :label="__('Interval (mins)')" type="number" min="1" max="60" step="1" required class="rounded-2xl" />
                        <flux:input wire:model="max_attempts" :label="__('Max attempts')" type="number" min="1" max="10" step="1" required class="rounded-2xl" />
                    </div>
                @endif

                <div class="rounded-3xl border border-amber-200 bg-amber-50/50 p-6 dark:border-amber-900/20 dark:bg-amber-950/20">
                    <div class="flex items-start justify-between gap-6">
                        <div>
                            <div class="text-[10px] font-black uppercase tracking-widest text-amber-700 dark:text-amber-400">
                                {{ __('Retry network issues') }}
                            </div>
                            <div class="mt-2 text-sm font-medium text-amber-600/80 leading-relaxed max-w-md">
                                {{ __('Aggressive recovery on local socket or connection failures.') }}
                            </div>
                        </div>

                        <label class="relative inline-flex cursor-pointer items-center">
                            <input wire:model="retry_network_issues" type="checkbox" class="peer sr-only">
                            <span class="h-7 w-12 rounded-full bg-amber-200 transition-all peer-checked:bg-amber-500 dark:bg-amber-900"></span>
                            <span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow-md transition-all peer-checked:translate-x-5"></span>
                        </label>
                    </div>
                </div>

                <flux:button variant="primary" class="h-14 w-full rounded-2xl font-black shadow-lg" type="submit">
                    {{ __('Apply Technical Configuration') }}
                </flux:button>
            </form>
        </article>
>
    </div>
</section>
