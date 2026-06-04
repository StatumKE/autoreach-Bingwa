<?php

use App\Actions\Autoreach\RefreshAirtimeBalance;
use App\Support\AppTimezone;
use App\Models\Transaction;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
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
    public bool $isRefreshingBalance = false;

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
        return $this->dashboardMetrics()['stats'];
    }

    /**
     * Active plan details.
     */
    public function getActivePlanProperty(): ?Plan
    {
        return Auth::user()->activePlan();
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
        return $this->dashboardMetrics()['airtime'];
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
        $this->airtimeBalanceCheckedAt = AppTimezone::format($snapshot['checked_at'], 'd M, H:i');
        $this->airtimeBalanceRawResponse = $snapshot['raw_response'];
        $this->callPhonePermissionDenied = $snapshot['permission_denied'];
    }

    /**
     * Commission and Chart Data.
     */
    public function getCommissionDataProperty(): array
    {
        return $this->dashboardMetrics()['commission'];
    }


    public function getWeekLabelsProperty(): array
    {
        $date = now()->startOfWeek(Carbon::SUNDAY);
        $labels = [];

        for ($i = 0; $i < 7; $i++) {
            $labels[] = $date->copy()->addDays($i)->format('D - d');
        }

        return $labels;
    }

    /**
     * Recent transactions preview for the dashboard.
     *
     * @return Collection<int, Transaction>
     */
    public function getRecentTransactionsProperty(): Collection
    {
        // NOTE: Do NOT use Cache::remember() here. Caching an Eloquent Collection
        // across ephemeral NativePHP PHP process boots causes __PHP_Incomplete_Class
        // deserialization failures — Transaction model isn't loaded when the cached
        // bytes are unpacked in a fresh process, resulting in a 500 error on the
        // dashboard island. The island polls every 10s so caching is unnecessary.
        return Transaction::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('id')
            ->limit(10)
            ->get([
                'id',
                'sender_name',
                'sender_phone',
                'offer_name',
                'amount',
                'status',
                'status_desc',
                'occurred_at',
            ]);
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
     */
    public function refreshAirtimeBalance(): void
    {
        if ($this->isRefreshingBalance) {
            return;
        }

        $this->isRefreshingBalance = true;

        try {
            Log::debug('Bingwa dashboard airtime refresh requested synchronously.', [
                'user_id' => Auth::id(),
            ]);

            app(RefreshAirtimeBalance::class)->refresh(Auth::user());
            $this->hydrateAirtimeBalance();
        } catch (\Throwable $throwable) {
            Log::error('Dashboard airtime refresh failed.', [
                'user_id' => Auth::id(),
                'message' => $throwable->getMessage(),
            ]);
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

    /**
     * Sync transactions and process jobs.
     */

    public function syncTransactions(): void
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('bingwa:sync-transactions');

            Cache::forget($this->dashboardMetricsCacheKey());
            $this->refreshTransactions();
        } catch (\Throwable $e) {
            Log::error('Dashboard background sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Get the cached dashboard metrics snapshot.
     *
     * Uses a single aggregated query instead of 4 separate round-trips.
     * One query computes today's counts+sum via CASE/WHEN aggregation;
     * a second fetches weekly day-bucket totals for the chart.
     *
     * @return array{
     *     stats: array{successful: int, failed: int},
     *     airtime: array{used_today: float|int},
     *     commission: array{total: float|int, points: array<int, float>, max: float|int}
     * }
     */
    private function dashboardMetrics(): array
    {
        return Cache::remember($this->dashboardMetricsCacheKey(), now()->addSeconds(5), function (): array {
            $userId = (int) Auth::id();
            $now = AppTimezone::now();
            $today = $now->toDateString();
            $startOfWeek = $now->copy()->startOfWeek();
            $endOfWeek = $now->copy()->endOfWeek();

            // Single aggregated query for today's stats
            $todayRow = DB::selectOne(
                'SELECT
                    COALESCE(SUM(CASE WHEN status = ? AND DATE(occurred_at) = ? THEN 1 ELSE 0 END), 0) AS completed_count,
                    COALESCE(SUM(CASE WHEN status = ? AND DATE(occurred_at) = ? THEN 1 ELSE 0 END), 0) AS failed_count,
                    COALESCE(SUM(CASE WHEN status = ? AND DATE(occurred_at) = ? THEN amount ELSE 0 END), 0) AS used_today
                FROM transactions
                WHERE user_id = ?
                  AND occurred_at >= ?',
                ['completed', $today, 'failed', $today, 'completed', $today, $userId, $today],
            );

            $dailyTotals = Transaction::query()
                ->where('user_id', $userId)
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
                $value = (float) ($dailyTotals[$date] ?? 0);

                $dataPoints[] = $value;
                $totalCommission += $value;
            }

            return [
                'stats' => [
                    'successful' => (int) ($todayRow->completed_count ?? 0),
                    'failed' => (int) ($todayRow->failed_count ?? 0),
                ],
                'airtime' => [
                    'used_today' => (float) ($todayRow->used_today ?? 0),
                ],
                'commission' => [
                    'total' => $totalCommission,
                    'points' => $dataPoints,
                    'max' => max($dataPoints) ?: 100,
                ],
            ];
        });
    }

    private function dashboardMetricsCacheKey(): string
    {
        return 'dashboard:metrics:'.Auth::id().':'.AppTimezone::now()->toDateString();
    }

    private function recentTransactionsCacheKey(): string
    {
        return 'dashboard:recent-transactions:'.Auth::id();
    }

}; ?>

<div
    class="h-[calc(100dvh-var(--inset-top,0px)-48px)] overflow-hidden bg-app-bg px-4 pb-4 pt-3 text-zinc-900 lg:h-auto lg:min-h-screen lg:pb-24 lg:pt-3"
    x-data="{
        accessibilityEnabled: true,
        async checkAccessibility() {
            try {
                const response = await fetch('/_native/api/call', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    body: JSON.stringify({
                        method: 'CheckSetupStatus',
                        params: {},
                    }),
                });
                const result = await response.json();
                const data = result?.data ?? result;
                if (data && typeof data.accessibilityEnabled !== 'undefined') {
                    this.accessibilityEnabled = data.accessibilityEnabled;
                }
            } catch (e) {
                console.error(e);
            }
        },
        async openAccessibility() {
            try {
                await fetch('/_native/api/call', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    body: JSON.stringify({
                        method: 'OpenAccessibilitySettings',
                        params: {},
                    }),
                });
            } catch (e) {
                console.error(e);
            }
        },
        async openAppInfo() {
            try {
                await fetch('/_native/api/call', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    body: JSON.stringify({
                        method: 'OpenAppInfo',
                        params: {},
                    }),
                });
            } catch (e) {
                console.error(e);
            }
        }
    }"
    x-init="checkAccessibility(); const timer = setInterval(() => checkAccessibility(), 10000); $cleanup(() => clearInterval(timer));"
    @visibilitychange.window="if (document.visibilityState === 'visible') checkAccessibility()"
