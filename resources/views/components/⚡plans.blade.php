<?php

use App\Models\Plan;
use App\Actions\Autoreach\FetchBingwaSubscriptionPlans;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public bool $loaded = false;

    /** @var array<int, array<string, mixed>> */
    public array $plans = [];

    public ?Plan $activePlan = null;

    public ?string $errorMessage = null;

    public ?string $sambazaLine = null;
    public int $simSlot = 0; // 0 = SIM 1, 1 = SIM 2

    public ?int $selectedPlanId = null;

    /**
     * Load the plans after the page has rendered.
     */
    public function loadPlans(): void
    {
        $this->loaded = true;
        $this->errorMessage = null;

        try {
            $result = app(FetchBingwaSubscriptionPlans::class)->fetch(Auth::user());

            $this->plans = $result['plans'];
            $this->sambazaLine = $result['sambaza_line'] ?? null;
            
            $this->activePlan = Auth::user()->plans()->where('is_active', true)->first();
        } catch (ValidationException $exception) {
            $this->plans = [];
            $this->errorMessage = collect($exception->errors())->flatten()->first() ?? __('Unable to load subscription plans right now.');
        }
    }

    /**
     * Mark a plan as selected for the mobile flow.
     */
    public function selectPlan(int $planId): void
    {
        $this->selectedPlanId = $planId;
    }

    /**
     * Trigger NativePHP bridge for manual Sambaza.
     */
    public function initiateSambaza(): void
    {
        $selectedPlan = collect($this->plans)->firstWhere('id', $this->selectedPlanId);
        if (!$selectedPlan || !$this->sambazaLine) return;

        $price = (int) ($selectedPlan['price'] ?? 0);
        $code = "*140*{$price}*{$this->sambazaLine}#";

        $this->js("
            fetch('/_native/api/call', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]')?.content || ''
                },
                body: JSON.stringify({
                    method: 'TriggerSambaza',
                    params: {
                        code: '{$code}',
                        simSlot: {$this->simSlot}
                    }
                })
            }).then(res => res.json()).then(res => {
                if(res.status === 'success' && res.data && res.data.success) {
                    @this.saveSubscription({$selectedPlan['id']});
                    alert('Sambaza successful! Transfer complete.');
                } else {
                    alert('Sambaza failed: ' + (res.data?.message || res.message || 'Unknown error'));
                }
            }).catch(e => alert('Error communicating with device: ' + e.message));
        ");
    }

    /**
     * Save the newly purchased subscription to the local database.
     */
    public function saveSubscription(int $planId): void
    {
        $selectedPlan = collect($this->plans)->firstWhere('id', $planId);
        if (!$selectedPlan) return;

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
        
        $this->selectedPlanId = null;
    }

};
?>

