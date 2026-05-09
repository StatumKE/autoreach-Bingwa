<?php

use App\Actions\Autoreach\RefreshAirtimeBalance;
use App\Models\Transaction;
use App\Models\Plan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    public bool $showBalance = true;

    public int $refreshKey = 0;

    public ?float $airtimeBalance = null;

    public ?string $airtimeBalanceCheckedAt = null;

    public ?string $airtimeBalanceRawResponse = null;

    public bool $callPhonePermissionDenied = false;

    /**
     * Guards against concurrent USSD bridge calls from overlapping polls.
     * The NativePHP persistent PHP runtime is single-threaded; two simultaneous
     * nativePersistentDispatch calls cause a SIGSEGV null-pointer crash.
     */
    private bool $isRefreshingBalance = false;

    /**
     * Hydrate the dashboard from the latest stored balance snapshot.
     */
    public function mount(): void
    {
        Log::debug('Bingwa dashboard mount started.', [
            'user_id' => Auth::id(),
        ]);

        $this->hydrateAirtimeBalance();

        Log::debug('Bingwa dashboard airtime response ready.', [
            'user_id' => Auth::id(),
            'balance' => $this->airtimeBalance,
            'checked_at' => $this->airtimeBalanceCheckedAt,
        ]);
    }

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

    public function hydrateAirtimeBalance(): void
    {
        $snapshot = app(RefreshAirtimeBalance::class)->cached(Auth::user());

        Log::debug('Bingwa dashboard airtime cache hydrated.', [
            'user_id' => Auth::id(),
            'has_balance' => $snapshot['balance'] !== null,
            'checked_at' => $snapshot['checked_at']?->toIso8601String(),
        ]);

        $this->airtimeBalance = $snapshot['balance'];
        $this->airtimeBalanceCheckedAt = $snapshot['checked_at']?->format('d M, H:i');
        $this->airtimeBalanceRawResponse = $snapshot['raw_response'];
        $this->callPhonePermissionDenied = $snapshot['permission_denied'];
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
        $this->refreshAirtimeBalance();
        $this->refreshKey++;
    }

    /**
     * Refresh the airtime balance from the active transaction SIM.
     *
     * Guard: if a balance refresh is already in-flight (e.g. from a concurrent
     * Livewire poll), skip this call entirely. The NativePHP persistent PHP
     * runtime is single-threaded; concurrent nativePersistentDispatch calls
     * produce a SIGSEGV null-pointer dereference (native_persistent_dispatch+776).
     */
    public function refreshAirtimeBalance(): void
    {
        if ($this->isRefreshingBalance) {
            Log::debug('Bingwa dashboard airtime refresh skipped — already in progress.', [
                'user_id' => Auth::id(),
            ]);

            return;
        }

        $this->isRefreshingBalance = true;

        try {
            Log::debug('Bingwa dashboard airtime refresh requested. Dispatching to background queue.', [
                'user_id' => Auth::id(),
            ]);

            \App\Jobs\RefreshAirtimeBalanceJob::dispatch(Auth::user());

            // We don't have the result immediately anymore. We rely on refreshTransactions()
            // to automatically pull the new balance on the next poll cycle.
        } finally {
            $this->isRefreshingBalance = false;
        }
    }

    /**
     * Dedicated poll target for the recent transactions list.
     * This is a pure DB read — it does NOT touch the USSD bridge.
     * Keeping it separate from refreshAirtimeBalance prevents the two
     * wire:poll directives from ever racing on the native PHP runtime.
     */
    public function refreshTransactions(): void
    {
        $this->hydrateAirtimeBalance();
        
        // Livewire will automatically re-render the recentTransactions computed
        // property on the next render cycle after this method completes.
    }

}; ?>

