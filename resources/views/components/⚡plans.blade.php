<?php

use App\Support\AppTimezone;
use App\Models\Plan;
use App\Actions\Autoreach\FetchBingwaSubscriptionPlans;
use App\Jobs\SyncRemoteSubscriptionPurchaseJob;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new #[Title('Subscriptions')] class extends Component {
    public bool $loaded = false;

    /** @var array<int, array<string, mixed>> */
    public array $plans = [];

    public ?Plan $activePlan = null;

    public ?string $errorMessage = null;

    public ?string $sambazaLine = null;
    public int $simSlot = 0; // 0 = SIM 1, 1 = SIM 2

    public ?int $selectedPlanId = null;

    public bool $permissionRequestInFlight = false;

    public bool $purchaseInFlight = false;

    public function mount(): void
    {
        $user = Auth::user();

        $this->simSlot = $user->deviceSetting?->primary_transaction_sim === 'slot_2' ? 1 : 0;
        $this->activePlan = $user->activePlan();

        $cachedPlans = app(FetchBingwaSubscriptionPlans::class)->cached($user);

        if (is_array($cachedPlans)) {
            $this->plans = $cachedPlans['plans'] ?? [];
            $this->sambazaLine = $cachedPlans['sambaza_line'] ?? null;
            $this->loaded = true;
        }
    }

    /**
     * Load the plans after the page has rendered.
     */
    public function loadPlans(bool $forceRefresh = false): void
    {
        \Illuminate\Support\Facades\Log::debug('⚡ Livewire: loadPlans triggered');

        $this->activePlan = Auth::user()->activePlan();

        $this->errorMessage = null;

        if (! $forceRefresh && $this->plans !== []) {
            $this->loaded = true;

            return;
        }

        try {
            $result = app(FetchBingwaSubscriptionPlans::class)->fetch(Auth::user(), $forceRefresh);

            $this->plans = $result['plans'];
            $this->sambazaLine = $result['sambaza_line'] ?? null;
            $this->loaded = true;
        } catch (ValidationException $exception) {
            $this->plans = [];
            $this->loaded = true;
            $this->errorMessage = collect($exception->errors())->flatten()->first() ?? __('Unable to load subscription plans right now.');
        } catch (RequestException $exception) {
            $this->plans = [];
            $this->loaded = true;
            $this->errorMessage = $exception->response->json('message') ?? __('Unable to load subscription plans right now.');
        }
    }

    /**
     * Force a fresh plans fetch and bypass the short cache window.
     */
    public function refreshPlans(): void
    {
        $this->loadPlans(true);
    }

    /**
     * Mark a plan as selected for the mobile flow.
     */
    public function selectPlan(int $planId): void
    {
        $this->selectedPlanId = $planId;
        $this->purchaseInFlight = false;
    }

    /**
     * Re-trigger Android setup permissions when the purchase flow detects a missing runtime permission.
     */
    public function requestSetupPermissions(): void
    {
        $this->permissionRequestInFlight = true;

        $this->js(<<<'JS'
            (async () => {
                try {
                    const response = await fetch('/_native/api/call', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        },
                        body: JSON.stringify({
                            method: 'RequestSetupPermissions',
                            params: {
                                force: true,
                                openSpecialSettings: false
                            }
                        })
                    });

                    const result = await response.json();
                    const payload = result.data?.data ?? result.data ?? {};
                    const missingPermissions = Array.isArray(payload.missingRuntimePermissions)
                        ? payload.missingRuntimePermissions
                        : [];

                    if (payload.requestedRuntimePermissions) {
                        $wire.set('errorMessage', 'Android permission prompt opened. Allow access, then try again.');
                    } else if (payload.runtimePermissionsGranted) {
                        $wire.set('errorMessage', null);
                    } else if (missingPermissions.length > 0) {
                        $wire.set('errorMessage', `Missing permissions: ${missingPermissions.join(', ')}`);
                    } else {
                        $wire.set('errorMessage', 'Permissions were not granted. Enable them in Android app settings and try again.');
                    }
                } catch (error) {
                    $wire.set('errorMessage', 'Unable to request Android permissions right now.');
                } finally {
                    $wire.set('permissionRequestInFlight', false);
                }
            })();
        JS);
    }

    /**
     * Trigger NativePHP bridge for manual Sambaza.
     */
    public function initiateSambaza(): void
    {
        \Illuminate\Support\Facades\Log::info('⚡ Livewire: initiateSambaza triggered', [
            'selectedPlanId' => $this->selectedPlanId,
            'activePlanId' => $this->activePlan?->backend_plan_id,
            'sambazaLine' => $this->sambazaLine
        ]);

        if ($this->purchaseInFlight) {
            return;
        }

        if ($this->activePlan) {
            $this->errorMessage = __('You already have an active subscription. Please wait for it to expire before purchasing another.');
            return;
        }

        $selectedPlan = collect($this->plans)->firstWhere('id', $this->selectedPlanId);
        if (! $selectedPlan || ! $this->sambazaLine) {
            return;
        }

        $this->purchaseInFlight = true;
        $this->errorMessage = null;

        $price = (int) ($selectedPlan['price'] ?? 0);
        $code = "*140*{$price}*{$this->sambazaLine}#";
        $planId = (int) ($selectedPlan['id'] ?? 0);

        $simSlotInt = (int) $this->simSlot;
        $codeJson = json_encode($code, JSON_UNESCAPED_SLASHES);

        $script = <<<JS
            (async () => {
                try {
                    const code = {$codeJson};

                    console.log('Initiating Sambaza:', { code, simSlot: {$simSlotInt} });
                    const response = await fetch('/_native/api/call', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]')?.content || ''
                        },
                        body: JSON.stringify({
                            method: 'TriggerSambaza',
                            params: { code, simSlot: {$simSlotInt} }
                        })
                    });

                    console.log('Sambaza response received:', response.status);
                    const res = await response.json();
                    console.log('Sambaza decoded response:', res);

                    // Unwrap nested data if present (BridgeRouter sometimes wraps twice)
                    let finalData = res.data || {};
                    if (finalData.data) finalData = finalData.data;

                    const message = (finalData.message || '').toLowerCase();
                    const isSuccess = (res.status === 'success' || finalData.status === 'success') &&
                                     (finalData.success === true || message.includes('transferred') || message.includes('successful'));

                    if (isSuccess) {
                        const paymentReference = finalData.payment_reference
                            || finalData.paymentReference
                            || finalData.transaction_reference
                            || finalData.transactionReference
                            || finalData.reference
                            || null;

                        await __WIRE__.saveSubscription({$planId}, paymentReference);
                    } else {
                        __WIRE__.set('errorMessage', finalData.message || res.message || 'Unknown error');
                    }
                } catch (e) {
                    __WIRE__.set('errorMessage', 'Error communicating with device: ' + e.message);
                } finally {
                    __WIRE__.set('purchaseInFlight', false);
                }
            })();
        JS;

        $this->js(str_replace('__WIRE__', '$wire', $script));
    }

    /**
     * Save the newly purchased subscription to the local database.
     */
    public function saveSubscription(int $planId, ?string $paymentReference = null): void
    {
        $selectedPlan = collect($this->plans)->firstWhere('id', $planId);
        if (! $selectedPlan) {
            $this->purchaseInFlight = false;

            return;
        }

        $user = Auth::user();

        // Deactivate all existing plans
        $user->plans()->update(['is_active' => false]);

        $type = $selectedPlan['type'] ?? 'usage_pack';
        $durationDays = (int) ($selectedPlan['duration_days'] ?? 0);
        
        $expiresAt = null;
        if ($type === 'time_unlimited' && $durationDays > 0) {
            $expiresAt = now()->addDays($durationDays);
        }

        $this->activePlan = $user->plans()->create([
            'backend_plan_id' => $selectedPlan['id'] ?? null,
            'code' => $selectedPlan['code'] ?? 'unknown',
            'name' => $selectedPlan['name'] ?? 'Unknown Plan',
            'type' => $type,
            'price' => (int) ($selectedPlan['price'] ?? 0),
            'duration_days' => $durationDays,
            'ussd_requests_included' => (int) ($selectedPlan['ussd_requests_included'] ?? 0),
            'ussd_counter' => 0,
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        SyncRemoteSubscriptionPurchaseJob::dispatch(
            (int) $this->activePlan->getKey(),
            filled($paymentReference) ? $paymentReference : null,
        );
        
        $this->selectedPlanId = null;
        $this->purchaseInFlight = false;
    }

};
?>

<section class="min-h-screen bg-app-bg px-4 pb-24 pt-3" wire:init="loadPlans">

    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between px-1">
            <div class="text-xl font-bold text-zinc-900">{{ __('Subscriptions') }}</div>
            <button
                type="button"
                wire:click="refreshPlans"
                wire:loading.attr="disabled"
                wire:target="refreshPlans"
                class="app-primary-button flex h-9 items-center gap-2 px-4 text-[10px] font-bold uppercase tracking-widest transition active:scale-95"
            >
                <span wire:loading.remove wire:target="refreshPlans" class="inline-flex items-center gap-2">
                    <flux:icon.arrow-path class="size-3.5" />
                    {{ __('Refresh') }}
                </span>
                <span wire:loading wire:target="refreshPlans" class="inline-flex items-center gap-2">
                    <flux:icon.loading variant="mini" class="size-3.5" />
                </span>
            </button>
        </div>

        @if (! $this->loaded)
            <div class="grid gap-4 md:grid-cols-2">
                @for ($i = 0; $i < 4; $i++)
                <div class="relative overflow-hidden rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200">
                        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-green-500/5 to-transparent motion-safe:animate-[pulse_1.8s_ease-in-out_infinite]"></div>
                        <div class="relative h-4 w-24 rounded bg-zinc-100"></div>
                        <div class="relative mt-4 h-6 w-40 rounded bg-zinc-100"></div>
                        <div class="relative mt-3 h-4 w-full rounded bg-zinc-100/70"></div>
                        <div class="relative mt-2 h-4 w-2/3 rounded bg-zinc-100/70"></div>
                    </div>
                @endfor
            </div>
        @elseif ($this->errorMessage)
            <div class="relative overflow-hidden rounded-[1.5rem] bg-rose-50 p-6 shadow-sm ring-1 ring-rose-100">
                <div class="flex items-start gap-4">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-rose-600 text-white shadow-lg shadow-rose-600/20">
                        <flux:icon.exclamation-triangle class="size-6" />
                    </div>
                    <div class="flex-1 space-y-1 py-1">
                        <flux:heading size="sm" class="text-xs font-black uppercase tracking-[0.2em] text-rose-600">{{ __('Action Required') }}</flux:heading>
                        <flux:text class="text-sm font-bold text-rose-700 leading-relaxed">
                            {{ $this->errorMessage }}
                        </flux:text>
                    </div>
                    <div class="mt-1 flex shrink-0 items-center gap-2">
                        @if (str_contains($this->errorMessage, 'permission'))
                            <button
                                type="button"
                                wire:click="requestSetupPermissions"
                                wire:loading.attr="disabled"
                                wire:target="requestSetupPermissions"
                                class="app-secondary-button h-9 px-4 text-[9px] font-black uppercase tracking-[0.18em] text-rose-600 hover:text-rose-700"
                            >
                                <span wire:loading.remove wire:target="requestSetupPermissions">{{ __('Grant Access') }}</span>
                                <span wire:loading wire:target="requestSetupPermissions">{{ __('Opening…') }}</span>
                            </button>
                        @endif

                        <button type="button" wire:click="$set('errorMessage', null)" class="app-secondary-button h-9 w-9 p-0 text-rose-500 hover:text-rose-700 transition-colors">
                            <flux:icon.x-mark class="size-5" />
                        </button>
                    </div>
                </div>
            </div>
        @elseif ($this->plans === [])
            <div class="rounded-xl bg-white p-6 text-center ring-1 ring-zinc-200 shadow-sm">
                <div class="text-sm font-medium text-zinc-500">
                    {{ __('No active subscription plans were returned for this device right now.') }}
                </div>
            </div>
        @else
            <div class="flex flex-col gap-4">
                
                @if ($this->activePlan)
                    <div class="relative overflow-hidden rounded-xl bg-white p-4 shadow-sm ring-1 ring-green-100">
                        <div class="absolute inset-y-0 left-0 w-1.5 bg-green-500"></div>
                        <div class="absolute top-0 right-0 p-6 opacity-10">
                            <flux:icon.sparkles class="size-16 text-green-600" />
                        </div>
                        
                        <div class="relative flex items-start justify-between">
                            <div>
                                <div class="text-[10px] font-bold uppercase tracking-widest text-green-700/70">{{ __('Active Subscription') }}</div>
                                <div class="mt-1 text-base font-bold text-zinc-900">{{ $this->activePlan->name }}</div>
                            </div>
                            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-green-50 text-green-700 ring-1 ring-green-100">
                                <flux:icon.check class="size-5" />
                            </div>
                        </div>

                        <div class="relative mt-6 flex gap-4">
                            <div class="flex-1 rounded-2xl bg-zinc-50 p-3 ring-1 ring-zinc-100">
                                <div class="text-[8px] font-black uppercase tracking-[0.2em] text-zinc-500">{{ __('Started') }}</div>
                                <div class="mt-1 text-[11px] font-black text-zinc-950">
                                    {{ $this->activePlan->created_at ? AppTimezone::format($this->activePlan->created_at, 'd M, H:i') : __('N/A') }}
                                </div>
                            </div>

                            <div class="flex-1 rounded-2xl bg-zinc-50 p-3 ring-1 ring-zinc-100">
                                <div class="text-[8px] font-black uppercase tracking-[0.2em] text-zinc-500">{{ __('Expires') }}</div>
                                <div class="mt-1 text-[11px] font-black text-zinc-950">
                                    {{ $this->activePlan->expires_at ? AppTimezone::format($this->activePlan->expires_at, 'd M, H:i') : __('Never') }}
                                </div>
                            </div>
                        </div>

                        <p class="mt-4 text-[11px] font-medium leading-relaxed text-zinc-500">
                            {{ __('The current catalog stays visible for reference, but purchasing stays locked until this plan expires.') }}
                        </p>
                    </div>
                @endif

                @foreach ($this->plans as $plan)
                    @php
                        $planDelay = ($loop->index * 90) + 120;
                    @endphp

                    <article @class([
                        'plans-reveal relative overflow-hidden rounded-xl transition duration-300',
                        'bg-white ring-2 ring-green-500 shadow-md' => $this->selectedPlanId === ($plan['id'] ?? null),
                        'bg-white shadow-sm ring-1 ring-zinc-200' => $this->selectedPlanId !== ($plan['id'] ?? null),
                        'opacity-40 grayscale-[0.8]' => $this->activePlan && ($this->activePlan->backend_plan_id !== ($plan['id'] ?? null))
                    ]) style="animation-delay: {{ $planDelay }}ms">
                        
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2">
                                        <div class="text-base font-bold tracking-tight text-zinc-900">{{ $plan['name'] ?? __('Plan') }}</div>
                                        @if ($this->activePlan?->backend_plan_id === ($plan['id'] ?? null))
                                            <div class="flex size-4 items-center justify-center rounded-full bg-green-500 text-white shadow-sm">
                                                <flux:icon.check class="size-3" />
                                            </div>
                                        @endif
                                    </div>
                                    <div class="text-[9px] font-bold uppercase tracking-widest text-zinc-500">{{ str_replace('_', ' ', $plan['type'] ?? 'PLAN') }}</div>
                                </div>
                                <div class="flex flex-col items-end">
                                    <div class="text-base font-bold text-green-700">KES {{ number_format((float) ($plan['price'] ?? 0)) }}</div>
                                    <div class="text-[8px] font-bold uppercase tracking-widest text-zinc-600">{{ __('PRICE') }}</div>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 mb-6">
                                @if (($plan['type'] ?? null) === 'usage_pack' && ! is_null($plan['ussd_requests_included']))
                                    <div class="rounded-xl bg-zinc-50 p-3 ring-1 ring-zinc-200">
                                        <div class="text-[8px] font-bold uppercase tracking-widest text-zinc-600">{{ __('USSD requests') }}</div>
                                        <div class="mt-1 text-xs font-bold text-zinc-900">{{ number_format((int) $plan['ussd_requests_included']) }}</div>
                                    </div>
                                @endif

                                @if (! empty($plan['duration_days']))
                                    <div class="rounded-xl bg-zinc-50 p-3 ring-1 ring-zinc-200">
                                        <div class="text-[8px] font-bold uppercase tracking-widest text-zinc-600">{{ __('Duration') }}</div>
                                        <div class="mt-1 text-xs font-bold text-zinc-900">
                                            {{ trans_choice(':count day|:count days', (int) $plan['duration_days'], ['count' => (int) $plan['duration_days']]) }}
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <button
                                @class([
                                    'flex h-10 w-full items-center justify-center rounded-xl text-[10px] font-bold uppercase tracking-widest shadow-sm transition active:scale-95 disabled:pointer-events-none disabled:opacity-50 ring-1 ring-inset',
                                    'bg-green-600 text-white ring-green-700/20' => $this->selectedPlanId === ($plan['id'] ?? null),
                                    'bg-white text-zinc-700 ring-zinc-200 hover:bg-zinc-50' => $this->selectedPlanId !== ($plan['id'] ?? null),
                            ])
                            type="button"
                            wire:click="selectPlan({{ (int) ($plan['id'] ?? 0) }})"
                            @disabled($this->activePlan !== null)
                        >
                                @if ($this->activePlan?->backend_plan_id === ($plan['id'] ?? null))
                                    {{ __('ACTIVE') }}
                                @elseif ($this->activePlan)
                                    {{ __('LOCKED') }}
                                @else
                                    {{ $this->selectedPlanId === ($plan['id'] ?? null) ? __('SELECTED') : __('SELECT PLAN') }}
                                @endif
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($this->selectedPlanId !== null)
                @php
                    $selectedPlan = collect($this->plans)->firstWhere('id', $this->selectedPlanId);
                @endphp

                @if (is_array($selectedPlan))
                    <div class="fixed inset-0 z-[70] flex items-end justify-center bg-zinc-950/45 p-4 backdrop-blur-sm sm:items-center sm:p-6">
                        <button
                            type="button"
                            wire:click="$set('selectedPlanId', null)"
                            class="absolute inset-0 cursor-default"
                            aria-label="{{ __('Close selected plan dialog') }}"
                        ></button>

                        <div class="relative z-10 w-full max-w-xl overflow-hidden rounded-[2rem] bg-white shadow-2xl ring-1 ring-zinc-200 plans-reveal">
                            @if ($this->purchaseInFlight)
                                <div class="absolute inset-0 z-30 flex items-center justify-center bg-white/80 px-6 backdrop-blur-sm">
                                    <div class="flex w-full max-w-sm flex-col items-center gap-3 rounded-[1.5rem] bg-white px-6 py-5 text-center shadow-xl ring-1 ring-zinc-200">
                                        <flux:icon.loading variant="mini" class="size-7 text-green-600" />
                                        <div class="text-sm font-black uppercase tracking-[0.18em] text-zinc-900">
                                            {{ __('Processing purchase') }}
                                        </div>
                                        <div class="text-xs font-medium leading-relaxed text-zinc-500">
                                            {{ __('Keep the app open while your phone handles the USSD session.') }}
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="bg-app-shell px-6 pb-5 pt-6 text-white">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <div class="text-[10px] font-bold uppercase tracking-[0.24em] text-green-300/80">{{ __('Purchase Plan') }}</div>
                                        <div class="mt-2 text-2xl font-black tracking-tight text-white">{{ $selectedPlan['name'] ?? __('Plan') }}</div>
                                        <div class="mt-1 text-[11px] font-bold uppercase tracking-[0.2em] text-white/55">
                                            {{ str_replace('_', ' ', $selectedPlan['type'] ?? 'PLAN') }}
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        wire:click="$set('selectedPlanId', null)"
                                        @disabled($this->purchaseInFlight)
                                        class="app-secondary-button flex size-11 shrink-0 items-center justify-center rounded-2xl border-0 bg-white/8 text-white ring-1 ring-white/10 hover:bg-white/14 disabled:cursor-not-allowed disabled:opacity-50"
                                        aria-label="{{ __('Close') }}"
                                    >
                                        <flux:icon.x-mark class="size-5" />
                                    </button>
                                </div>
                            </div>

                            <div @class(['space-y-6 px-6 py-6', 'opacity-40 pointer-events-none' => $this->purchaseInFlight])>
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="rounded-2xl bg-zinc-50 px-4 py-4 ring-1 ring-zinc-200">
                                        <div class="text-[9px] font-bold uppercase tracking-[0.22em] text-zinc-500">{{ __('Price') }}</div>
                                        <div class="mt-2 text-2xl font-black tracking-tight text-green-700">
                                            KES {{ number_format((float) ($selectedPlan['price'] ?? 0)) }}
                                        </div>
                                    </div>

                                    <div class="rounded-2xl bg-zinc-50 px-4 py-4 ring-1 ring-zinc-200">
                                        <div class="text-[9px] font-bold uppercase tracking-[0.22em] text-zinc-500">{{ __('Duration') }}</div>
                                        <div class="mt-2 text-lg font-black text-zinc-950">
                                            @if (! empty($selectedPlan['duration_days']))
                                                {{ trans_choice(':count day|:count days', (int) $selectedPlan['duration_days'], ['count' => (int) $selectedPlan['duration_days']]) }}
                                            @elseif (($selectedPlan['type'] ?? null) === 'usage_pack' && ! is_null($selectedPlan['ussd_requests_included']))
                                                {{ number_format((int) $selectedPlan['ussd_requests_included']) }} {{ __('requests') }}
                                            @else
                                                {{ __('N/A') }}
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                @if ($this->sambazaLine)
                                    <div class="rounded-[1.75rem] bg-zinc-50 p-4 ring-1 ring-zinc-200">
                                        <div class="text-[10px] font-bold uppercase tracking-[0.24em] text-zinc-600">{{ __('Choose SIM Slot') }}</div>
                                        <flux:radio.group wire:model="simSlot" variant="segmented" class="mt-4 h-12 w-full rounded-2xl bg-white p-1 ring-1 ring-zinc-200">
                                            <flux:radio value="0" label="{{ __('SIM 1') }}" class="font-bold text-zinc-700 text-sm" />
                                            <flux:radio value="1" label="{{ __('SIM 2') }}" class="font-bold text-zinc-700 text-sm" />
                                        </flux:radio.group>
                                    </div>
                                @endif

                                <div class="flex flex-col gap-3 sm:flex-row">
                                    <button
                                        type="button"
                                        wire:click="$set('selectedPlanId', null)"
                                        @disabled($this->purchaseInFlight)
                                        class="app-secondary-button flex h-12 w-full items-center justify-center text-[10px] font-bold uppercase tracking-widest sm:w-40 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ __('Cancel') }}
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="initiateSambaza"
                                        @disabled($this->purchaseInFlight)
                                        wire:loading.attr="disabled"
                                        wire:target="initiateSambaza"
                                        class="app-primary-button flex h-12 w-full items-center justify-center text-[10px] font-bold uppercase tracking-widest disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span wire:loading.remove wire:target="initiateSambaza" @class(['inline-flex items-center justify-center gap-2', 'hidden' => $this->purchaseInFlight])>
                                            {{ __('Purchase Now') }}
                                        </span>
                                        <span wire:loading wire:target="initiateSambaza" @class(['inline-flex items-center justify-center gap-2', 'hidden' => ! $this->purchaseInFlight])>
                                            <flux:icon.loading variant="mini" class="size-3.5" />
                                            {{ __('Purchasing…') }}
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        @endif
    </div>
</section>
