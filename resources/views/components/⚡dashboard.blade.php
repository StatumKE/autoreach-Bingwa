<?php

use App\Models\Transaction;
use App\Models\Plan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    public bool $showBalance = true;

    public int $refreshKey = 0;

    /**
     * Get the dynamic greeting based on time of day.
     */
    public function getGreetingProperty(): string
    {
        $hour = now()->hour;
        if ($hour < 12) return __('Good Morning');
        if ($hour < 17) return __('Good Afternoon');
        return __('Good Evening');
    }

    /**
     * Statistics and counts.
     */
    public function getStatsProperty(): array
    {
        $user = Auth::user();
        return [
            'successful' => Transaction::where('user_id', $user->id)->where('status', 'completed')->count(),
            'failed' => Transaction::where('user_id', $user->id)->where('status', 'failed')->count(),
        ];
    }

    /**
     * Active plan details.
     */
    public function getActivePlanProperty(): ?Plan
    {
        return Auth::user()->plans()->where('is_active', true)->first();
    }

    /**
     * Formatted token text for the plan card.
     */
    public function getTokenTextProperty(): string
    {
        $plan = $this->activePlan;
        if (!$plan) return __('No Plan');

        if ($plan->type === 'time_unlimited') {
            if (!$plan->expires_at) return __('Unlimited');
            $diff = now()->diff($plan->expires_at);
            if ($diff->invert) return __('Expired');
            
            $days = $diff->d;
            $hours = $diff->h;
            return "{$days}d {$hours}h";
        }

        $remaining = ($plan->ussd_requests_included ?? 0) - $plan->ussd_counter;
        return max(0, $remaining) . ' ' . __('Tokens');
    }

    /**
     * Airtime usage summary.
     */
    public function getAirtimeProperty(): array
    {
        $user = Auth::user();
        $today = Transaction::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereDate('occurred_at', Carbon::today())
            ->sum('amount');

        return [
            'used_today' => $today,
        ];
    }

    /**
     * Commission and Chart Data.
     */
    public function getCommissionDataProperty(): array
    {
        $user = Auth::user();
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        $dailyTotals = Transaction::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereBetween('occurred_at', [$startOfWeek, $endOfWeek])
            ->select(DB::raw('DATE(occurred_at) as date'), DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->get()
            ->pluck('total', 'date')
            ->toArray();

        $dataPoints = [];
        $totalCommission = 0;
        
        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i)->format('Y-m-d');
            $val = $dailyTotals[$date] ?? 0;
            $dataPoints[] = (float) $val;
            $totalCommission += $val;
        }

        return [
            'total' => $totalCommission,
            'points' => $dataPoints,
            'max' => max($dataPoints) ?: 100, // Avoid division by zero
        ];
    }

    /**
     * Recent transactions.
     */
    public function getRecentTransactionsProperty()
    {
        return Transaction::where('user_id', Auth::id())
            ->orderBy('id', 'desc')
            ->take(10)
            ->get();
    }

    public function toggleBalance(): void
    {
        $this->showBalance = !$this->showBalance;
    }

    public function refreshData(): void
    {
        $this->refreshKey++;
    }
}; ?>

