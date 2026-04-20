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
        \Illuminate\Support\Facades\Log::debug('⚡ Livewire: loadPlans triggered');
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
        \Illuminate\Support\Facades\Log::info('⚡ Livewire: initiateSambaza triggered', [
            'selectedPlanId' => $this->selectedPlanId,
            'activePlanId' => $this->activePlan?->backend_plan_id,
            'sambazaLine' => $this->sambazaLine
        ]);

        if ($this->activePlan) {
            $this->errorMessage = __('You already have an active subscription. Please wait for it to expire before purchasing another.');
            return;
        }

        $selectedPlan = collect($this->plans)->firstWhere('id', $this->selectedPlanId);
        if (!$selectedPlan || !$this->sambazaLine) return;

        $price = (int) ($selectedPlan['price'] ?? 0);
        $code = "*140*{$price}*{$this->sambazaLine}#";

        $this->js("
            console.log('Initiating Sambaza:', { code: '{$code}', simSlot: {$this->simSlot} });
            fetch('/_native/api/call', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]')?.content || ''
                },
                body: JSON.stringify({
                    method: 'TriggerSambaza',
                    params: { code: '{$code}', simSlot: {$this->simSlot} }
                })
            }).then(res => {
                console.log('Sambaza response received:', res.status);
                return res.json();
            }).then(res => {
                console.log('Sambaza decoded response:', res);
                
                // Unwrap nested data if present (BridgeRouter sometimes wraps twice)
                let finalData = res.data || {};
                if (finalData.data) finalData = finalData.data;

                const message = (finalData.message || '').toLowerCase();
                const isSuccess = (res.status === 'success' || finalData.status === 'success') && 
                                 (finalData.success === true || message.includes('transferred') || message.includes('successful'));

                if(isSuccess) {
                    \$wire.saveSubscription({$selectedPlan['id']});
                } else {
                    \$wire.set('errorMessage', finalData.message || res.message || 'Unknown error');
                }
            }).catch(e => \$wire.set('errorMessage', 'Error communicating with device: ' + e.message));
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