<div class="min-h-screen bg-app-bg px-4 pb-24 pt-3 text-zinc-900" wire:poll.15m="refreshAirtimeBalance">
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

        {{-- CALL_PHONE permission warning --}}
        @if ($this->callPhonePermissionDenied)
            <div class="col-span-3 flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200">
                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-amber-500" />
                <div class="min-w-0">
                    <div class="text-xs font-bold text-amber-800">{{ __('Phone Permission Required') }}</div>
                    <div class="mt-0.5 text-[11px] leading-snug text-amber-700">
                        {{ __('Airtime balance cannot be fetched. Go to Settings → Apps → Bingwa → Permissions and enable Phone access.') }}
                    </div>
                </div>
            </div>
        @endif
            <a href="{{ route('transactions', ['filter' => 'success']) }}" wire:navigate class="flex flex-col items-center justify-center rounded-xl bg-[#0f652a] px-2 py-2 text-white transition active:scale-[0.97]">
                <div class="text-[10px] font-bold uppercase tracking-wider text-emerald-100/90">{{ __('Successful') }}</div>
                <div class="mt-1 text-lg font-bold leading-none">{{ number_format($this->stats['successful']) }}</div>
            </a>

            <a href="{{ route('transactions', ['filter' => 'failed']) }}" wire:navigate class="flex flex-col items-center justify-center rounded-xl bg-[#ffd9dc] px-2 py-2 text-[#5e181b] transition active:scale-[0.97]">
                <div class="text-[10px] font-bold uppercase tracking-wider text-rose-900/80">{{ __('Failed') }}</div>
                <div class="mt-1 text-lg font-bold leading-none">{{ number_format($this->stats['failed']) }}</div>
            </a>

            <a href="{{ route('plans') }}" wire:navigate class="flex flex-col items-center justify-center rounded-xl bg-[#c8ebfb] px-2 py-2 text-[#12313d] transition active:scale-[0.97]">
                <div class="text-[10px] font-bold uppercase tracking-wider text-sky-900/80">{{ __('Tokens') }}</div>
                <div class="mt-1 text-lg font-bold leading-none">
                    @if ($this->tokenText === __('No Plan'))
                        {{ __('Expired') }}
                    @else
                        {{ $this->tokenText }}
                    @endif
                </div>
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
                <div class="mt-1 flex flex-col items-center gap-1">
                    <div class="flex items-center gap-1.5 text-sm font-medium text-zinc-800">
                        <span>Ksh {{ $this->showBalance ? number_format($this->airtimeBalance ?? 0, 2) : '••••' }}</span>
                        <button wire:click="toggleBalance" class="text-zinc-400" type="button">
                            @if($this->showBalance)
                                <flux:icon.eye class="size-4" />
                            @else
                                <flux:icon.eye-slash class="size-4" />
                            @endif
                        </button>
                    </div>
                    @if ($this->airtimeBalance === null && filled($this->airtimeBalanceRawResponse))
                        <div class="max-w-[120px] truncate text-[9px] font-bold text-rose-600/70" title="{{ $this->airtimeBalanceRawResponse }}">
                            {{ $this->airtimeBalanceRawResponse }}
                        </div>
                    @endif
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
                $height = 100;
                $paddingX = 56;
                $paddingY = 12;
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
                <svg viewBox="0 0 640 100" class="h-[100px] w-full overflow-visible" preserveAspectRatio="none">
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

        <div class="mt-2 min-h-[150px] overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-zinc-200" wire:poll.30s="refreshTransactions">
            <div class="divide-y divide-zinc-100 text-center">
                @forelse($this->recentTransactions as $tx)
                    @php
                        $status = strtolower((string) ($tx->status ?? ''));
                        $isSuccess = in_array($status, ['completed', 'successful'], true);
                        $isFailed = $status === 'failed';
                    @endphp

                    <div @class([
                        'px-2.5 py-1.5 text-left transition',
                        'bg-emerald-50/40 hover:bg-emerald-50/60' => $isSuccess,
                        'bg-rose-50/40 hover:bg-rose-50/60' => $isFailed,
                        'bg-zinc-50/40 hover:bg-zinc-50/60' => ! $isSuccess && ! $isFailed,
                    ])>
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <div class="truncate text-xs font-bold text-zinc-900">
                                    {{ $tx->sender_name ?: $tx->sender_phone }}
                                </div>
                                <div class="shrink-0 rounded bg-black/5 px-1 py-0.5 text-[8px] font-bold uppercase tracking-wider text-zinc-600">
                                    {{ $tx->offer_name }}
                                </div>
                            </div>
                            <div @class([
                                'shrink-0 text-xs font-black',
                                'text-green-700' => $isSuccess,
                                'text-rose-700' => $isFailed,
                                'text-zinc-900' => ! $isSuccess && ! $isFailed,
                            ])>
                                Ksh {{ number_format((float) $tx->amount, 2) }}
                            </div>
                        </div>

                        @if (filled($tx->status_desc))
                            <div class="mt-1 flex items-center justify-between gap-2">
                                <div @class([
                                    'truncate text-[9px] font-medium leading-tight',
                                    'text-green-800/80' => $isSuccess,
                                    'text-rose-800/80' => $isFailed,
                                    'text-zinc-600' => ! $isSuccess && ! $isFailed,
                                ])>
                                    {{ $tx->status_desc }}
                                </div>
                                <div class="shrink-0 text-[8px] font-bold tracking-wider text-zinc-400">
                                    {{ $tx->occurred_at?->diffForHumans(null, true, true) ?? '—' }}
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="py-6">
                        <div class="text-sm font-semibold text-zinc-500">{{ __('Your most recent transactions will appear here') }}</div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
