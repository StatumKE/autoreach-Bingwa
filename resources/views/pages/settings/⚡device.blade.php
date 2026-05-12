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
     * Save the operator identity section.
     */
    public function saveIdentity(): void
    {
        $this->validate(['operator_identity' => ['required', 'string', 'max:120']]);

        $this->persist(['operator_identity' => $this->operator_identity]);

        Flux::toast(variant: 'success', text: __('Operator identity updated.'));
    }

    /**
     * Save the hardware SIM mapping section.
     */
    public function saveHardware(): void
    {
        $this->validate([
            'primary_transaction_sim' => ['required', Rule::in(['slot_1', 'slot_2'])],
            'sms_auto_reply_sim' => ['required', Rule::in(['slot_1', 'slot_2'])],
        ]);

        $this->persist([
            'primary_transaction_sim' => $this->primary_transaction_sim,
            'sms_auto_reply_sim' => $this->sms_auto_reply_sim,
        ]);

        Flux::toast(variant: 'success', text: __('SIM mapping updated.'));
    }

    /**
     * Save the technical/retry configuration section.
     */
    public function saveTechnical(): void
    {
        $this->validate([
            'auto_reschedule_rejected' => ['boolean'],
            'retry_tomorrow_at' => [Rule::requiredIf($this->auto_reschedule_rejected), 'nullable', 'string'],
            'ussd_timeout_seconds' => ['required', 'integer', 'min:5', 'max:300'],
            'intelligent_auto_retry' => ['boolean'],
            'retry_interval_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'retry_network_issues' => ['boolean'],
        ]);

        $this->persist([
            'auto_reschedule_rejected' => $this->auto_reschedule_rejected,
            'retry_tomorrow_at' => $this->auto_reschedule_rejected ? $this->retry_tomorrow_at : null,
            'ussd_timeout_seconds' => (int) $this->ussd_timeout_seconds,
            'intelligent_auto_retry' => $this->intelligent_auto_retry,
            'retry_interval_minutes' => (int) $this->retry_interval_minutes,
            'max_attempts' => (int) $this->max_attempts,
            'retry_network_issues' => $this->retry_network_issues,
        ]);

        Flux::toast(variant: 'success', text: __('Technical settings updated.'));
    }

    /**
     * Atomic persistence to database.
     */
    private function persist(array $data): void
    {
        $userId = Auth::id();

        if (! $userId) {
            return;
        }

        DeviceSetting::query()->updateOrCreate(['user_id' => $userId], $data);
        
        session()->flash('status', __('Settings saved successfully.'));
    }

    /**
     * Get the platform label shown in the device summary.
     */
    public function getPlatformLabelProperty(): string
    {
        return $this->osVersion !== 'Unknown' ? trim("{$this->osName} {$this->osVersion}") : ($this->osName ?: __('Android'));
    }

    /**
     * Get the slot options for the hardware mapping cards.
     */
    public function simSlotOptions(): array
    {
        return ['slot_1' => __('Slot 1'), 'slot_2' => __('Slot 2')];
    }

    /**
     * Get the retry schedule options.
     */
    public function retryScheduleOptions(): array
    {
        $options = [];
        for ($hour = 0; $hour < 24; $hour++) {
            foreach ([0, 30] as $minute) {
                $time = now()->copy()->startOfDay()->addHours($hour)->addMinutes($minute)->format('g:i A');
                $options[$time] = $time;
            }
        }
        return $options;
    }

    /**
     * Trigger permission request flow.
     */
    public function requestPermissions(): void
    {
        if (! function_exists('nativephp_call')) {
            Flux::toast(variant: 'warning', text: __('Native features unavailable.'));
            return;
        }
        nativephp_call('RequestSetupPermissions', json_encode(['force' => true, 'openSpecialSettings' => true]));
        Flux::toast(text: __('Requesting permissions…'));
    }

    /**
     * Fetch native device hardware information.
     */
    private function fetchNativeDeviceInfo(): void
    {
        $user = Auth::user();
        $registration = $user->bingwaDeviceRegistration;
        $this->deviceId = $registration?->hardware_id ?? $user->autoreach_connect_id ?? 'Unknown';
        $this->deviceCode = $registration?->bhc_code ?? 'None';

        if (! function_exists('nativephp_call')) return;

        try {
            $idResponse = json_decode(nativephp_call('Device.GetId', '{}'), true);
            if (isset($idResponse['data']['id'])) $this->deviceId = $idResponse['data']['id'];

            $infoResponse = json_decode(nativephp_call('Device.GetInfo', '{}'), true);
            if (isset($infoResponse['data']['os_name'])) $this->osName = $infoResponse['data']['os_name'];
            if (isset($infoResponse['data']['os_version'])) $this->osVersion = $infoResponse['data']['os_version'];
        } catch (\Exception $e) {}
    }
}; ?>

