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

    /**
     * Get the current week labels for the chart axis.
     *
     * @return array<int, string>
     */
    public function getWeekLabelsProperty(): array
    {
        $date = now()->startOfWeek(Carbon::SUNDAY);
        $labels = [];

        for ($i = 0; $i < 7; $i++) {
            $labels[] = $date->copy()->addDays($i)->format('D - d');
        }

        return $labels;
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

<div class="min-h-screen bg-app-bg px-4 pb-24 pt-3 text-zinc-900" wire:poll.10s="refreshData">
    <div class="mx-auto flex max-w-[780px] flex-col gap-3">
        {{-- Greeting --}}
        <div class="flex items-start justify-between px-1">
            <div class="min-w-0">
                <div class="text-sm font-medium leading-tight text-zinc-700">{{ $this->greeting }},</div>
                <div class="text-2xl font-bold leading-tight text-zinc-900">{{ auth()->user()->name }}</div>
            </div>
        </div>

        {{-- Stat Cards --}}
        <div class="grid grid-cols-3 gap-3">
            <a href="{{ route('transactions', ['filter' => 'success']) }}" wire:navigate class="flex flex-col items-center rounded-2xl bg-[#0f652a] px-3 py-3 text-white transition active:scale-[0.97]">
                <div class="text-2xl font-black leading-none">{{ number_format($this->stats['successful']) }}</div>
                <div class="mt-1 text-xs font-medium text-emerald-100/90">{{ __('Successful') }}</div>
            </a>

            <a href="{{ route('transactions', ['filter' => 'failed']) }}" wire:navigate class="flex flex-col items-center rounded-2xl bg-[#ffd9dc] px-3 py-3 text-[#5e181b] transition active:scale-[0.97]">
                <div class="text-2xl font-black leading-none">{{ number_format($this->stats['failed']) }}</div>
                <div class="mt-1 text-xs font-medium text-rose-900/80">{{ __('Failed') }}</div>
            </a>

            <a href="{{ route('plans') }}" wire:navigate class="flex flex-col items-center rounded-2xl bg-[#c8ebfb] px-3 py-3 text-[#12313d] transition active:scale-[0.97]">
                <div class="text-xl font-black leading-none">
                    @if ($this->tokenText === __('No Plan'))
                        {{ __('Expired') }}
                    @else
                        {{ $this->tokenText }}
                    @endif
                </div>
                <div class="mt-1 text-xs font-medium text-sky-900/80">{{ __('Tokens') }}</div>
            </a>
        </div>

        {{-- Airtime Row --}}
        <div class="grid grid-cols-[1fr_1fr_auto] items-center gap-2 rounded-xl bg-[#f6f8f0] px-4 py-3 ring-1 ring-black/5">
            <div class="flex flex-col items-center text-center">
                <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Airtime Used Today') }}</div>
                <div class="mt-1 flex items-center gap-1.5 text-sm font-medium text-zinc-800">
                    <span>Ksh {{ $this->showBalance ? number_format($this->airtime['used_today'], 2) : '••••' }}</span>
                    <button wire:click="toggleBalance" class="text-zinc-400" type="button">
                        @if($this->showBalance)
                            <flux:icon.eye class="size-4" />
                        @else
                            <flux:icon.eye-slash class="size-4" />
                        @endif
                    </button>
                </div>
            </div>

            <div class="flex flex-col items-center text-center">
                <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Airtime Balance') }}</div>
                <div class="mt-1 flex items-center gap-1.5 text-sm font-medium text-zinc-800">
                    <span>Ksh</span>
                    <button wire:click="toggleBalance" class="text-zinc-400" type="button">
                        @if($this->showBalance)
                            <flux:icon.eye class="size-4" />
                        @else
                            <flux:icon.eye-slash class="size-4" />
                        @endif
                    </button>
                </div>
            </div>

            <button
                wire:click="refreshData"
                wire:loading.attr="disabled"
                wire:target="refreshData"
                class="text-zinc-500 transition hover:text-green-700 disabled:opacity-60"
                type="button"
            >
                <flux:icon.arrow-path class="size-5" />
            </button>
        </div>

        {{-- Commission Chart --}}
        <div class="rounded-xl bg-[#f6f8f0] px-4 py-3 ring-1 ring-black/5">
            <div class="text-center text-[0.95rem] font-bold leading-none text-green-700">
                {{ __("This week's commission (Ksh. :amount)", ['amount' => number_format($this->commissionData['total'], 2)]) }}
            </div>

            @php
                $points = $this->commissionData['points'];
                $max = max($this->commissionData['max'], 1);
                $width = 640;
                $height = 152;
                $paddingX = 56;
                $paddingY = 16;
                $usableWidth = $width - ($paddingX * 2);
                $usableHeight = $height - ($paddingY * 2);
                $chartPath = '';
                $count = count($points);

                foreach ($points as $index => $value) {
                    $x = $count > 1 ? $paddingX + ($usableWidth * ($index / ($count - 1))) : $paddingX + ($usableWidth / 2);
                    $y = $paddingY + ($usableHeight - (($value / $max) * $usableHeight));
                    $chartPath .= ($index === 0 ? 'M' : ' L')." {$x} {$y}";
                }
            @endphp

            <div class="mt-1 overflow-hidden">
                <svg viewBox="0 0 640 152" class="h-[152px] w-full overflow-visible" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="dashboardGrid" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#d9e2d0" stop-opacity="0.85" />
                            <stop offset="100%" stop-color="#d9e2d0" stop-opacity="0.35" />
                        </linearGradient>
                    </defs>

                    @for ($i = 0; $i < 5; $i++)
                        @php
                            $y = $paddingY + (($usableHeight / 4) * $i);
                        @endphp
                        <line x1="{{ $paddingX }}" y1="{{ $y }}" x2="{{ $width - $paddingX }}" y2="{{ $y }}" stroke="url(#dashboardGrid)" stroke-dasharray="8 8" stroke-width="1" />
                        <text x="{{ $paddingX - 14 }}" y="{{ $y + 4 }}" text-anchor="end" class="fill-zinc-500 text-[8px] font-black">
                            0.00
                        </text>
                    @endfor

                    <path d="{{ $chartPath }}" fill="none" stroke="#4b9e33" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />

                    @foreach ($points as $index => $value)
                        @php
                            $x = $count > 1 ? $paddingX + ($usableWidth * ($index / ($count - 1))) : $paddingX + ($usableWidth / 2);
                            $y = $paddingY + ($usableHeight - (($value / $max) * $usableHeight));
                        @endphp
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="3" fill="#4b9e33" stroke="#ffffff" stroke-width="2" />
                    @endforeach
                </svg>

                <div class="mt-0.5 grid grid-cols-7 gap-1 px-1 text-center text-[8px] font-bold uppercase tracking-[0.18em] text-zinc-500">
                    @foreach ($this->weekLabels as $label)
                        <span>{{ $label }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Recent Transactions --}}
        <div class="flex items-center justify-between px-1 pt-1">
            <div class="text-base font-semibold text-zinc-900">{{ __('Recent Transactions') }}</div>
            <flux:button variant="ghost" size="sm" :href="route('transactions')" wire:navigate class="text-sm font-medium text-zinc-600 transition hover:text-green-700">
                {{ __('All') }} <flux:icon.arrow-right class="ml-1 size-4 text-green-600" />
            </flux:button>
        </div>

        <div class="min-h-[200px] px-1 py-4 text-center" wire:poll.10s>
            <div class="text-base font-medium text-zinc-600">
                @forelse($this->recentTransactions as $tx)
                    @php
                        $status = strtolower((string) ($tx->status ?? ''));
                        $isSuccess = in_array($status, ['completed', 'successful'], true);
                        $isFailed = $status === 'failed';
                    @endphp

                    <div @class([
                        'mb-3 rounded-xl px-4 py-3 text-left shadow-sm ring-1 transition last:mb-0',
                        'bg-emerald-50 ring-emerald-100' => $isSuccess,
                        'bg-rose-50 ring-rose-100' => $isFailed,
                        'bg-zinc-50 ring-zinc-200' => ! $isSuccess && ! $isFailed,
                    ])>
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-zinc-900">
                                    {{ $tx->sender_name ?: $tx->sender_phone }}
                                </div>
                                <div class="mt-1 text-[10px] font-bold uppercase tracking-wider text-zinc-500">
                                    {{ $tx->occurred_at?->diffForHumans() ?? '—' }}
                                </div>
                            </div>
                            <div class="text-right">
                                <div @class([
                                    'text-sm font-black',
                                    'text-green-700' => $isSuccess,
                                    'text-rose-700' => $isFailed,
                                    'text-zinc-900' => ! $isSuccess && ! $isFailed,
                                ])>
                                    Ksh {{ number_format((float) $tx->amount, 2) }}
                                </div>
                                <div class="mt-1 text-[10px] font-bold uppercase tracking-wider text-zinc-500">
                                    {{ $tx->offer_name }}
                                </div>
                            </div>
                        </div>

                        @if (filled($tx->status_desc))
                            <div @class([
                                'mt-2 rounded-lg px-3 py-2 text-[10px] font-semibold leading-relaxed ring-1',
                                'bg-green-50 text-green-800 ring-green-100' => $isSuccess,
                                'bg-rose-50 text-rose-800 ring-rose-100' => $isFailed,
                                'bg-zinc-50 text-zinc-700 ring-zinc-200' => ! $isSuccess && ! $isFailed,
                            ])>
                                <span class="mr-1.5 text-[8px] font-black uppercase tracking-wider text-zinc-500">
                                    {{ __('USSD') }}
                                </span>
                                {{ $tx->status_desc }}
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="py-12">
                        <div class="text-base font-semibold text-zinc-700">{{ __('Your most recent transactions will appear here') }}</div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
