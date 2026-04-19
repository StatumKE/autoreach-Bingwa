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

    /**
     * Hydrate the form from the stored device settings.
     */
    public function mount(): void
    {
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
}; ?>

<section class="w-full p-4 md:p-6">
    <div class="flex flex-col gap-4">
        <div class="relative overflow-hidden rounded-3xl border border-zinc-200 bg-gradient-to-br from-white via-white to-zinc-100 p-5 shadow-sm dark:border-zinc-700 dark:from-zinc-950 dark:via-zinc-900 dark:to-zinc-900 md:p-6">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -top-12 -right-12 h-36 w-36 rounded-full bg-indigo-400/10 blur-3xl motion-safe:animate-pulse"></div>
                <div class="absolute -bottom-16 -left-12 h-40 w-40 rounded-full bg-cyan-400/10 blur-3xl motion-safe:animate-pulse" style="animation-delay: 240ms;"></div>
            </div>

            <div class="relative flex items-start justify-between gap-4">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.3em] text-zinc-500 dark:text-zinc-400">
                        {{ __('Settings') }}
                    </div>
                    <flux:heading size="xl" class="mt-2">{{ __('Device & application configuration') }}</flux:heading>
                    <flux:text class="mt-2 max-w-2xl text-zinc-600 dark:text-zinc-300">
                        {{ __('Configure the device identity, SIM mapping, and retry behavior used by this Android installation.') }}
                    </flux:text>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white/80 px-4 py-3 text-right shadow-sm dark:border-zinc-700 dark:bg-zinc-900/80">
                    <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Device') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-zinc-950 dark:text-zinc-50">{{ __('Ready') }}</div>
                </div>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <article class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:col-span-1">
                <div class="flex items-center gap-3">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-500/10 text-indigo-700 dark:text-indigo-300">
                        <span class="text-xl font-semibold">{{ strtoupper(substr($this->operator_identity ?: Auth::user()->name, 0, 1)) }}</span>
                    </div>

                    <div>
                        <flux:heading size="lg">{{ $this->operator_identity ?: Auth::user()->name }}</flux:heading>
                        <div class="text-xs font-semibold uppercase tracking-[0.25em] text-zinc-500 dark:text-zinc-400">
                            {{ __('Operator identity') }}
                        </div>
                    </div>
                </div>

                <form wire:submit="saveOperatorIdentity" class="mt-5 space-y-4">
                    <flux:input
                        wire:model="operator_identity"
                        :label="__('Operator identity')"
                        type="text"
                        required
                        autocomplete="name"
                        placeholder="{{ __('Bob Mwenda') }}"
                    />

                    <flux:button variant="primary" class="w-full" type="submit">
                        {{ __('Update Profile') }}
                    </flux:button>
                </form>
            </article>

            <article class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:col-span-2">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.25em] text-zinc-500 dark:text-zinc-400">
                            {{ __('Radio & hardware') }}
                        </div>
                        <flux:heading size="lg" class="mt-1">{{ __('SIM Slot Mapping') }}</flux:heading>
                    </div>
                </div>

                <form wire:submit="saveHardwareMapping" class="mt-5 space-y-5">
                    <div class="space-y-3">
                        <div class="text-sm font-semibold uppercase tracking-[0.25em] text-zinc-500 dark:text-zinc-400">
                            {{ __('Primary transaction SIM') }}
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($this->simSlotOptions() as $value => $label)
                                <label class="flex cursor-pointer items-center justify-between rounded-2xl border px-4 py-4 transition-colors {{ $this->primary_transaction_sim === $value ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-500/15 dark:bg-indigo-500/10' : 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800' }}">
                                    <span class="text-base font-semibold text-zinc-950 dark:text-zinc-50">{{ $label }}</span>
                                    <input wire:model="primary_transaction_sim" type="radio" class="h-5 w-5 border-zinc-300 text-indigo-600 focus:ring-indigo-600" value="{{ $value }}">
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="text-sm font-semibold uppercase tracking-[0.25em] text-zinc-500 dark:text-zinc-400">
                            {{ __('SMS auto-reply SIM') }}
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($this->simSlotOptions() as $value => $label)
                                <label class="flex cursor-pointer items-center justify-between rounded-2xl border px-4 py-4 transition-colors {{ $this->sms_auto_reply_sim === $value ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-500/15 dark:bg-indigo-500/10' : 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800' }}">
                                    <span class="text-base font-semibold text-zinc-950 dark:text-zinc-50">{{ $label }}</span>
                                    <input wire:model="sms_auto_reply_sim" type="radio" class="h-5 w-5 border-zinc-300 text-indigo-600 focus:ring-indigo-600" value="{{ $value }}">
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <flux:button variant="primary" class="w-full" type="submit">
                        {{ __('Save Hardware Mapping') }}
                    </flux:button>
                </form>
            </article>
        </div>

        <article class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-500/10 text-violet-700 dark:text-violet-300">
                    <span class="text-xl">⚙</span>
                </div>

                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.25em] text-zinc-500 dark:text-zinc-400">
                        {{ __('Advanced job logic') }}
                    </div>
                    <flux:heading size="lg" class="mt-1">{{ __('Retry & resilience rules') }}</flux:heading>
                </div>
            </div>

            <form wire:submit="saveTechnicalConfig" class="mt-6 space-y-6">
                <div class="space-y-3">
                    <div class="text-sm font-semibold uppercase tracking-[0.25em] text-zinc-500 dark:text-zinc-400">
                        {{ __('App interface mode') }}
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($this->interfaceModeOptions() as $value => $label)
                            <label class="flex cursor-pointer items-center justify-between rounded-2xl border px-4 py-4 transition-colors {{ $this->app_interface_mode === $value ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-500/15 dark:bg-indigo-500/10' : 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800' }}">
                                <span>
                                    <span class="block text-base font-semibold text-zinc-950 dark:text-zinc-50">{{ $label }}</span>
                                    <span class="block text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $value === 'express' ? __('Simplified UI') : __('Fine grain info') }}
                                    </span>
                                </span>
                                <input wire:model="app_interface_mode" type="radio" class="h-5 w-5 border-zinc-300 text-indigo-600 focus:ring-indigo-600" value="{{ $value }}">
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-3xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-semibold uppercase tracking-[0.25em] text-zinc-500 dark:text-zinc-400">
                                {{ __('Auto reschedule rejected') }}
                            </div>
                            <div class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ __('Automatically re-queue rejected offers for the next day.') }}
                            </div>
                        </div>

                        <label class="relative inline-flex cursor-pointer items-center">
                            <input wire:model="auto_reschedule_rejected" type="checkbox" class="peer sr-only">
                            <span class="h-7 w-12 rounded-full bg-zinc-300 transition peer-checked:bg-violet-600"></span>
                            <span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                        </label>
                    </div>

                    @if ($this->auto_reschedule_rejected)
                        <div class="mt-5">
                            <flux:select wire:model="retry_tomorrow_at" :label="__('Retry tomorrow at')" required>
                                @foreach ($this->retryScheduleOptions() as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    @endif
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="ussd_timeout_seconds" :label="__('USSD timeout (seconds)')" type="number" min="5" max="300" step="1" required />

                    <div class="rounded-3xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-sm font-semibold uppercase tracking-[0.25em] text-zinc-500 dark:text-zinc-400">
                                    {{ __('Intelligent auto-retry') }}
                                </div>
                                <div class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ __('Automatically re-queue failed jobs on recoverable failures.') }}
                                </div>
                            </div>

                            <label class="relative inline-flex cursor-pointer items-center">
                                <input wire:model="intelligent_auto_retry" type="checkbox" class="peer sr-only">
                                <span class="h-7 w-12 rounded-full bg-zinc-300 transition peer-checked:bg-violet-600"></span>
                                <span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                            </label>
                        </div>
                    </div>
                </div>

                @if ($this->intelligent_auto_retry)
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="retry_interval_minutes" :label="__('Interval (mins)')" type="number" min="1" max="60" step="1" required />
                        <flux:input wire:model="max_attempts" :label="__('Max attempts')" type="number" min="1" max="10" step="1" required />
                    </div>
                @endif

                <div class="rounded-3xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/20 dark:bg-amber-500/10">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-semibold uppercase tracking-[0.25em] text-amber-900 dark:text-amber-100">
                                {{ __('Retry network issues') }}
                            </div>
                            <div class="mt-1 text-sm text-amber-900/80 dark:text-amber-100/80">
                                {{ __('Attempt recovery on local socket or connection failures.') }}
                            </div>
                        </div>

                        <label class="relative inline-flex cursor-pointer items-center">
                            <input wire:model="retry_network_issues" type="checkbox" class="peer sr-only">
                            <span class="h-7 w-12 rounded-full bg-zinc-300 transition peer-checked:bg-orange-500"></span>
                            <span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                        </label>
                    </div>
                </div>

                <flux:button variant="primary" class="w-full" type="submit">
                    {{ __('Save Technical Config') }}
                </flux:button>
            </form>
        </article>
    </div>
</section>