<section class="min-h-screen bg-zinc-50 px-4 pb-24 pt-4">
    <div class="flex flex-col gap-4">
        <header class="px-1">
            <h1 class="text-xl font-black tracking-tight text-zinc-900">{{ __('Device Configuration') }}</h1>
            <p class="text-xs text-zinc-500">{{ __('Manage hardware and automation rules for this device.') }}</p>
        </header>

        @if (session('status'))
            <div class="rounded-xl bg-green-50 p-4 text-xs font-bold text-green-700 ring-1 ring-green-100">
                {{ session('status') }}
            </div>
        @endif

        {{-- Device Summary Grid --}}
        <div class="grid grid-cols-2 gap-2 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-zinc-200">
            <div class="space-y-0.5">
                <span class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ __('Hardware ID') }}</span>
                <p class="font-mono text-[10px] font-bold text-zinc-700 truncate">{{ $this->deviceId }}</p>
            </div>
            <div class="space-y-0.5">
                <span class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ __('Platform') }}</span>
                <p class="text-[11px] font-bold text-zinc-700">{{ $this->platformLabel }}</p>
            </div>
        </div>

        {{-- Card 1: Identity --}}
        <article class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200">
            <form wire:submit="saveIdentity">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-green-50 text-green-700 ring-1 ring-green-100">
                            <flux:icon.user variant="mini" class="size-5" />
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-zinc-900">{{ __('Operator Identity') }}</h2>
                            <p class="text-[10px] text-zinc-500">{{ __('How this device appears in reports.') }}</p>
                        </div>
                    </div>
                    <flux:button type="submit" variant="primary" size="sm" class="font-bold" wire:loading.attr="disabled">
                        <span wire:loading.remove>{{ __('Save') }}</span>
                        <span wire:loading>{{ __('Saving...') }}</span>
                    </flux:button>
                </div>
                <div class="mt-6">
                    <flux:input wire:model="operator_identity" :label="__('Display Name')" placeholder="{{ Auth::user()->name }}" required />
                </div>
            </form>
        </article>

        {{-- Permissions Quick Action --}}
        <article class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200">
            <div class="flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-700 ring-1 ring-indigo-100">
                    <flux:icon.shield-check variant="mini" class="size-5" />
                </div>
                <div>
                    <h2 class="text-sm font-bold text-zinc-900">{{ __('System Permissions') }}</h2>
                    <p class="text-[10px] text-zinc-500">{{ __('Required for USSD automation.') }}</p>
                </div>
            </div>
            <div class="mt-6">
                <flux:button variant="ghost" class="w-full justify-center font-black uppercase tracking-widest text-[10px] !bg-zinc-50 ring-1 ring-zinc-200" wire:click.prevent="requestPermissions">
                    <flux:icon.hand-raised variant="mini" class="mr-2 size-4" />
                    {{ __('Grant All Permissions') }}
                </flux:button>
            </div>
        </article>

        {{-- Card 2: SIM Mapping --}}
        <article class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200">
            <form wire:submit="saveHardware">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-zinc-900 text-white">
                            <flux:icon.cpu-chip variant="mini" class="size-5" />
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-zinc-900">{{ __('SIM Slot Mapping') }}</h2>
                            <p class="text-[10px] text-zinc-500">{{ __('Routing rules for transactions.') }}</p>
                        </div>
                    </div>
                    <flux:button type="submit" variant="primary" size="sm" class="font-bold" wire:loading.attr="disabled">
                        <span wire:loading.remove>{{ __('Save Mapping') }}</span>
                        <span wire:loading>{{ __('Updating...') }}</span>
                    </flux:button>
                </div>

                <div class="mt-8 space-y-8">
                    <flux:radio.group wire:model="primary_transaction_sim" :label="__('Primary Transaction SIM')" variant="cards" class="flex-col">
                        @foreach ($this->simSlotOptions() as $val => $label)
                            <flux:radio value="{{ $val }}" :label="$label" />
                        @endforeach
                    </flux:radio.group>

                    <flux:radio.group wire:model="sms_auto_reply_sim" :label="__('SMS Auto-Reply SIM')" variant="cards" class="flex-col">
                        @foreach ($this->simSlotOptions() as $val => $label)
                            <flux:radio value="{{ $val }}" :label="$label" />
                        @endforeach
                    </flux:radio.group>
                </div>
            </form>
        </article>

        {{-- Card 3: Advanced Rules --}}
        <article class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200">
            <form wire:submit="saveTechnical">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-amber-50 text-amber-700 ring-1 ring-amber-100">
                            <flux:icon.command-line variant="mini" class="size-5" />
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-zinc-900">{{ __('Automation Rules') }}</h2>
                            <p class="text-[10px] text-zinc-500">{{ __('Retry logic and USSD timeouts.') }}</p>
                        </div>
                    </div>
                    <flux:button type="submit" variant="primary" size="sm" class="font-bold" wire:loading.attr="disabled">
                        <span wire:loading.remove>{{ __('Save Rules') }}</span>
                        <span wire:loading>{{ __('Applying...') }}</span>
                    </flux:button>
                </div>

                <div class="mt-8 space-y-5">
                    <div class="rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-200">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-zinc-900">{{ __('Auto-reschedule rejected') }}</span>
                            <flux:switch wire:model.live="auto_reschedule_rejected" size="sm" />
                        </div>
                        @if ($this->auto_reschedule_rejected)
                            <div class="mt-4 border-t border-zinc-200 pt-4">
                                <flux:select wire:model="retry_tomorrow_at" :label="__('Retry tomorrow at')" size="sm">
                                    @foreach ($this->retryScheduleOptions() as $val => $lbl)
                                        <flux:select.option value="{{ $val }}">{{ $lbl }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="ussd_timeout_seconds" :label="__('USSD Timeout (s)')" type="number" min="5" max="300" size="sm" />
                        <div class="flex flex-col gap-2">
                            <span class="text-xs font-bold text-zinc-900">{{ __('Auto-Retry') }}</span>
                            <flux:switch wire:model.live="intelligent_auto_retry" size="sm" />
                        </div>
                    </div>

                    @if ($this->intelligent_auto_retry)
                        <div class="grid grid-cols-2 gap-4">
                            <flux:input wire:model="retry_interval_minutes" :label="__('Interval (min)')" type="number" min="1" max="60" size="sm" />
                            <flux:input wire:model="max_attempts" :label="__('Max Attempts')" type="number" min="1" max="10" size="sm" />
                        </div>
                    @endif

                    <div class="flex items-center justify-between rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-200">
                        <span class="text-xs font-bold text-zinc-900">{{ __('Aggressive Network Recovery') }}</span>
                        <flux:switch wire:model.live="retry_network_issues" size="sm" />
                    </div>
                </div>
            </form>
        </article>
    </div>
</section>