<div class="flex flex-col gap-3 p-3 pb-24 bg-app-bg text-zinc-950 min-h-screen">
    <!-- Header -->
    <div class="flex flex-col gap-1 px-1 pt-0">
        <flux:text class="app-kicker">{{ __('Dashboard') }}</flux:text>
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0 flex items-baseline gap-1.5 truncate">
                <div class="shrink-0 text-[12px] font-semibold leading-none text-zinc-800 sm:text-[13px]">{{ $this->greeting }},</div>
                <flux:heading size="xl" class="min-w-0 truncate text-[12px] font-black tracking-tight text-zinc-950 leading-none sm:text-[13px]">{{ auth()->user()->name }}</flux:heading>
            </div>
            <button wire:click="refreshData" wire:loading.attr="disabled" wire:target="refreshData" class="group app-primary-button flex h-11 w-11 items-center justify-center">
                <span wire:loading.remove wire:target="refreshData">
                    <flux:icon.arrow-path class="size-5 text-white transition-transform group-hover:rotate-180 duration-500" />
                </span>
                <span wire:loading wire:target="refreshData" class="inline-flex items-center justify-center">
                    <flux:icon.loading variant="mini" class="size-5 text-white" />
                </span>
            </button>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-cols-3 gap-2">
        <!-- Successful -->
        <a href="{{ route('transactions', ['filter' => 'success']) }}" wire:navigate class="group relative flex flex-col items-start justify-center overflow-hidden rounded-2xl bg-green-50 px-2 py-2 text-left shadow-sm ring-1 ring-green-100 transition hover:-translate-y-0.5 active:scale-95">
            <div class="text-[6px] font-black uppercase tracking-[0.22em] text-zinc-600">{{ __('Successful') }}</div>
            <div class="mt-0.5 text-lg font-black text-zinc-950 leading-none tracking-tighter">{{ number_format($this->stats['successful']) }}</div>
        </a>

        <!-- Failed -->
        <a href="{{ route('transactions', ['filter' => 'failed']) }}" wire:navigate class="group relative flex flex-col items-start justify-center overflow-hidden rounded-2xl bg-rose-50 px-2 py-2 text-left shadow-sm ring-1 ring-rose-100 transition hover:-translate-y-0.5 active:scale-95">
            <div class="text-[6px] font-black uppercase tracking-[0.22em] text-zinc-600">{{ __('Failed') }}</div>
            <div class="mt-0.5 text-lg font-black text-zinc-950 leading-none tracking-tighter">{{ number_format($this->stats['failed']) }}</div>
        </a>

        <!-- Tokens -->
        <a href="{{ route('plans') }}" wire:navigate class="group relative flex flex-col items-start justify-center overflow-hidden rounded-2xl bg-sky-50 px-2 py-2 text-left shadow-sm ring-1 ring-sky-100 transition hover:-translate-y-0.5 active:scale-95">
            <div class="text-[6px] font-black uppercase tracking-[0.22em] text-zinc-600">{{ __('Tokens') }}</div>
            <div class="mt-0.5 text-[0.85rem] font-black text-zinc-950 leading-tight tracking-tighter">{{ $this->tokenText }}</div>
        </a>
    </div>

    <!-- Airtime Bar -->
    <div class="relative overflow-hidden rounded-2xl bg-white px-3 py-2 shadow-sm ring-1 ring-zinc-200">
        <div class="flex items-center justify-between">
            <div class="flex flex-col">
                <div class="text-[6px] font-black uppercase tracking-[0.25em] text-zinc-500">{{ __('Used Today') }}</div>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-black text-zinc-950 tracking-tight">
                        Ksh {{ $this->showBalance ? number_format($this->airtime['used_today'], 2) : '••••' }}
                    </span>
                    <button wire:click="toggleBalance" class="text-zinc-400 hover:text-green-600 transition-colors">
                        @if($this->showBalance) <flux:icon.eye class="size-3" /> @else <flux:icon.eye-slash class="size-3" /> @endif
                    </button>
                </div>
            </div>
            <div class="opacity-10">
                <flux:icon.banknotes class="size-5 text-green-600" />
            </div>
        </div>
    </div>

    <!-- Chart -->
    <div class="flex flex-col rounded-[1.5rem] bg-white p-4 shadow-sm ring-1 ring-zinc-200">
        <div class="flex items-center justify-between mb-4">
            <div class="flex flex-col gap-0.5">
                <flux:heading size="sm" class="text-[11px] font-black tracking-tight text-zinc-950">
                    {{ __("Weekly Performance") }}
                </flux:heading>
                <div class="text-[9px] font-bold text-zinc-500 uppercase tracking-widest">{{ __('Transaction Volume') }}</div>
            </div>
            <div class="flex flex-col items-end">
                <span class="text-[13px] font-black text-green-700">
                    Ksh {{ number_format($this->commissionData['total'], 2) }}
                </span>
                <div class="text-[8px] font-black text-green-600/60 uppercase tracking-[0.2em]">{{ __('TOTAL') }}</div>
            </div>
        </div>

        <div class="h-20 w-full mt-2">
            <svg viewBox="0 0 700 130" class="h-full w-full overflow-visible" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="gradient" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#3aa335" stop-opacity="0.22" />
                        <stop offset="100%" stop-color="#3aa335" stop-opacity="0" />
                    </linearGradient>
                    <filter id="glow" x="-20%" y="-20%" width="140%" height="140%">
                        <feGaussianBlur stdDeviation="3" result="blur" />
                        <feComposite in="SourceGraphic" in2="blur" operator="over" />
                    </filter>
                </defs>
                
                @php
                    $points = $this->commissionData['points'];
                    $max = $this->commissionData['max'];
                    $path = "";
                    foreach($points as $i => $val) {
                        $x = $i * 100;
                        $y = 120 - ($val / $max * 100);
                        $path .= ($i === 0 ? "M" : " L") . " {$x} {$y}";
                    }
                @endphp

                <path d="{{ $path }} L 600 130 L 0 130 Z" fill="url(#gradient)" />
                <path d="{{ $path }}" fill="none" stroke="#3aa335" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" filter="url(#glow)" />

                @foreach($points as $i => $val)
                    @php
                        $x = $i * 100;
                        $y = 120 - ($val / $max * 100);
                    @endphp
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="5" fill="#3aa335" stroke="#ffffff" stroke-width="2.5" />
                @endforeach
            </svg>
        </div>
        <div class="mt-3 flex justify-between px-1 text-[8px] font-black uppercase tracking-[0.25em] text-zinc-700">
            <span>{{ __('SUN') }}</span>
            <span>{{ __('MON') }}</span>
            <span>{{ __('TUE') }}</span>
            <span>{{ __('WED') }}</span>
            <span>{{ __('THU') }}</span>
            <span>{{ __('FRI') }}</span>
            <span>{{ __('SAT') }}</span>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="flex flex-col gap-4" wire:poll.10s>
        <div class="flex items-center justify-between px-1">
            <flux:heading size="lg" class="text-xl font-black tracking-tight text-zinc-950">{{ __('Activity') }}</flux:heading>
            <flux:button variant="ghost" size="sm" :href="route('transactions')" wire:navigate class="text-xs font-bold text-green-700 hover:text-green-700 transition-colors">
                {{ __('View All') }}
            </flux:button>
        </div>

        <div class="flex flex-col gap-3">
            @forelse($this->recentTransactions as $tx)
                <div class="flex items-center gap-3 rounded-xl bg-white px-3 py-1.5 shadow-sm ring-1 ring-zinc-200 transition-transform active:scale-[0.98]">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-600 shadow-inner">
                        @if($tx->status === 'completed')
                            <flux:icon.check-circle class="size-4 text-green-600" />
                        @else
                            <flux:icon.clock class="size-4 text-zinc-400" />
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col min-w-0">
                        <div class="truncate text-[12px] font-black text-zinc-950">{{ $tx->sender_name ?: $tx->sender_phone }}</div>
                        <div class="text-[8px] font-bold text-zinc-400 uppercase tracking-tight">{{ $tx->occurred_at->diffForHumans() }}</div>
                    </div>
                    <div class="flex flex-col items-end shrink-0">
                        <div class="text-[13px] font-black text-zinc-950">Ksh {{ number_format($tx->amount) }}</div>
                        <div class="text-[7px] font-black text-green-600/70 uppercase tracking-widest">{{ $tx->offer_name }}</div>
                    </div>
                </div>
            @empty
                <div class="rounded-[1.75rem] border border-dashed border-zinc-200 p-12 text-center bg-zinc-50">
                    <div class="text-sm font-bold text-zinc-600">{{ __('No recent activity found.') }}</div>
                </div>
            @endforelse
        </div>
    </div>
</div>
