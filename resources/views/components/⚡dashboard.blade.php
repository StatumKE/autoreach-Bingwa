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

<div class="flex flex-col gap-6 p-4 md:p-6 pb-24 bg-zinc-50/50 dark:bg-zinc-950/50 min-h-screen">
    <!-- Header -->
    <div class="flex flex-col pt-2">
        <flux:text class="text-[10px] font-black uppercase tracking-[0.2em] text-zinc-500/80 dark:text-zinc-400/60">{{ $this->greeting }}</flux:text>
        <div class="flex items-center justify-between mt-0.5">
            <flux:heading size="xl" class="text-3xl font-black tracking-tight text-zinc-900 dark:text-zinc-50">{{ auth()->user()->name }}</flux:heading>
            <button wire:click="refreshData" class="flex h-10 w-10 items-center justify-center rounded-2xl bg-white shadow-sm ring-1 ring-zinc-200/50 transition-all hover:bg-zinc-50 active:scale-90 dark:bg-zinc-900 dark:ring-zinc-800">
                <flux:icon.arrow-path class="size-5 text-zinc-500" />
            </button>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-cols-3 gap-3">
        <!-- Successful -->
        <a href="{{ route('transactions', ['filter' => 'success']) }}" wire:navigate class="group relative flex flex-col items-center justify-center rounded-[2.5rem] bg-emerald-600 p-4 text-center shadow-lg shadow-emerald-500/20 transition-all active:scale-95 dark:bg-emerald-700">
            <div class="absolute inset-0 rounded-[2.5rem] bg-white/10 opacity-0 transition-opacity group-hover:opacity-100"></div>
            <div class="text-2xl font-black text-white leading-tight tracking-tighter">{{ number_format($this->stats['successful']) }}</div>
            <div class="text-[8px] font-black uppercase tracking-widest text-emerald-100/70 mt-1">{{ __('SUCCESS') }}</div>
        </a>

        <!-- Failed -->
        <a href="{{ route('transactions', ['filter' => 'failed']) }}" wire:navigate class="group relative flex flex-col items-center justify-center rounded-[2.5rem] bg-white p-4 text-center shadow-sm ring-1 ring-zinc-200/50 transition-all active:scale-95 dark:bg-zinc-900 dark:ring-zinc-800">
            <div class="absolute inset-0 rounded-[2.5rem] bg-rose-500/5 opacity-0 transition-opacity group-hover:opacity-100"></div>
            <div class="text-2xl font-black text-rose-600 dark:text-rose-400 leading-tight tracking-tighter">{{ number_format($this->stats['failed']) }}</div>
            <div class="text-[8px] font-black uppercase tracking-widest text-rose-500/50 mt-1">{{ __('FAILED') }}</div>
        </a>

        <!-- Tokens -->
        <a href="{{ route('plans') }}" wire:navigate class="group relative flex flex-col items-center justify-center rounded-[2.5rem] bg-white p-4 text-center shadow-sm ring-1 ring-zinc-200/50 transition-all active:scale-95 dark:bg-zinc-900 dark:ring-zinc-800">
            <div class="absolute inset-0 rounded-[2.5rem] bg-sky-500/5 opacity-0 transition-opacity group-hover:opacity-100"></div>
            <div class="text-lg font-black text-sky-900 dark:text-sky-100 leading-tight tracking-tighter">{{ $this->tokenText }}</div>
            <div class="text-[8px] font-black uppercase tracking-widest text-sky-700/50 dark:text-sky-400/50 mt-1">{{ __('TOKENS') }}</div>
        </a>
    </div>

    <!-- Airtime Bar -->
    <div class="relative overflow-hidden rounded-[2.5rem] bg-white p-6 shadow-sm ring-1 ring-zinc-200/50 dark:bg-zinc-900 dark:ring-zinc-800">
        <div class="absolute top-0 right-0 p-4 opacity-10">
            <flux:icon.banknotes class="size-12 text-zinc-900 dark:text-white" />
        </div>
        
        <div class="flex items-center gap-8">
            <div class="flex flex-col gap-1">
                <div class="text-[9px] font-black uppercase tracking-[0.2em] text-zinc-400">{{ __('Used Today') }}</div>
                <div class="flex items-center gap-2">
                    <span class="text-xl font-black text-zinc-900 dark:text-zinc-50 tracking-tight">
                        Ksh {{ $this->showBalance ? number_format($this->airtime['used_today'], 2) : '••••' }}
                    </span>
                    <button wire:click="toggleBalance" class="text-zinc-300 hover:text-zinc-500 transition-colors">
                        @if($this->showBalance) <flux:icon.eye class="size-4" /> @else <flux:icon.eye-slash class="size-4" /> @endif
                    </button>
                </div>
            </div>

            <div class="h-10 w-px bg-zinc-200 dark:bg-zinc-800"></div>

            <div class="flex flex-col gap-1">
                <div class="text-[9px] font-black uppercase tracking-[0.2em] text-zinc-400">{{ __('Balance') }}</div>
                <div class="flex items-center gap-2">
                    <span class="text-xl font-black text-emerald-600 dark:text-emerald-400 tracking-tight">
                        Ksh {{ $this->showBalance ? number_format($this->airtime['balance'], 2) : '••••' }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart -->
    <div class="flex flex-col rounded-[2.5rem] bg-white p-6 shadow-sm ring-1 ring-zinc-200/50 dark:bg-zinc-900 dark:ring-zinc-800">
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="sm" class="text-sm font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                {{ __("Weekly Performance") }}
            </flux:heading>
            <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 px-2 py-0.5 rounded-lg">
                Ksh {{ number_format($this->commissionData['total'], 2) }}
            </span>
        </div>

        <div class="h-32 w-full mt-2">
            <svg viewBox="0 0 700 130" class="h-full w-full overflow-visible" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="gradient" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#10b981" stop-opacity="0.2" />
                        <stop offset="100%" stop-color="#10b981" stop-opacity="0" />
                    </linearGradient>
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
                <path d="{{ $path }}" fill="none" stroke="#10b981" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />

                @foreach($points as $i => $val)
                    @php
                        $x = $i * 100;
                        $y = 120 - ($val / $max * 100);
                    @endphp
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="4.5" fill="white" stroke="#10b981" stroke-width="2.5" class="dark:fill-zinc-900" />
                @endforeach
            </svg>
        </div>
        <div class="mt-4 flex justify-between px-1 text-[9px] font-black uppercase tracking-[0.15em] text-zinc-400">
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
            <flux:heading size="lg" class="text-xl font-black tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('Activity') }}</flux:heading>
            <flux:button variant="ghost" size="sm" :href="route('transactions')" wire:navigate class="text-xs font-bold text-zinc-500 hover:text-emerald-600 transition-colors">
                {{ __('View All') }}
            </flux:button>
        </div>

        <div class="flex flex-col gap-3">
            @forelse($this->recentTransactions as $tx)
                <div class="flex items-center gap-4 rounded-[2rem] bg-white p-4 shadow-sm ring-1 ring-zinc-200/50 dark:bg-zinc-900 dark:ring-zinc-800 transition-transform active:scale-[0.98]">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        @if($tx->status === 'completed')
                            <flux:icon.check-circle class="size-6 text-emerald-500" />
                        @else
                            <flux:icon.clock class="size-6 text-zinc-400" />
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col">
                        <div class="text-sm font-black text-zinc-900 dark:text-zinc-50">{{ $tx->sender_name ?: $tx->sender_phone }}</div>
                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wider">{{ $tx->occurred_at->diffForHumans() }}</div>
                    </div>
                    <div class="flex flex-col items-end">
                        <div class="text-base font-black text-zinc-900 dark:text-zinc-50">Ksh {{ number_format($tx->amount) }}</div>
                        <div class="text-[9px] font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-widest">{{ $tx->offer_name }}</div>
                    </div>
                </div>
            @empty
                <div class="rounded-[2.5rem] border border-dashed border-zinc-200 p-12 text-center dark:border-zinc-800">
                    <div class="text-sm font-bold text-zinc-400">{{ __('No recent activity found.') }}</div>
                </div>
            @endforelse
        </div>
    </div>
</div>
