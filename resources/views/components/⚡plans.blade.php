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

        app(FetchBingwaSubscriptionPlans::class)->forget($user);
        
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
                    @php
                        $isTimePack = $this->activePlan->type === 'time_unlimited';
                        $remainingTimeText = null;
                        $progressPercent = 0;
                        
                        if ($isTimePack) {
                            $created = $this->activePlan->created_at;
                            $expires = $this->activePlan->expires_at;
                            if ($created && $expires) {
                                $totalSec = $created->diffInSeconds($expires);
                                $elapsedSec = $created->diffInSeconds(now());
                                $progressPercent = max(0, min(100, (1 - ($elapsedSec / max(1, $totalSec))) * 100));
                                
                                $diff = now()->diff($expires);
                                if (now()->isAfter($expires)) {
                                    $remainingTimeText = __('Expired');
                                } else {
                                    $parts = [];
                                    if ($diff->d > 0) {
                                        $parts[] = trans_choice(':count day|:count days', $diff->d, ['count' => $diff->d]);
                                    }
                                    if ($diff->h > 0) {
                                        $parts[] = trans_choice(':count hr|:count hrs', $diff->h, ['count' => $diff->h]);
                                    }
                                    if ($diff->i > 0) {
                                        $parts[] = trans_choice(':count min|:count mins', $diff->i, ['count' => $diff->i]);
                                    }
                                    $remainingTimeText = count($parts) > 0 ? implode(' ', array_slice($parts, 0, 2)) . ' ' . __('left') : __('Expires soon');
                                }
                            } else {
                                $remainingTimeText = __('Never');
                                $progressPercent = 100;
                            }
                        } else {
                            $included = (int) $this->activePlan->ussd_requests_included;
                            $counter = (int) $this->activePlan->ussd_counter;
                            $remaining = max(0, $included - $counter);
                            $progressPercent = max(0, min(100, ($remaining / max(1, $included)) * 100));
                            $remainingTimeText = trans_choice(':count request left|:count requests left', $remaining, ['count' => $remaining]);
                        }
                    @endphp

                    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-500/8 to-emerald-500/2 p-5 shadow-sm border border-emerald-100/60">
                        <div class="absolute top-0 right-0 p-6 opacity-5">
                            <flux:icon.sparkles class="size-20 text-emerald-600 animate-pulse" />
                        </div>
                        
                        <div class="relative flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-500/10 text-emerald-700 shadow-inner">
                                    @if ($isTimePack)
                                        <flux:icon.clock class="size-5 text-emerald-600" />
                                    @else
                                        <flux:icon.banknotes class="size-5 text-emerald-600" />
                                    @endif
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] font-black uppercase tracking-[0.2em] text-emerald-700">{{ __('Active Subscription') }}</span>
                                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500 animate-ping"></span>
                                    </div>
                                    <h3 class="text-lg font-black text-zinc-900 mt-0.5 leading-tight">{{ $this->activePlan->name }}</h3>
                                </div>
                            </div>
                            <div class="rounded-xl bg-white px-3 py-1 text-[9px] font-black uppercase tracking-widest text-zinc-500 shadow-xs ring-1 ring-zinc-200">
                                {{ str_replace('_', ' ', $this->activePlan->type) }}
                            </div>
                        </div>

                        <!-- Progress Bar Section -->
                        <div class="relative mt-6">
                            <div class="flex items-center justify-between text-xs font-bold text-zinc-700 mb-2">
                                <span>{{ $isTimePack ? __('Time remaining') : __('Usage tokens remaining') }}</span>
                                <span class="text-emerald-700 font-extrabold">{{ $remainingTimeText }}</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-zinc-100 overflow-hidden ring-1 ring-zinc-200/50">
                                <div class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-green-600 transition-all duration-500" style="width: {{ $progressPercent }}%"></div>
                            </div>
                        </div>

                        <!-- Stats Grid -->
                        <div class="relative mt-5 grid grid-cols-2 gap-4 border-t border-emerald-100/40 pt-4">
                            <div>
                                <div class="text-[8px] font-black uppercase tracking-[0.2em] text-zinc-400">{{ __('Started') }}</div>
                                <div class="mt-1 text-xs font-extrabold text-zinc-900">
                                    {{ $this->activePlan->created_at ? AppTimezone::format($this->activePlan->created_at, 'd M, H:i') : __('N/A') }}
                                </div>
                            </div>

                            <div>
                                <div class="text-[8px] font-black uppercase tracking-[0.2em] text-zinc-400">{{ $isTimePack ? __('Expires') : __('Usage limit') }}</div>
                                <div class="mt-1 text-xs font-extrabold text-zinc-900">
                                    @if ($isTimePack)
                                        {{ $this->activePlan->expires_at ? AppTimezone::format($this->activePlan->expires_at, 'd M, H:i') : __('Never') }}
                                    @else
                                        {{ number_format((int) $this->activePlan->ussd_requests_included) }} {{ __('requests') }} ({{ number_format((int) $this->activePlan->ussd_counter) }} {{ __('used') }})
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 flex items-center gap-2 text-[10px] font-bold leading-normal text-zinc-500">
                            <flux:icon.lock-closed class="size-3.5 shrink-0 text-zinc-400" />
                            <span>{{ __('Purchasing remains locked until your active subscription plan expires or is fully depleted.') }}</span>
                        </div>
                    </div>
                @endif
                <div class="grid gap-3 grid-cols-1 sm:grid-cols-2">
                    @foreach ($this->plans as $plan)
                        @php
                            $planDelay = ($loop->index * 80) + 100;
                            $isSelected = $this->selectedPlanId === ($plan['id'] ?? null);
                            $isActive = $this->activePlan?->backend_plan_id === ($plan['id'] ?? null);
                            $isUsage = ($plan['type'] ?? '') === 'usage_pack';
                        @endphp

                        <article 
                            @class([
                                'plans-reveal group relative overflow-hidden rounded-2xl transition-all duration-300 transform hover:-translate-y-0.5 shadow-sm hover:shadow border',
                                'bg-gradient-to-br from-emerald-500/10 via-emerald-600/[0.03] to-green-600/[0.02] border-emerald-500 ring-2 ring-emerald-500/10' => $isSelected,
                                'bg-white border-zinc-200/90 hover:border-zinc-300' => !$isSelected,
                                'opacity-70 grayscale-[0.2]' => $this->activePlan && !$isActive
                            ]) 
                            style="animation-delay: {{ $planDelay }}ms"
                        >
                            <!-- Elegant top accent line for selected plan -->
                            @if ($isSelected)
                                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-emerald-500 to-green-600"></div>
                            @endif

                            <div class="p-3.5 flex flex-col justify-between h-full gap-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="space-y-1">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span class="text-sm font-black tracking-tight text-zinc-950 group-hover:text-emerald-700 transition-colors">
                                                {{ $plan['name'] ?? __('Plan') }}
                                            </span>
                                            @if ($isActive)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[8px] font-black tracking-wider text-emerald-700">
                                                    <span class="size-1 rounded-full bg-emerald-500 animate-ping"></span>
                                                    {{ __('ACTIVE') }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="inline-flex items-center gap-1 text-[8px] font-bold uppercase tracking-widest text-zinc-500 bg-zinc-100/80 px-2 py-0.5 rounded-full border border-zinc-200/60">
                                            {{ str_replace('_', ' ', $plan['type'] ?? 'PLAN') }}
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-lg font-black text-zinc-950 leading-tight">
                                            KES {{ number_format((float) ($plan['price'] ?? 0)) }}
                                        </div>
                                        <span class="text-[8px] font-bold uppercase tracking-widest text-zinc-400 block mt-0.5">{{ __('One-time') }}</span>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    @if ($isUsage && !is_null($plan['ussd_requests_included']))
                                        <div class="rounded-xl bg-zinc-50/80 p-2 border border-zinc-150 flex flex-col justify-center shadow-inner">
                                            <span class="text-[7px] font-bold uppercase tracking-wider text-zinc-400 block">{{ __('USSD requests') }}</span>
                                            <span class="text-[11px] font-extrabold text-zinc-850 mt-0.5 flex items-center gap-1">
                                                <flux:icon.banknotes class="size-3 text-emerald-600" />
                                                {{ number_format((int) $plan['ussd_requests_included']) }}
                                            </span>
                                        </div>
                                    @endif

                                    @if (!empty($plan['duration_days']))
                                        <div class="rounded-xl bg-zinc-50/80 p-2 border border-zinc-150 flex flex-col justify-center shadow-inner">
                                            <span class="text-[7px] font-bold uppercase tracking-wider text-zinc-400 block">{{ __('Duration') }}</span>
                                            <span class="text-[11px] font-extrabold text-zinc-850 mt-0.5 flex items-center gap-1">
                                                <flux:icon.clock class="size-3 text-emerald-600" />
                                                {{ trans_choice(':count day|:count days', (int) $plan['duration_days'], ['count' => (int) $plan['duration_days']]) }}
                                            </span>
                                        </div>
                                    @endif
                                </div>

                                <button
                                    type="button"
                                    wire:click="selectPlan({{ (int) ($plan['id'] ?? 0) }})"
                                    @disabled($this->activePlan !== null)
                                    @class([
                                        'flex h-9 w-full items-center justify-center rounded-xl text-[9px] font-black uppercase tracking-widest transition-all duration-200 transform active:scale-95 disabled:pointer-events-none disabled:opacity-50 border shadow-sm',
                                        'bg-gradient-to-r from-emerald-600 to-green-600 text-white border-transparent shadow-emerald-500/10 hover:shadow-md' => $isSelected,
                                        'bg-white text-zinc-700 border-zinc-200 hover:bg-zinc-50 hover:text-zinc-900' => !$isSelected,
                                    ])
                                >
                                    @if ($isActive)
                                        {{ __('CURRENT ACTIVE') }}
                                    @elseif ($this->activePlan)
                                        {{ __('LOCKED') }}
                                    @else
                                        {{ $isSelected ? __('SELECTED') : __('SELECT THIS PLAN') }}
                                    @endif
                                </button>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>

            @if ($this->selectedPlanId !== null)
                @php
                    $selectedPlan = collect($this->plans)->firstWhere('id', $this->selectedPlanId);
                @endphp

                @if (is_array($selectedPlan))
                    <!-- Centered Modal Wrapper with Glassmorphism Overlay -->
                    <div class="fixed inset-0 z-[70] flex items-center justify-center p-4 sm:p-6 bg-zinc-950/50 backdrop-blur-sm transition-all duration-300">
                        <button
                            type="button"
                            wire:click="$set('selectedPlanId', null)"
                            class="absolute inset-0 cursor-default bg-transparent"
                            aria-label="{{ __('Close selected plan dialog') }}"
                        ></button>

                        <div class="relative z-10 w-full max-w-md overflow-hidden rounded-[2.5rem] bg-white shadow-2xl ring-1 ring-zinc-200/80 plans-reveal">
                            @if ($this->purchaseInFlight)
                                <div class="absolute inset-0 z-30 flex items-center justify-center bg-white/90 px-6 backdrop-blur-sm">
                                    <div class="flex w-full max-w-xs flex-col items-center gap-4 rounded-3xl bg-white px-6 py-6 text-center shadow-2xl ring-1 ring-zinc-150">
                                        <flux:icon.loading variant="mini" class="size-8 text-emerald-600" />
                                        <div class="text-xs font-black uppercase tracking-[0.2em] text-zinc-950">
                                            {{ __('Processing purchase') }}
                                        </div>
                                        <div class="text-[10px] font-medium leading-relaxed text-zinc-500">
                                            {{ __('Keep the app open while your phone handles the USSD session.') }}
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="bg-gradient-to-br from-zinc-900 to-zinc-950 px-6 pb-5 pt-6 text-white border-b border-zinc-800">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <div class="text-[9px] font-bold uppercase tracking-[0.24em] text-emerald-400">{{ __('Confirm Purchase') }}</div>
                                        <div class="mt-2 text-xl font-black tracking-tight text-white">{{ $selectedPlan['name'] ?? __('Plan') }}</div>
                                        <div class="mt-1">
                                            <span class="inline-flex items-center gap-1 text-[9px] font-bold uppercase tracking-widest text-zinc-400 bg-zinc-800/80 px-2.5 py-0.5 rounded-full">
                                                {{ str_replace('_', ' ', $selectedPlan['type'] ?? 'PLAN') }}
                                            </span>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        wire:click="$set('selectedPlanId', null)"
                                        @disabled($this->purchaseInFlight)
                                        class="flex size-9 shrink-0 items-center justify-center rounded-2xl border-0 bg-white/5 text-white ring-1 ring-white/10 hover:bg-white/10 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50 transition-all"
                                        aria-label="{{ __('Close') }}"
                                    >
                                        <flux:icon.x-mark class="size-5" />
                                    </button>
                                </div>
                            </div>

                            <div @class(['space-y-6 px-6 py-6', 'opacity-40 pointer-events-none' => $this->purchaseInFlight])>
                                <div class="grid grid-cols-2 gap-3.5">
                                    <div class="rounded-2xl bg-zinc-50/80 px-4 py-3.5 border border-zinc-150 shadow-inner">
                                        <div class="text-[8px] font-bold uppercase tracking-[0.22em] text-zinc-500">{{ __('Price') }}</div>
                                        <div class="mt-1 text-xl font-black tracking-tight text-emerald-700">
                                            KES {{ number_format((float) ($selectedPlan['price'] ?? 0)) }}
                                        </div>
                                    </div>

                                    <div class="rounded-2xl bg-zinc-50/80 px-4 py-3.5 border border-zinc-150 shadow-inner">
                                        <div class="text-[8px] font-bold uppercase tracking-[0.22em] text-zinc-500">{{ __('Duration') }}</div>
                                        <div class="mt-1 text-sm font-black text-zinc-950">
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
                                    <div class="rounded-2xl bg-zinc-50/80 p-4 border border-zinc-150">
                                        <div class="text-[9px] font-bold uppercase tracking-[0.24em] text-zinc-600 mb-3 block">{{ __('Choose SIM Slot') }}</div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <button 
                                                type="button" 
                                                wire:click="$set('simSlot', 0)"
                                                @class([
                                                    'flex h-11 items-center justify-center rounded-xl text-xs font-black transition-all border',
                                                    'bg-emerald-600 text-white border-transparent shadow-sm shadow-emerald-500/20' => (int) $simSlot === 0,
                                                    'bg-white text-zinc-700 border-zinc-200 hover:bg-zinc-50' => (int) $simSlot !== 0,
                                                ])
                                            >
                                                {{ __('SIM 1') }}
                                            </button>
                                            <button 
                                                type="button" 
                                                wire:click="$set('simSlot', 1)"
                                                @class([
                                                    'flex h-11 items-center justify-center rounded-xl text-xs font-black transition-all border',
                                                    'bg-emerald-600 text-white border-transparent shadow-sm shadow-emerald-500/20' => (int) $simSlot === 1,
                                                    'bg-white text-zinc-700 border-zinc-200 hover:bg-zinc-50' => (int) $simSlot !== 1,
                                                ])
                                            >
                                                {{ __('SIM 2') }}
                                            </button>
                                        </div>
                                    </div>
                                @endif

                                <div class="flex flex-col gap-3 sm:flex-row">
                                    <button
                                        type="button"
                                        wire:click="$set('selectedPlanId', null)"
                                        @disabled($this->purchaseInFlight)
                                        class="flex h-12 w-full items-center justify-center rounded-2xl border border-zinc-200 bg-white text-[10px] font-black uppercase tracking-widest text-zinc-700 hover:bg-zinc-50 active:scale-95 transition-all disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ __('Cancel') }}
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="initiateSambaza"
                                        @disabled($this->purchaseInFlight)
                                        wire:loading.attr="disabled"
                                        wire:target="initiateSambaza"
                                        class="flex h-12 w-full items-center justify-center rounded-2xl bg-gradient-to-r from-emerald-600 to-green-600 text-[10px] font-black uppercase tracking-widest text-white shadow-md shadow-emerald-600/10 hover:opacity-95 active:scale-95 transition-all disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span wire:loading.remove wire:target="initiateSambaza" @class(['inline-flex items-center justify-center gap-2', 'hidden' => $this->purchaseInFlight])>
                                            {{ __('Confirm & Buy') }}
                                        </span>
                                        <span wire:loading wire:target="initiateSambaza" @class(['inline-flex items-center justify-center gap-2', 'hidden' => ! $this->purchaseInFlight])>
                                            <flux:icon.loading variant="mini" class="size-3.5" />
                                            {{ __('Processing…') }}
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
