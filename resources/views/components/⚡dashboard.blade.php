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
     * Airtime usage and balance.
     */
    public function getAirtimeProperty(): array
    {
        $user = Auth::user();
        $today = Transaction::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereDate('occurred_at', Carbon::today())
            ->sum('amount');

        $latestBalance = Transaction::where('user_id', $user->id)
            ->whereNotNull('balance')
            ->latest('occurred_at')
            ->first();

        $balance = 0;
        if ($latestBalance && isset($latestBalance->balance['airtime'])) {
            $balance = $latestBalance->balance['airtime'];
        }

        return [
            'used_today' => $today,
            'balance' => $balance,
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
        // Livewire refresh
    }
}; ?>

<div class="flex flex-col gap-4 p-4 pb-24 bg-slate-950 text-slate-100 min-h-screen">
    <!-- Header -->
    <div class="flex flex-col gap-1 px-1">
        <flux:text class="text-[10px] font-black uppercase tracking-[0.3em] text-teal-400/50">{{ $this->greeting }}</flux:text>
        <div class="flex items-center justify-between">
            <flux:heading size="xl" class="text-3xl font-black tracking-tight text-white">{{ auth()->user()->name }}</flux:heading>
            <button wire:click="refreshData" class="group flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-900 shadow-xl ring-1 ring-slate-800 transition-all hover:ring-indigo-500/30 active:scale-90">
                <flux:icon.arrow-path class="size-5 text-indigo-400 transition-transform group-hover:rotate-180 duration-500" />
            </button>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-cols-3 gap-3">
        <!-- Successful -->
        <a href="{{ route('transactions', ['filter' => 'success']) }}" wire:navigate class="group relative flex flex-col items-center justify-center overflow-hidden rounded-[2rem] bg-gradient-to-br from-teal-500 to-cyan-600 p-4 text-center shadow-[0_20px_40px_-15px_rgba(20,184,166,0.4)] transition-all hover:-translate-y-0.5 active:scale-95">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.2),transparent)]"></div>
            <div class="text-2xl font-black text-white leading-tight tracking-tighter">{{ number_format($this->stats['successful']) }}</div>
            <div class="text-[8px] font-black uppercase tracking-[0.2em] text-white/70 mt-1">{{ __('SUCCESS') }}</div>
        </a>

        <!-- Failed -->
        <a href="{{ route('transactions', ['filter' => 'failed']) }}" wire:navigate class="group relative flex flex-col items-center justify-center overflow-hidden rounded-[2rem] bg-slate-900 p-4 text-center shadow-xl ring-1 ring-slate-800 transition-all hover:-translate-y-0.5 active:scale-95">
            <div class="absolute inset-0 bg-gradient-to-br from-rose-500/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
            <div class="text-2xl font-black text-rose-500 leading-tight tracking-tighter">{{ number_format($this->stats['failed']) }}</div>
            <div class="text-[8px] font-black uppercase tracking-[0.2em] text-slate-500 mt-1">{{ __('FAILED') }}</div>
        </a>

        <!-- Tokens -->
        <a href="{{ route('plans') }}" wire:navigate class="group relative flex flex-col items-center justify-center overflow-hidden rounded-[2rem] bg-slate-900 p-4 text-center shadow-xl ring-1 ring-slate-800 transition-all hover:-translate-y-0.5 active:scale-95">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-500/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
            <div class="text-base font-black text-indigo-400 leading-tight tracking-tighter">{{ $this->tokenText }}</div>
            <div class="text-[8px] font-black uppercase tracking-[0.2em] text-slate-500 mt-1">{{ __('PLAN') }}</div>
        </a>
    </div>

    <!-- Airtime Bar -->
    <div class="relative overflow-hidden rounded-[2.5rem] bg-slate-900 p-6 shadow-2xl ring-1 ring-slate-800">
        <div class="absolute top-0 right-0 p-6 opacity-10">
            <flux:icon.banknotes class="size-12 text-white" />
        </div>
        
        <div class="flex items-center gap-8">
            <div class="flex flex-col gap-1">
                <div class="text-[9px] font-black uppercase tracking-[0.25em] text-slate-500">{{ __('Used Today') }}</div>
                <div class="flex items-center gap-2">
                    <span class="text-xl font-black text-white tracking-tight">
                        Ksh {{ $this->showBalance ? number_format($this->airtime['used_today'], 2) : '••••' }}
                    </span>
                    <button wire:click="toggleBalance" class="text-slate-600 hover:text-indigo-400 transition-colors">
                        @if($this->showBalance) <flux:icon.eye class="size-4" /> @else <flux:icon.eye-slash class="size-4" /> @endif
                    </button>
                </div>
            </div>

            <div class="h-10 w-px bg-slate-800"></div>

            <div class="flex flex-col gap-1">
                <div class="text-[9px] font-black uppercase tracking-[0.25em] text-teal-400/60">{{ __('Current Balance') }}</div>
                <div class="flex items-center gap-2">
                    <span class="text-xl font-black text-teal-400 tracking-tight">
                        Ksh {{ $this->showBalance ? number_format($this->airtime['balance'], 2) : '••••' }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart -->
    <div class="flex flex-col rounded-[2.5rem] bg-slate-900 p-6 shadow-2xl ring-1 ring-slate-800">
        <div class="flex items-center justify-between mb-4">
            <div class="flex flex-col gap-0.5">
                <flux:heading size="sm" class="text-xs font-black tracking-tight text-white">
                    {{ __("Weekly Performance") }}
                </flux:heading>
                <div class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">{{ __('Transaction Volume') }}</div>
            </div>
            <div class="flex flex-col items-end">
                <span class="text-sm font-black text-indigo-400">
                    Ksh {{ number_format($this->commissionData['total'], 2) }}
                </span>
                <div class="text-[8px] font-black text-indigo-400/40 uppercase tracking-[0.2em]">{{ __('TOTAL') }}</div>
            </div>
        </div>

        <div class="h-24 w-full mt-2">
            <svg viewBox="0 0 700 130" class="h-full w-full overflow-visible" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="gradient" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#6366f1" stop-opacity="0.2" />
                        <stop offset="100%" stop-color="#14b8a6" stop-opacity="0" />
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
                <path d="{{ $path }}" fill="none" stroke="#6366f1" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" filter="url(#glow)" />

                @foreach($points as $i => $val)
                    @php
                        $x = $i * 100;
                        $y = 120 - ($val / $max * 100);
                    @endphp
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="5" fill="#14b8a6" stroke="#0f172a" stroke-width="2.5" />
                @endforeach
            </svg>
        </div>
        <div class="mt-4 flex justify-between px-1 text-[8px] font-black uppercase tracking-[0.25em] text-slate-700">
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
            <flux:heading size="lg" class="text-xl font-black tracking-tight text-white">{{ __('Activity') }}</flux:heading>
            <flux:button variant="ghost" size="sm" :href="route('transactions')" wire:navigate class="text-xs font-bold text-teal-400 hover:text-cyan-400 transition-colors">
                {{ __('View All') }}
            </flux:button>
        </div>

        <div class="flex flex-col gap-3">
            @forelse($this->recentTransactions as $tx)
                <div class="flex items-center gap-4 rounded-[2rem] bg-slate-900 p-4 shadow-xl ring-1 ring-slate-800 transition-transform active:scale-[0.98]">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-950 text-indigo-400 shadow-inner">
                        @if($tx->status === 'completed')
                            <flux:icon.check-circle class="size-6 text-teal-400" />
                        @else
                            <flux:icon.clock class="size-6 text-slate-700" />
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col">
                        <div class="text-sm font-black text-white">{{ $tx->sender_name ?: $tx->sender_phone }}</div>
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">{{ $tx->occurred_at->diffForHumans() }}</div>
                    </div>
                    <div class="flex flex-col items-end">
                        <div class="text-base font-black text-white">Ksh {{ number_format($tx->amount) }}</div>
                        <div class="text-[9px] font-bold text-teal-400/60 uppercase tracking-widest">{{ $tx->offer_name }}</div>
                    </div>
                </div>
            @empty
                <div class="rounded-[2.5rem] border border-dashed border-slate-800 p-12 text-center bg-slate-900/30">
                    <div class="text-sm font-bold text-slate-600">{{ __('No recent activity found.') }}</div>
                </div>
            @endforelse
        </div>
    </div>
</div>