>
    <div class="mx-auto flex h-full max-w-[780px] flex-col gap-3 lg:h-auto">
        {{-- Greeting --}}
        <div class="flex items-start justify-between px-1">
            <div class="min-w-0">
                <div class="text-[9px] font-medium leading-tight text-zinc-700">{{ $this->greeting }},</div>
                <div class="text-sm font-bold leading-tight text-zinc-900">{{ auth()->user()->name }}</div>
            </div>
        </div>

        {{-- Accessibility Service Alert --}}
        <div
            x-show="!accessibilityEnabled"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 -translate-y-2 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 -translate-y-2 scale-95"
            class="rounded-xl bg-gradient-to-r from-red-500/10 via-amber-500/10 to-amber-500/5 border border-red-500/20 px-4 py-3 shadow-sm ring-1 ring-red-500/10 flex items-start gap-3 transition-all hover:border-red-500/30"
            x-cloak
        >
            <flux:icon.exclamation-circle class="mt-0.5 size-4 shrink-0 text-red-500 animate-pulse" />
            <div class="min-w-0 flex-1">
                <div class="text-xs font-black text-red-900 leading-tight">{{ __('Accessibility Service Off') }}</div>
                <div class="mt-1 text-[11px] leading-snug text-red-800/90 font-semibold">
                    {{ __('USSD automated package delivery cannot run without the accessibility service.') }}
                </div>
                <div class="mt-1.5 text-[10px] leading-relaxed text-red-800/80">
                    Tap <strong class="font-bold text-red-900">Enable Service</strong> below. On <strong class="font-bold text-red-900">Samsung</strong>: tap <strong class="font-bold text-red-900">Installed apps</strong>. On <strong class="font-bold text-red-900">Xiaomi, Oppo, Realme, Tecno, Infinix & Pixel</strong>: look for <strong class="font-bold text-red-900">Downloaded apps</strong>, <strong class="font-bold text-red-900">Downloaded services</strong> or <strong class="font-bold text-red-900">Installed services</strong>. Turn on <strong class="font-bold text-red-900">Bingwa USSD Automation</strong>.
                </div>
                <div class="mt-1.5 text-[9.5px] leading-relaxed text-red-700/80 border-t border-red-500/10 pt-1.5">
                    If blocked by a <strong class="font-bold text-red-900">"Restricted Setting"</strong> pop-up: Open App Info, tap the three dots (<strong class="font-bold text-red-900">More</strong>) in the top-right corner, select <strong class="font-bold text-red-900">"Allow restricted settings"</strong>, and then enable it.
                </div>
                <div class="mt-2 flex items-center gap-2">
                    <button
                        type="button"
                        id="btn-enable-accessibility"
                        @click="openAccessibility"
                        class="rounded-lg bg-red-600 text-white px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider transition hover:bg-red-700 active:scale-95 shadow-sm hover:shadow"
                    >
                        {{ __('Enable Service') }}
                    </button>
                    <button
                        type="button"
                        id="btn-open-app-info"
                        @click="openAppInfo"
                        class="rounded-lg bg-zinc-800 text-white px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider transition hover:bg-zinc-700 active:scale-95 shadow-sm hover:shadow"
                    >
                        {{ __('Open App Info') }}
                    </button>
                </div>
            </div>
        </div>


        {{-- Stat Cards --}}
        @island
        <div wire:poll.visible.5s class="grid grid-cols-3 gap-2">

        {{-- CALL_PHONE permission warning --}}
        @if ($this->callPhonePermissionDenied)
            <div class="col-span-3 flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200">
                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-amber-500" />
                <div class="min-w-0">
                    <div class="text-xs font-bold text-amber-800">{{ __('Phone Permission Required') }}</div>
                    <div class="mt-0.5 text-[11px] leading-snug text-amber-700">
                        {{ __('Airtime balance cannot be fetched. Enable Phone access to see your balance.') }}
                    </div>
                    <button 
                        type="button"
                        x-on:click="requestSetupPermissionsOnce(true)"
                        class="mt-2 rounded-lg bg-amber-200/50 px-3 py-1 text-[10px] font-bold text-amber-900 transition hover:bg-amber-200 active:scale-95"
                    >
                        {{ __('Grant Access') }}
                    </button>
                </div>
            </div>
        @endif
            <a href="{{ route('transactions', ['filter' => 'success']) }}" wire:navigate class="flex flex-col items-center justify-center rounded-xl bg-[#0f652a] px-1.5 py-1 text-white transition active:scale-[0.97]">
                <div class="text-[8px] font-bold uppercase tracking-wider text-emerald-100/90">{{ __('Successful') }}</div>
                <div class="mt-0.5 text-sm font-bold leading-none">{{ number_format($this->stats['successful']) }}</div>
            </a>

            <a href="{{ route('transactions', ['filter' => 'failed']) }}" wire:navigate class="flex flex-col items-center justify-center rounded-xl bg-[#ffd9dc] px-1.5 py-1 text-[#5e181b] transition active:scale-[0.97]">
                <div class="text-[8px] font-bold uppercase tracking-wider text-rose-900/80">{{ __('Failed') }}</div>
                <div class="mt-0.5 text-sm font-bold leading-none">{{ number_format($this->stats['failed']) }}</div>
            </a>

            <a href="{{ route('plans') }}" wire:navigate class="flex flex-col items-center justify-center rounded-xl bg-[#c8ebfb] px-1.5 py-1 text-[#12313d] transition active:scale-[0.97]">
                <div class="text-[8px] font-bold uppercase tracking-wider text-sky-900/80">{{ __('Tokens') }}</div>
                <div class="mt-0.5 text-sm font-bold leading-none">
                    @if ($this->tokenText === __('No Plan'))
                        {{ __('Expired') }}
                    @else
                        {{ $this->tokenText }}
                    @endif
                </div>
            </a>
        </div>
        @endisland

        @island
        {{-- Airtime Row --}}
        <div wire:poll.visible.180s="refreshAirtimeBalance" class="grid grid-cols-[1fr_1fr_auto] items-center gap-2 rounded-xl bg-[#f6f8f0] px-2 py-1.5 ring-1 ring-black/5">
            <div class="flex flex-col items-center text-center">
                <div class="text-[8px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Airtime Used Today') }}</div>
                <div class="mt-0.5 flex items-center gap-1.5 text-[11px] font-medium text-zinc-800">
                    <span>Ksh {{ $this->showBalance ? number_format($this->airtime['used_today'], 2) : '••••' }}</span>
                    <button wire:click="toggleBalance" class="text-zinc-400" type="button">
                        @if($this->showBalance)
                            <flux:icon.eye class="size-3.5" />
                        @else
                            <flux:icon.eye-slash class="size-3.5" />
                        @endif
                    </button>
                </div>
            </div>

            <div class="flex flex-col items-center text-center border-l border-black/5 pl-2">
                <div class="text-[8px] font-bold uppercase tracking-wider text-zinc-500">{{ __('Airtime Balance') }}</div>
                <div class="mt-0.5 flex flex-col items-center gap-1">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-zinc-800">
                        <span>Ksh {{ $this->showBalance ? number_format($this->airtimeBalance ?? 0, 2) : '••••' }}</span>
                        <button wire:click="toggleBalance" class="text-zinc-400" type="button">
                            @if($this->showBalance)
                                <flux:icon.eye class="size-3.5" />
                            @else
                                <flux:icon.eye-slash class="size-3.5" />
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
                @class([
                    'text-zinc-500 transition hover:text-green-700 disabled:opacity-60 ml-1 pl-2 border-l border-black/5',
                    'animate-spin text-green-600' => $this->isRefreshingBalance
                ])
                type="button"
            >
                <flux:icon.arrow-path class="size-4" />
            </button>
        </div>
        @endisland

        {{-- Commission Chart --}}
        <div class="rounded-xl bg-[#f6f8f0] px-4 py-3 ring-1 ring-black/5">
            <div class="text-center text-[10px] font-bold uppercase tracking-widest text-green-700/90">
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

            <div class="mt-1">
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

        @island
            <div wire:poll.visible.5s class="flex min-h-0 flex-1 flex-col gap-2">
                {{-- Recent Transactions --}}
                <div class="flex items-center justify-between px-1 pt-1">
                    <div class="text-xs font-bold text-zinc-900">{{ __('Recent Transactions') }}</div>
                    <flux:button variant="ghost" size="sm" :href="route('transactions')" wire:navigate class="!h-8 text-xs font-medium text-zinc-600 transition hover:text-green-700">
                        {{ __('All') }} <flux:icon.arrow-right class="ml-1 size-3.5 text-green-600" />
                    </flux:button>
                </div>



                <div class="min-h-0 flex-1 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-zinc-200">
                    <div class="h-full overflow-y-auto overscroll-contain divide-y divide-zinc-100 text-center">
                        @forelse($this->recentTransactions as $tx)
                            @php
                                $status = strtolower((string) ($tx->status ?? ''));
                                $isSuccess = in_array($status, ['completed', 'successful'], true);
                                $isFailed = $status === 'failed';
                            @endphp

                            <div @class([
                                'px-3 py-2 text-left transition',
                                'bg-emerald-50/40 hover:bg-emerald-50/60' => $isSuccess,
                                'bg-rose-50/40 hover:bg-rose-50/60' => $isFailed,
                                'bg-zinc-50/40 hover:bg-zinc-50/60' => ! $isSuccess && ! $isFailed,
                            ])>
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex min-w-0 items-center gap-1.5">
                                        <div class="truncate text-[12px] font-bold text-zinc-900">
                                            {{ $tx->sender_name ?: $tx->sender_phone ?: __('Unknown') }}
                                        </div>
                                        <div class="shrink-0 rounded bg-black/5 px-1 py-0.5 text-[9px] font-bold uppercase tracking-wider text-zinc-600">
                                            {{ $tx->offer_name }}
                                        </div>
                                    </div>
                                    <div @class([
                                        'shrink-0 text-[12px] font-black',
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
                                        <div class="shrink-0 text-[9px] font-bold tracking-wider text-zinc-400">
                                            {{ $tx->occurred_at?->diffForHumans(null, true, true) ?? '—' }}
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="py-12 px-4 text-center">
                                <flux:icon.arrows-right-left class="mx-auto mb-3 size-8 text-zinc-200" />
                                <div class="text-sm font-semibold text-zinc-500">{{ __('No recent transactions found') }}</div>
                                <div class="mt-1 text-xs text-zinc-400">{{ __('Your history will appear here once you start using the app.') }}</div>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endisland
    </div>
</div>
