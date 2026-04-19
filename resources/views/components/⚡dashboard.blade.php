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

<div class="flex flex-col gap-6 p-4 md:p-6 pb-20">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <div>
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ $this->greeting }},</flux:text>
                <flux:heading size="xl" class="mt-0.5">{{ auth()->user()->name }}</flux:heading>
            </div>
        </div>
        <div class="flex flex-col items-end gap-1">
            <flux:icon.qr-code class="size-6 text-zinc-900 dark:text-zinc-50" />
            <div class="flex items-center gap-1.5">
                <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Express Mode') }}</span>
                <span class="size-2 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]"></span>
            </div>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-cols-3 gap-3">
        <!-- Successful -->
        <div class="flex flex-col items-center justify-center rounded-2xl bg-emerald-900/95 p-4 text-center shadow-lg dark:bg-emerald-800">
            <div class="text-xl font-bold text-white">{{ $this->stats['successful'] }}</div>
            <div class="text-[10px] font-medium uppercase tracking-wider text-emerald-200/80">{{ __('Successful') }}</div>
        </div>

        <!-- Failed -->
        <div class="flex flex-col items-center justify-center rounded-2xl bg-rose-100 p-4 text-center shadow-sm dark:bg-rose-900/30">
            <div class="text-xl font-bold text-rose-600 dark:text-rose-400">{{ $this->stats['failed'] }}</div>
            <div class="text-[10px] font-medium uppercase tracking-wider text-rose-500/80 dark:text-rose-400/80">{{ __('Failed') }}</div>
        </div>

        <!-- Tokens -->
        <div class="flex flex-col items-center justify-center rounded-2xl bg-sky-100 p-4 text-center shadow-sm dark:bg-sky-900/30">
            <div class="text-lg font-bold text-sky-900 dark:text-sky-100">{{ $this->tokenText }}</div>
            <div class="text-[10px] font-medium uppercase tracking-wider text-sky-700/80 dark:text-sky-400/80">{{ __('Plan Details') }}</div>
        </div>
    </div>

    <!-- Airtime Bar -->
    <div class="flex items-center justify-between rounded-2xl bg-zinc-100/80 p-4 dark:bg-zinc-800/50">
        <div class="flex items-center gap-6">
            <div class="flex flex-col">
                <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Airtime Used Today') }}</div>
                <div class="mt-0.5 flex items-center gap-2">
                    <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        Ksh {{ $this->showBalance ? number_format($this->airtime['used_today'], 2) : '****' }}
                    </span>
                    <button wire:click="toggleBalance" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        @if($this->showBalance) <flux:icon.eye class="size-4" /> @else <flux:icon.eye-slash class="size-4" /> @endif
                    </button>
                </div>
            </div>
            <div class="h-8 w-px bg-zinc-300 dark:bg-zinc-700"></div>
            <div class="flex flex-col">
                <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Airtime Balance') }}</div>
                <div class="mt-0.5 flex items-center gap-2">
                    <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        Ksh {{ $this->showBalance ? number_format($this->airtime['balance'], 2) : '****' }}
                    </span>
                    <button wire:click="toggleBalance" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        @if($this->showBalance) <flux:icon.eye class="size-4" /> @else <flux:icon.eye-slash class="size-4" /> @endif
                    </button>
                </div>
            </div>
        </div>
        <button wire:click="refreshData" class="rounded-full p-1.5 text-zinc-500 hover:bg-zinc-200 dark:hover:bg-zinc-700">
            <flux:icon.arrow-path class="size-5" />
        </button>
    </div>

    <!-- Chart -->
    <div class="flex flex-col rounded-3xl bg-zinc-50/50 p-4 shadow-sm dark:bg-zinc-900/20 border border-zinc-100 dark:border-zinc-800">
        <flux:heading size="sm" class="text-emerald-600 dark:text-emerald-400 font-bold tracking-tight">
            {{ __("This week's commission (Ksh. :amount)", ['amount' => number_format($this->commissionData['total'], 2)]) }}
        </flux:heading>

        <div class="mt-4 h-32 w-full">
            <svg viewBox="0 0 700 130" class="h-full w-full overflow-visible" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="gradient" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#10b981" stop-opacity="0.3" />
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

                <!-- Area fill -->
                <path d="{{ $path }} L 600 130 L 0 130 Z" fill="url(#gradient)" />
                
                <!-- Line -->
                <path d="{{ $path }}" fill="none" stroke="#10b981" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />

                <!-- Dots -->
                @foreach($points as $i => $val)
                    @php
                        $x = $i * 100;
                        $y = 120 - ($val / $max * 100);
                    @endphp
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="4" fill="#ef4444" />
                @endforeach
            </svg>
        </div>
        <div class="mt-2 flex justify-between px-1 text-[10px] font-bold uppercase tracking-tighter text-zinc-400">
            <span>{{ __('Sun') }}</span>
            <span>{{ __('Mon') }}</span>
            <span>{{ __('Tue') }}</span>
            <span>{{ __('Wed') }}</span>
            <span>{{ __('Thu') }}</span>
            <span>{{ __('Fri') }}</span>
            <span>{{ __('Sat') }}</span>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="flex flex-col gap-4" wire:poll.5s>
        <div class="flex items-center justify-between">
            <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-50">{{ __('Recent Transactions') }}</flux:heading>
            <flux:button variant="ghost" size="sm" :href="route('transactions')" wire:navigate class="text-emerald-600 dark:text-emerald-400">
                {{ __('All') }} <flux:icon.arrow-long-right class="ml-1 size-4" />
            </flux:button>
        </div>

        <div class="flex flex-col gap-3">
            @forelse($this->recentTransactions as $tx)
                <div class="flex items-center gap-4 rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/50">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                        <flux:icon.check class="size-5" />
                    </div>
                    <div class="flex flex-1 flex-col">
                        <div class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $tx->sender_name ?: $tx->sender_phone }}</div>
                        <div class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">{{ $tx->offer_name }}</div>
                    </div>
                    <div class="flex flex-col items-end">
                        <div class="text-[10px] font-medium text-zinc-500">{{ $tx->occurred_at->diffForHumans() }}</div>
                        <div class="text-sm font-bold text-emerald-600 dark:text-emerald-400">Ksh {{ number_format($tx->amount) }}</div>
                    </div>
                </div>
            @empty
                <div class="rounded-2xl border border-dashed border-zinc-200 p-8 text-center text-zinc-500 dark:border-zinc-700">
                    {{ __('No transactions yet.') }}
                </div>
            @endforelse
        </div>
    </div>
</div>
