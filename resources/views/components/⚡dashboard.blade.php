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
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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

    public bool $isProcessingEnabled = true;

    public bool $showTransactionDetails = false;

    public ?int $selectedTransactionId = null;

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
        $this->isProcessingEnabled = \App\Models\DeviceSetting::isTransactionProcessingEnabledForUser((int) Auth::id());

        // Dispatch is best-effort: if SQLite is locked by the background queue worker,
        // swallow the exception rather than returning a 500. The job is ShouldBeUnique
        // so it will be re-dispatched on the next mount anyway.
        try {
            \App\Jobs\PrefetchSubscriptionPlansJob::dispatch((int) Auth::id());
        } catch (\Throwable $e) {
            Log::warning('Dashboard mount: PrefetchSubscriptionPlansJob dispatch skipped (database contention).', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);
        }

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
        $now = AppTimezone::now();
        $labels = [];

        for ($i = 6; $i >= 0; $i--) {
            $labels[] = $now->copy()->subDays($i)->format('D - d');
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
        $this->isProcessingEnabled = \App\Models\DeviceSetting::isTransactionProcessingEnabledForUser((int) Auth::id());
        
        // Livewire will automatically re-render the recentTransactions computed
        // property on the next render cycle after this method completes.
    }

    /**
     * Toggle the transaction processing setting.
     */
    public function toggleProcessing(): void
    {
        $setting = \App\Models\DeviceSetting::firstOrCreate(['user_id' => Auth::id()]);
        $setting->transaction_processing_enabled = !$this->isProcessingEnabled;
        $setting->save();
        $this->isProcessingEnabled = $setting->transaction_processing_enabled;
        
        if ($this->isProcessingEnabled) {
            app(\App\Actions\Autoreach\DispatchBingwaQueuedTransactionsJob::class)->dispatch((int) Auth::id());
        }

        $this->dispatch('modal-close', name: 'toggle-processing-modal');

        Flux::toast(
            variant: 'success',
            text: $this->isProcessingEnabled 
                ? __('Transaction processing activated.') 
                : __('Transaction processing paused.')
        );
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
            $startOfWindow = $now->copy()->subDays(6)->startOfDay();
            $endOfWindow = $now->copy()->endOfDay();

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
                ->whereBetween('occurred_at', [$startOfWindow, $endOfWindow])
                ->select(DB::raw('DATE(occurred_at) as date'), DB::raw('SUM(amount) as total'))
                ->groupBy('date')
                ->get()
                ->pluck('total', 'date')
                ->toArray();

            $dataPoints = [];
            $totalCommission = 0;

            for ($i = 6; $i >= 0; $i--) {
                $date = $now->copy()->subDays($i)->format('Y-m-d');
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


    #[On('open-transaction-details')]
    public function openTransactionDetails(int $transactionId): void
    {
        $this->selectedTransactionId = $transactionId;
        $this->showTransactionDetails = true;
    }

    public function closeTransactionDetails(): void
    {
        $this->showTransactionDetails = false;
        $this->selectedTransactionId = null;
    }

    #[Computed]
    public function selectedTransaction(): ?Transaction
    {
        if ($this->selectedTransactionId === null) {
            return null;
        }

        return Transaction::query()
            ->with(['offer:id,name,ussd_code,ussd_mode'])
            ->where('user_id', Auth::id())
            ->find($this->selectedTransactionId);
    }

    public function transactionProductLabel(Transaction $transaction): string
    {
        return $transaction->offer?->name
            ?? ($transaction->matched_offer['offer_name'] ?? $transaction->offer_name ?? '—');
    }

    public function resolvedUssdCode(Transaction $transaction): string
    {
        $ussdCode = (string) ($transaction->offer?->ussd_code ?? '');

        if ($ussdCode === '') {
            return '—';
        }

        return str_replace('PN', (string) $transaction->sender_phone, $ussdCode);
    }

    public function formatDetailValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || $value === '') {
            return '—';
        }

        return (string) $value;
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
    x-init="checkAccessibility(); const _bingwaTimer = setInterval(() => checkAccessibility(), 10000); return () => clearInterval(_bingwaTimer);"
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
            class="rounded-[1.5rem] border border-amber-300/60 bg-gradient-to-br from-amber-500/10 via-rose-500/5 to-amber-500/5 px-4 py-4 shadow-xl shadow-black/[0.03] ring-1 ring-amber-300/20 backdrop-blur-xl transition-all hover:border-amber-300/80"
            x-cloak
        >
            <div class="flex items-start gap-3">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-amber-300/40 bg-amber-100/80">
                    <flux:icon.exclamation-circle class="size-5 text-amber-700" />
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="text-xs font-black uppercase tracking-[0.24em] text-amber-900">{{ __('Accessibility service off') }}</div>
                        <span class="inline-flex items-center rounded-full border border-amber-300 bg-amber-200/50 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.24em] text-amber-900">
                            {{ __('Required') }}
                        </span>
                    </div>

                    <div class="mt-1 text-[11px] leading-snug text-zinc-700">
                        {{ __('Bingwa needs accessibility enabled to read USSD replies and confirm a successful transaction.') }}
                    </div>

                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-3">
                            <div class="text-[9px] font-black uppercase tracking-[0.26em] text-amber-800">{{ __('Quick path') }}</div>
                            <p class="mt-1 text-[10px] leading-5 text-zinc-600">
                                {{ __('Tap Open Accessibility, then turn on Bingwa USSD Automation.') }}
                            </p>
                            <p class="mt-2 text-[10px] leading-5 text-zinc-500">
                                <strong class="font-bold text-zinc-800">{{ __('Samsung') }}</strong>{{ __(': choose Installed apps.') }} <strong class="font-bold text-zinc-800">{{ __('Other Android phones') }}</strong>{{ __(': look for Downloaded services or Installed services.') }}
                            </p>
                        </div>

                        <div class="rounded-2xl border border-rose-200 bg-rose-50/30 p-3">
                            <div class="text-[9px] font-black uppercase tracking-[0.26em] text-rose-800">{{ __('If restricted') }}</div>
                            <p class="mt-1 text-[10px] leading-5 text-zinc-600">
                                {{ __('Open App Info, tap More, then Allow restricted settings before enabling the service.') }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <button
                            type="button"
                            id="btn-enable-accessibility"
                            @click="openAccessibility"
                            class="inline-flex h-11 items-center justify-center rounded-2xl bg-amber-600 hover:bg-amber-700 active:bg-amber-850 px-4 text-[10px] font-black uppercase tracking-[0.24em] text-white transition active:scale-[0.98]"
                        >
                            {{ __('Open Accessibility') }}
                        </button>
                        <button
                            type="button"
                            id="btn-open-app-info"
                            @click="openAppInfo"
                            class="inline-flex h-11 items-center justify-center rounded-2xl border border-amber-200 bg-white px-4 text-[10px] font-black uppercase tracking-[0.24em] text-amber-800 transition hover:bg-amber-50 active:scale-[0.98]"
                        >
                            {{ __('Open App Info') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>


        {{-- Stat Cards --}}
        @island
        <div wire:poll.visible.5s class="grid grid-cols-3 gap-2">
        {{-- Processing Paused Warning --}}
        @if(!$this->isProcessingEnabled)
        <div class="col-span-3 flex items-start gap-3 rounded-xl bg-rose-50 px-4 py-3 ring-1 ring-rose-200">
            <flux:icon.pause-circle class="mt-0.5 size-4 shrink-0 text-rose-500" />
            <div class="min-w-0">
                <div class="text-xs font-bold text-rose-800">{{ __('Processing Paused') }}</div>
                <div class="mt-0.5 text-[11px] leading-snug text-rose-700">
                    {{ __('Transaction processing is currently paused. New transactions will be queued but not processed until you activate it.') }}
                </div>
            </div>
        </div>
        @endif

        {{-- CALL_PHONE permission warning --}}
        <div
            x-data="{
                phoneGranted: true,
                async checkPhonePermission() {
                    try {
                        const res = await fetch('/_native/api/call', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                            },
                            body: JSON.stringify({ method: 'CheckSetupStatus', params: {} }),
                        });
                        if (!res.ok) return;
                        const data = (await res.json())?.data;
                        if (data) {
                            this.phoneGranted = data.phoneGranted ?? true;
                        }
                    } catch {}
                },
                async requestPhonePermission() {
                    try {
                        await fetch('/_native/api/call', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                            },
                            body: JSON.stringify({ method: 'RequestSetupPermissions', params: { force: true } }),
                        });
                        setTimeout(() => this.checkPhonePermission(), 1000);
                    } catch {}
                }
            }"
            x-init="checkPhonePermission()"
            @visibilitychange.window="if (document.visibilityState === 'visible') checkPhonePermission()"
            x-show="!phoneGranted"
            x-cloak
            class="col-span-3 flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-amber-200"
        >
            <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-amber-500" />
            <div class="min-w-0">
                <div class="text-xs font-bold text-amber-800">{{ __('Phone Permission Required') }}</div>
                <div class="mt-0.5 text-[11px] leading-snug text-amber-700">
                    {{ __('Airtime balance cannot be fetched. Enable Phone access to see your balance.') }}
                </div>
                <button 
                    type="button"
                    x-on:click="requestPhonePermission()"
                    class="mt-2 rounded-lg bg-amber-200/50 px-3 py-1 text-[10px] font-bold text-amber-900 transition hover:bg-amber-200 active:scale-95"
                >
                    {{ __('Grant Access') }}
                </button>
            </div>
        </div>
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
        <div wire:poll.visible.300s="refreshAirtimeBalance" class="grid grid-cols-[1fr_1fr_auto] items-center gap-2 rounded-xl bg-[#f6f8f0] px-2 py-1.5 ring-1 ring-black/5">
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
                {{ __("Last 7 days commission (Ksh. :amount)", ['amount' => number_format($this->commissionData['total'], 2)]) }}
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

                            <div 
                                @click="$dispatch('open-transaction-details', { transactionId: {{ $tx->id }} })"
                                role="button"
                                tabindex="0"
                                @class([
                                'px-3 py-2 text-left transition cursor-pointer active:scale-[0.99]',
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

        {{-- Floating Action Button --}}
        <div class="fixed bottom-24 right-4 z-50 lg:bottom-8 lg:right-8">
            <flux:modal.trigger name="toggle-processing-modal">
                <button
                    type="button"
                    class="flex h-12 w-12 items-center justify-center rounded-full shadow-lg ring-1 transition-transform active:scale-95 focus:outline-none"
                    :class="$wire.isProcessingEnabled ? 'bg-green-600 text-white ring-green-700' : 'bg-rose-600 text-white ring-rose-700'"
                >
                    @if($this->isProcessingEnabled)
                        <flux:icon.pause variant="solid" class="size-6" />
                    @else
                        <flux:icon.play variant="solid" class="size-6 ml-0.5" />
                    @endif
                </button>
            </flux:modal.trigger>
        </div>

        {{-- Toggle Modal --}}
        <flux:modal name="toggle-processing-modal" class="max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        @if($this->isProcessingEnabled)
                            {{ __('Pause Transaction Processing?') }}
                        @else
                            {{ __('Activate Transaction Processing?') }}
                        @endif
                    </flux:heading>
                    <flux:text class="mt-2 text-sm text-zinc-500">
                        @if($this->isProcessingEnabled)
                            {{ __('Pausing transaction processing will stop the app from automatically fulfilling incoming SMS or queuing USSD codes. Incoming requests will be kept in the queue until you activate processing again.') }}
                        @else
                            {{ __('Activating transaction processing will allow the app to resume automatically fulfilling incoming requests via USSD.') }}
                        @endif
                    </flux:text>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button
                        :variant="$this->isProcessingEnabled ? 'danger' : 'primary'"
                        wire:click="toggleProcessing"
                        x-on:click="$dispatch('modal-close', { name: 'toggle-processing-modal' })"
                    >
                        @if($this->isProcessingEnabled)
                            {{ __('Pause Processing') }}
                        @else
                            {{ __('Activate Processing') }}
                        @endif
                    </flux:button>
                </div>
            </div>
        </flux:modal>

    <flux:modal
        name="transaction-details"
        wire:model.self="showTransactionDetails"
        class="w-[min(100vw-1rem,48rem)] max-w-3xl"
        @close="closeTransactionDetails"
        scroll="body"
    >
        @php
            $selectedTransaction = $this->selectedTransaction;
        @endphp

        @if ($selectedTransaction)
            <div x-data="{ copied: false }" class="space-y-3">
                <div class="space-y-0.5">
                    <flux:heading size="md">{{ __('Transaction Details') }}</flux:heading>
                </div>

                <div class="flex flex-col gap-2">
                    {{-- Status Card --}}
                    <div class="rounded-xl bg-zinc-50 p-2.5 px-3 ring-1 ring-zinc-200">
                        <div class="grid @if($selectedTransaction->next_attempt_at) grid-cols-2 gap-4 @else grid-cols-1 @endif">
                            <div>
                                <div class="text-[9px] font-black uppercase tracking-widest text-zinc-400">{{ __('Status') }}</div>
                                <div class="mt-0.5 text-xs font-bold text-zinc-900">
                                    {{ blank($selectedTransaction->status) ? __('Pending') : $selectedTransaction->status }}
                                    @if ($selectedTransaction->status === 'failed' && $selectedTransaction->next_attempt_at)
                                        <span class="ml-2 inline-flex items-center rounded-md bg-amber-500/10 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-widest text-amber-600">
                                            {{ __('Rescheduled') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            @if ($selectedTransaction->next_attempt_at)
                                <div>
                                    <div class="text-[9px] font-black uppercase tracking-widest text-zinc-400">{{ __('Rescheduled For') }}</div>
                                    <div class="mt-0.5 text-xs font-bold text-zinc-900">{{ AppTimezone::format($selectedTransaction->next_attempt_at, 'H:i, M j, Y') }}</div>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Amount and Sender Card --}}
                    <div class="rounded-xl bg-zinc-50 p-2.5 px-3 ring-1 ring-zinc-200">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <div class="text-[9px] font-black uppercase tracking-widest text-zinc-400">{{ __('Amount') }}</div>
                                <div class="mt-0.5 text-xs font-bold text-zinc-900">Ksh {{ number_format((float) $selectedTransaction->amount) }}</div>
                            </div>
                            <div>
                                <div class="text-[9px] font-black uppercase tracking-widest text-zinc-400">{{ __('Sender') }}</div>
                                <div class="mt-0.5 text-xs font-bold text-zinc-900">{{ $selectedTransaction->sender_name ?: __('Unknown sender') }}</div>
                                <div class="mt-0.5 flex items-center gap-2">
                                    <div class="text-[10px] text-zinc-500">{{ $selectedTransaction->sender_phone }}</div>
                                    @if (filled($selectedTransaction->sender_phone))
                                        <flux:button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="!h-5 px-1.5 text-[9px] font-bold uppercase tracking-widest text-zinc-500"
                                            data-phone="{{ $selectedTransaction->sender_phone }}"
                                            x-on:click="
                                                let phone = $el.getAttribute('data-phone');
                                                let fallbackCopy = function(text) {
                                                    let ta = document.createElement('textarea');
                                                    ta.value = text;
                                                    ta.style.position = 'fixed';
                                                    ta.style.left = '-9999px';
                                                    ta.style.top = '0';
                                                    ta.setAttribute('readonly', '');
                                                    document.body.appendChild(ta);
                                                    ta.select();
                                                    ta.setSelectionRange(0, 99999);
                                                    document.execCommand('copy');
                                                    document.body.removeChild(ta);
                                                };
                                                if (navigator.clipboard && navigator.clipboard.writeText) {
                                                    navigator.clipboard.writeText(phone).catch(() => fallbackCopy(phone));
                                                } else {
                                                    fallbackCopy(phone);
                                                }
                                                copied = true;
                                                setTimeout(() => copied = false, 1200);
                                            "
                                        >
                                            <span x-show="!copied">{{ __('Copy') }}</span>
                                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- M-PESA Code and Matched Offer Card --}}
                    <div class="rounded-xl bg-zinc-50 p-2.5 px-3 ring-1 ring-zinc-200">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <div class="text-[9px] font-black uppercase tracking-widest text-zinc-400">{{ __('M-PESA Code') }}</div>
                                <div class="mt-0.5 text-xs font-bold text-zinc-900">{{ $selectedTransaction->mpesa_code ?: '—' }}</div>
                            </div>
                            <div>
                                <div class="text-[9px] font-black uppercase tracking-widest text-zinc-400">{{ __('Matched App Product') }}</div>
                                <div class="mt-0.5 text-xs font-bold text-zinc-900">{{ $this->transactionProductLabel($selectedTransaction) }}</div>
                                <div class="mt-0.5 text-[10px] text-zinc-500">{{ $selectedTransaction->offer_type ?: '—' }}</div>
                            </div>
                        </div>
                    </div>

                    {{-- USSD Code Card --}}
                    <div class="rounded-xl bg-zinc-50 p-2.5 px-3 ring-1 ring-zinc-200">
                        <div class="text-[9px] font-black uppercase tracking-widest text-zinc-400">{{ __('USSD Code') }}</div>
                        <div class="mt-0.5 font-mono text-xs font-bold text-zinc-900 break-all">{{ $this->resolvedUssdCode($selectedTransaction) }}</div>
                        <div class="mt-0.5 text-[10px] text-zinc-500">{{ $selectedTransaction->offer?->ussd_mode ?: '—' }}</div>
                    </div>
                </div>

                {{-- Compact grouped dates panel --}}
                <div class="rounded-xl bg-zinc-50/50 p-2.5 px-3 ring-1 ring-zinc-200">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-2.5">
                        <div>
                            <span class="text-[9px] font-black uppercase tracking-widest text-zinc-400 block">{{ __('Occurred At') }}</span>
                            <span class="text-xs font-bold text-zinc-900 mt-0.5 block">{{ AppTimezone::format($selectedTransaction->occurred_at) }}</span>
                        </div>
                        <div>
                            <span class="text-[9px] font-black uppercase tracking-widest text-zinc-400 block">{{ __('Processed At') }}</span>
                            <span class="text-xs font-bold text-zinc-900 mt-0.5 block">{{ AppTimezone::format($selectedTransaction->processed_at) }}</span>
                        </div>
                        <div>
                            <span class="text-[9px] font-black uppercase tracking-widest text-zinc-400 block">{{ __('Created At') }}</span>
                            <span class="text-xs font-bold text-zinc-900 mt-0.5 block">{{ AppTimezone::format($selectedTransaction->created_at) }}</span>
                        </div>
                        <div>
                            <span class="text-[9px] font-black uppercase tracking-widest text-zinc-400 block">{{ __('Updated At') }}</span>
                            <span class="text-xs font-bold text-zinc-900 mt-0.5 block">{{ AppTimezone::format($selectedTransaction->updated_at) }}</span>
                        </div>
                    </div>
                </div>

                @if ($selectedTransaction->raw_sms)
                    <div class="space-y-1">
                        <div class="text-[9px] font-black uppercase tracking-widest text-zinc-400">{{ __('Raw SMS') }}</div>
                        <div class="rounded-xl bg-zinc-50 p-2.5 text-xs leading-relaxed text-zinc-800 ring-1 ring-zinc-200 break-words">
                            {{ $selectedTransaction->raw_sms }}
                        </div>
                    </div>
                @endif

                <div class="space-y-1">
                    <div class="text-[9px] font-black uppercase tracking-widest text-zinc-400">{{ __('USSD Result') }}</div>
                    <div class="rounded-xl bg-zinc-50 p-2.5 text-xs leading-relaxed text-zinc-800 ring-1 ring-zinc-200">
                        {{ $selectedTransaction->status_desc ?: __('—') }}
                    </div>
                </div>

                @if ($selectedTransaction->balance)
                    <div class="space-y-1">
                        <div class="text-[9px] font-black uppercase tracking-widest text-zinc-400">{{ __('Balance Payload') }}</div>
                        <pre class="overflow-x-auto rounded-xl bg-zinc-950 p-2.5 text-[10px] leading-relaxed text-zinc-100 ring-1 ring-zinc-900">{{ $this->formatDetailValue($selectedTransaction->balance) }}</pre>
                    </div>
                @endif
            </div>
        @else
            <div class="space-y-3">
                <flux:heading size="lg">{{ __('Transaction details') }}</flux:heading>
                <flux:text class="text-sm text-zinc-500">{{ __('Select a transaction to view the full USSD trail.') }}</flux:text>
            </div>
        @endif
    </flux:modal>
    </div>
</div>