<section class="w-full p-4 md:p-6 bg-slate-950 min-h-screen" wire:init="loadPlans">
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
            animation: plans-reveal 500ms cubic-bezier(0.16, 1, 0.3, 1) both;
        }
    </style>

    <div class="flex flex-col gap-4">
        <div class="relative overflow-hidden rounded-[2.5rem] bg-slate-900 p-6 shadow-2xl ring-1 ring-slate-800 md:p-8">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -top-16 -right-10 h-40 w-40 rounded-full bg-teal-500/5 blur-3xl"></div>
                <div class="absolute -bottom-16 -left-10 h-44 w-44 rounded-full bg-indigo-500/5 blur-3xl"></div>
            </div>

            <div class="relative flex flex-col gap-4">
                <div class="flex items-center gap-2">
                    <div class="flex h-6 w-6 items-center justify-center rounded-lg bg-teal-500/10">
                        <flux:icon.sparkles class="size-3.5 text-teal-400" />
                    </div>
                    <span class="text-[10px] font-black uppercase tracking-[0.3em] text-teal-400/40">{{ __('Subscriptions') }}</span>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="space-y-1">
                        <flux:heading size="xl" class="text-3xl font-black tracking-tight text-white">{{ __('Bingwa Plans') }}</flux:heading>
                        <flux:text class="max-w-lg text-sm font-medium text-slate-500">
                            {{ __('Automate your USSD operations with a premium plan.') }}
                        </flux:text>
                    </div>

                    <button
                        type="button"
                        wire:click="loadPlans"
                        class="group flex h-11 items-center justify-center gap-3 rounded-2xl bg-slate-900 px-5 text-xs font-black uppercase tracking-widest text-slate-100 shadow-xl ring-1 ring-slate-800 transition-all hover:ring-indigo-500/30 active:scale-95"
                    >
                        <flux:icon.arrow-path class="size-4 text-indigo-400 transition-transform group-hover:rotate-180 duration-500" />
                        {{ __('Refresh') }}
                    </button>
                </div>
            </div>
        </div>

        @if (! $this->loaded)
            <div class="grid gap-4 md:grid-cols-2">
                @for ($i = 0; $i < 4; $i++)
                    <div class="relative overflow-hidden rounded-[2.5rem] bg-slate-900 p-6 shadow-2xl ring-1 ring-slate-800">
                        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-teal-500/5 to-transparent motion-safe:animate-[pulse_1.8s_ease-in-out_infinite]"></div>
                        <div class="relative h-4 w-24 rounded bg-slate-800"></div>
                        <div class="relative mt-4 h-6 w-40 rounded bg-slate-800"></div>
                        <div class="relative mt-3 h-4 w-full rounded bg-slate-800/50"></div>
                        <div class="relative mt-2 h-4 w-2/3 rounded bg-slate-800/50"></div>
                    </div>
                @endfor
            </div>
        @elseif ($this->errorMessage)
            <div class="relative overflow-hidden rounded-[2rem] bg-rose-950/20 p-6 shadow-2xl ring-1 ring-rose-900/50">
                <div class="flex items-start gap-4">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-rose-600 text-white shadow-lg shadow-rose-600/20">
                        <flux:icon.exclamation-triangle class="size-6" />
                    </div>
                    <div class="flex-1 space-y-1 py-1">
                        <flux:heading size="sm" class="text-xs font-black uppercase tracking-[0.2em] text-rose-400">{{ __('Action Required') }}</flux:heading>
                        <flux:text class="text-sm font-bold text-rose-100/70 leading-relaxed">
                            {{ $this->errorMessage }}
                        </flux:text>
                    </div>
                    <button type="button" wire:click="$set('errorMessage', null)" class="mt-1 p-1 text-rose-900 hover:text-rose-500 transition-colors">
                        <flux:icon.x-mark class="size-5" />
                    </button>
                </div>
            </div>
        @elseif ($this->plans === [])
            <div class="rounded-[2rem] bg-slate-900 p-8 text-center ring-1 ring-slate-800 shadow-2xl">
                <div class="text-sm font-bold text-slate-500">
                    {{ __('No active subscription plans were returned for this device right now.') }}
                </div>
            </div>
        @else
            <div class="flex flex-col gap-4">
                
                @if ($this->activePlan)
                    <div class="relative overflow-hidden rounded-[2.5rem] bg-indigo-600 p-6 shadow-[0_20px_40px_-15px_rgba(79,70,229,0.3)]">
                        <div class="absolute top-0 right-0 p-6 opacity-10">
                            <flux:icon.sparkles class="size-16 text-white" />
                        </div>
                        
                        <div class="relative flex items-start justify-between">
                            <div>
                                <div class="text-[10px] font-black uppercase tracking-[0.3em] text-white/70">{{ __('Active Subscription') }}</div>
                                <flux:heading size="lg" class="mt-2 text-2xl font-black text-white tracking-tight">{{ $this->activePlan->name }}</flux:heading>
                            </div>
                            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-white/20 backdrop-blur-md text-white ring-1 ring-white/30">
                                <flux:icon.check class="size-5" />
                            </div>
                        </div>

                        <div class="relative mt-6 flex gap-4">
                            <div class="flex-1 rounded-2xl bg-white/10 p-3 backdrop-blur-md ring-1 ring-white/10">
                                <div class="text-[8px] font-black uppercase tracking-[0.2em] text-white/60">{{ __('Started') }}</div>
                                <div class="mt-1 text-[11px] font-black text-white">
                                    {{ $this->activePlan->created_at?->format('d M, H:i') ?? __('N/A') }}
                                </div>
                            </div>

                            <div class="flex-1 rounded-2xl bg-white/10 p-3 backdrop-blur-md ring-1 ring-white/10">
                                <div class="text-[8px] font-black uppercase tracking-[0.2em] text-white/60">{{ __('Expires') }}</div>
                                <div class="mt-1 text-[11px] font-black text-white">
                                    {{ $this->activePlan->expires_at?->format('d M, H:i') ?? __('Never') }}
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @foreach ($this->plans as $plan)
                    @php
                        $planDelay = ($loop->index * 90) + 120;
                    @endphp

                    <article @class([
                        'plans-reveal relative overflow-hidden rounded-[2.5rem] transition-all duration-500',
                        'bg-slate-900 ring-2 ring-indigo-500 shadow-[0_20px_50px_-20px_rgba(79,70,229,0.3)]' => $this->selectedPlanId === ($plan['id'] ?? null),
                        'bg-slate-900 shadow-xl ring-1 ring-slate-800' => $this->selectedPlanId !== ($plan['id'] ?? null),
                        'opacity-40 grayscale-[0.8]' => $this->activePlan && ($this->activePlan->backend_plan_id !== ($plan['id'] ?? null))
                    ]) style="animation-delay: {{ $planDelay }}ms">
                        
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2">
                                        <flux:heading size="lg" class="text-xl font-black tracking-tight text-white">{{ $plan['name'] ?? __('Plan') }}</flux:heading>
                                        @if ($this->activePlan?->backend_plan_id === ($plan['id'] ?? null))
                                            <div class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-500 text-white shadow-lg shadow-indigo-500/20">
                                                <flux:icon.check class="size-3" />
                                            </div>
                                        @endif
                                    </div>
                                    <div class="text-[9px] font-black uppercase tracking-[0.2em] text-slate-500">{{ str_replace('_', ' ', $plan['type'] ?? 'PLAN') }}</div>
                                </div>
                                <div class="flex flex-col items-end">
                                    <div class="text-xl font-black text-teal-400">KES {{ number_format((float) ($plan['price'] ?? 0)) }}</div>
                                    <div class="text-[8px] font-black uppercase tracking-[0.2em] text-slate-600">{{ __('PRICE') }}</div>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 mb-6">
                                @if (($plan['type'] ?? null) === 'usage_pack' && ! is_null($plan['ussd_requests_included']))
                                    <div class="rounded-2xl bg-slate-950 p-3 ring-1 ring-slate-800/50">
                                        <div class="text-[8px] font-black uppercase tracking-[0.2em] text-slate-600">{{ __('Requests') }}</div>
                                        <div class="mt-1 text-xs font-black text-slate-100">{{ number_format((int) $plan['ussd_requests_included']) }}</div>
                                    </div>
                                @endif

                                @if (! empty($plan['duration_days']))
                                    <div class="rounded-2xl bg-slate-950 p-3 ring-1 ring-slate-800/50">
                                        <div class="text-[8px] font-black uppercase tracking-[0.2em] text-slate-600">{{ __('Validity') }}</div>
                                        <div class="mt-1 text-xs font-black text-slate-100">
                                            {{ trans_choice(':count Day|:count Days', (int) $plan['duration_days'], ['count' => (int) $plan['duration_days']]) }}
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <flux:button
                                class="h-12 w-full font-black uppercase tracking-[0.25em] text-[10px]"
                                type="button"
                                variant="{{ $this->selectedPlanId === ($plan['id'] ?? null) ? 'primary' : 'ghost' }}"
                                wire:click="selectPlan({{ (int) ($plan['id'] ?? 0) }})"
                                :disabled="$this->activePlan !== null"
                            >
                                @if ($this->activePlan?->backend_plan_id === ($plan['id'] ?? null))
                                    {{ __('ACTIVE') }}
                                @elseif ($this->activePlan)
                                    {{ __('LOCKED') }}
                                @else
                                    {{ $this->selectedPlanId === ($plan['id'] ?? null) ? __('SELECTED') : __('SELECT PLAN') }}
                                @endif
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
                    <div class="fixed inset-x-0 bottom-0 z-[60] flex flex-col p-6 animate-in slide-in-from-bottom duration-500">
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/40 to-transparent pointer-events-none"></div>
                        <div class="relative mx-auto w-full max-w-lg rounded-[2.5rem] bg-slate-900/90 p-6 shadow-2xl ring-1 ring-slate-800 backdrop-blur-3xl">
                            <div class="flex flex-col gap-5">
                                <div class="flex items-center justify-between">
                                    <div class="flex flex-col">
                                        <div class="text-[10px] font-black uppercase tracking-[0.3em] text-teal-400/60">{{ __('Review Order') }}</div>
                                        <div class="text-xl font-black text-white tracking-tight">{{ $selectedPlan['name'] ?? __('Plan') }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-2xl font-black text-teal-400">KES {{ number_format((float) ($selectedPlan['price'] ?? 0)) }}</div>
                                    </div>
                                </div>

                                <div class="h-px w-full bg-slate-800"></div>

                                @if ($this->sambazaLine)
                                    <div class="flex flex-col gap-4">
                                        <div class="space-y-2">
                                            <div class="text-[9px] font-black uppercase tracking-widest text-slate-600 px-1">{{ __('Choose SIM Slot') }}</div>
                                            <flux:radio.group wire:model="simSlot" variant="segmented" class="w-full h-12 bg-slate-950 p-1 rounded-2xl ring-1 ring-slate-800">
                                                <flux:radio value="0" label="{{ __('SIM 1') }}" class="font-bold text-white" />
                                                <flux:radio value="1" label="{{ __('SIM 2') }}" class="font-bold text-white" />
                                            </flux:radio.group>
                                        </div>
                                        
                                        <flux:button type="button" variant="primary" wire:click="initiateSambaza" class="h-14 w-full text-base font-black uppercase tracking-widest shadow-2xl shadow-indigo-500/20">
                                            {{ __('Purchase Now') }}
                                        </flux:button>
                                    </div>
                                @endif

                                <button type="button" wire:click="$set('selectedPlanId', null)" class="text-[10px] font-black uppercase tracking-[0.25em] text-slate-600 hover:text-slate-400 transition-colors">
                                    {{ __('Cancel selection') }}
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        @endif
    </div>
</section>