<section class="w-full p-4 md:p-6" wire:init="loadPlans">
    <style>
        @keyframes plans-reveal {
            from {
                opacity: 0;
                transform: translateY(12px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .plans-reveal {
            animation: plans-reveal 420ms ease-out both;
        }
    </style>

    <div class="flex flex-col gap-4">
        <div class="relative overflow-hidden rounded-3xl border border-emerald-800 bg-gradient-to-br from-emerald-950 via-emerald-900 to-zinc-900 p-5 text-white shadow-lg dark:border-emerald-700 md:p-6">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -top-16 -right-10 h-40 w-40 rounded-full bg-emerald-400/15 blur-3xl motion-safe:animate-pulse"></div>
                <div class="absolute -bottom-16 -left-10 h-44 w-44 rounded-full bg-zinc-400/10 blur-3xl motion-safe:animate-pulse" style="animation-delay: 300ms;"></div>
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/30 to-transparent"></div>
            </div>

            <div class="relative flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400 shadow-[0_0_0_6px_rgba(16,185,129,0.15)] motion-safe:animate-pulse"></span>
                    <span class="text-[10px] font-bold uppercase tracking-[0.25em] text-emerald-300/60">{{ __('Subscriptions') }}</span>
                </div>

                <div class="flex items-start justify-between gap-3">
                    <div>
                        <flux:heading size="xl" class="text-white">{{ __('Bingwa Plans') }}</flux:heading>
                        <flux:text class="max-w-2xl text-emerald-100/80">
                            {{ __('Choose a plan to enable automated USSD execution and tracking.') }}
                        </flux:text>
                    </div>

                    <flux:button
                        type="button"
                        variant="ghost"
                        class="shrink-0 border border-white/15 bg-white/5 text-white hover:bg-white/10"
                        wire:click="loadPlans"
                    >
                        {{ __('Refresh') }}
                    </flux:button>
                </div>
            </div>
        </div>

        @if (! $this->loaded)
            <div class="grid gap-4 md:grid-cols-2">
                @for ($i = 0; $i < 4; $i++)
                    <div class="relative overflow-hidden rounded-3xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/40 to-transparent opacity-0 motion-safe:animate-[pulse_1.8s_ease-in-out_infinite] dark:via-white/5"></div>
                        <div class="relative h-4 w-24 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                        <div class="relative mt-4 h-6 w-40 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                        <div class="relative mt-3 h-4 w-full rounded bg-zinc-200 dark:bg-zinc-700"></div>
                        <div class="relative mt-2 h-4 w-2/3 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </div>
                @endfor
            </div>
        @elseif ($this->errorMessage)
            <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 px-4 py-4 text-sm text-rose-50">
                {{ $this->errorMessage }}
            </div>
        @elseif ($this->plans === [])
            <div class="rounded-2xl border border-zinc-200 bg-white px-4 py-4 text-sm text-zinc-600 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                {{ __('No active subscription plans were returned for this device right now.') }}
            </div>
        @else
            <div class="flex flex-col gap-4">
                
                @if ($this->activePlan)
                    <div class="rounded-3xl border border-emerald-100 bg-emerald-50/50 p-5 dark:border-emerald-800/30 dark:bg-emerald-900/10">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">{{ __('Active Subscription') }}</div>
                                <flux:heading size="lg" class="mt-1 text-zinc-900 dark:text-white">{{ $this->activePlan->name }}</flux:heading>
                            </div>
                            <div class="rounded-full bg-emerald-500 px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-white shadow-sm">
                                {{ __('Active') }}
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-4">
                            <div class="rounded-2xl bg-white p-3 shadow-sm dark:bg-zinc-800/50">
                                <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ $this->activePlan->type === 'usage_pack' ? __('Usage Left') : __('Expires') }}</div>
                                <div class="mt-1 text-sm font-bold text-zinc-900 dark:text-white">
                                    @if ($this->activePlan->type === 'usage_pack')
                                        {{ max(0, $this->activePlan->ussd_requests_included - $this->activePlan->ussd_counter) }} {{ __('Tokens') }}
                                    @else
                                        {{ $this->activePlan->expires_at?->diffForHumans() ?? __('Never') }}
                                    @endif
                                </div>
                            </div>

                            <div class="rounded-2xl bg-white p-3 shadow-sm dark:bg-zinc-800/50">
                                <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ __('Network Mode') }}</div>
                                <div class="mt-1 text-sm font-bold text-zinc-900 dark:text-white">{{ __('Express') }}</div>
                            </div>
                        </div>
                    </div>
                @endif

                @foreach ($this->plans as $plan)
                    @php
                        $planDelay = ($loop->index * 90) + 120;
                    @endphp

                    <article @class([
                        'plans-reveal rounded-3xl border bg-white p-5 shadow-sm transition-colors dark:bg-zinc-900',
                        'border-emerald-500 ring-2 ring-emerald-500/20' => $this->selectedPlanId === ($plan['id'] ?? null),
                        'border-zinc-200 dark:border-zinc-700' => $this->selectedPlanId !== ($plan['id'] ?? null),
                    ]) style="animation-delay: {{ $planDelay }}ms">
                        <div class="flex items-start justify-between gap-4">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <flux:heading size="lg">{{ $plan['name'] ?? __('Unnamed plan') }}</flux:heading>
                                    @if ($this->selectedPlanId === ($plan['id'] ?? null))
                                        <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                                            {{ __('Selected') }}
                                        </span>
                                    @endif
                                </div>

                                @if (! empty($plan['code']))
                                    <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        {{ $plan['code'] }}
                                    </div>
                                @endif
                            </div>

                            <div class="rounded-full bg-emerald-500/10 px-3 py-1 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                                {{ __('KES :price', ['price' => number_format((float) ($plan['price'] ?? 0))]) }}
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            @if (! empty($plan['type']))
                                <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                    {{ str_replace('_', ' ', $plan['type']) }}
                                </span>
                            @endif
                        </div>

                        <dl class="mt-5 grid gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                            @if (($plan['type'] ?? null) === 'usage_pack' && ! is_null($plan['ussd_requests_included']))
                                <div class="flex items-center justify-between gap-3">
                                    <dt>{{ __('USSD requests') }}</dt>
                                    <dd class="font-medium text-zinc-950 dark:text-zinc-50">{{ number_format((int) $plan['ussd_requests_included']) }}</dd>
                                </div>
                            @endif

                            @if (($plan['type'] ?? null) === 'time_unlimited' && ! empty($plan['duration_days']))
                                <div class="flex items-center justify-between gap-3">
                                    <dt>{{ __('Duration') }}</dt>
                                    <dd class="font-medium text-zinc-950 dark:text-zinc-50">
                                        {{ trans_choice(':count day|:count days', (int) $plan['duration_days'], ['count' => (int) $plan['duration_days']]) }}
                                    </dd>
                                </div>
                            @endif
                        </dl>

                        <div class="mt-5">
                            <flux:button
                                class="w-full"
                                type="button"
                                variant="{{ $this->selectedPlanId === ($plan['id'] ?? null) ? 'primary' : 'ghost' }}"
                                wire:click="selectPlan({{ (int) ($plan['id'] ?? 0) }})"
                            >
                                {{ $this->selectedPlanId === ($plan['id'] ?? null) ? __('Selected plan') : __('Select plan') }}
                            </flux:button>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($this->selectedPlanId !== null)
                @php
                    $selectedPlan = collect($this->plans)->firstWhere('id', $this->selectedPlanId);
                @endphp

                @if (is_array($selectedPlan))
                    <div class="sticky bottom-4 z-10 rounded-3xl border border-zinc-200 bg-white/95 p-4 shadow-lg backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Selected plan') }}</div>
                                <div class="mt-1 font-semibold text-zinc-950 dark:text-zinc-50">{{ $selectedPlan['name'] ?? __('Unnamed plan') }}</div>
                                <div class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('KES :price', ['price' => number_format((float) ($selectedPlan['price'] ?? 0))]) }}</div>
                            </div>

                            @if ($this->sambazaLine)
                                <div class="flex items-center gap-4">
                                    <flux:radio.group wire:model="simSlot" variant="segmented" class="max-w-xs">
                                        <flux:radio value="0" label="{{ __('SIM 1') }}" />
                                        <flux:radio value="1" label="{{ __('SIM 2') }}" />
                                    </flux:radio.group>
                                    
                                    <flux:button type="button" variant="primary" wire:click="initiateSambaza" class="whitespace-nowrap">
                                        {{ __('Purchase via Sambaza') }}
                                    </flux:button>
                                </div>
                            @endif

                            <flux:button type="button" variant="ghost" wire:click="$set('selectedPlanId', null)">
                                {{ __('Clear') }}
                            </flux:button>
                        </div>
                    </div>
                @endif
            @endif
        @endif
    </div>
</section>
