<?php

use App\Actions\Autoreach\DispatchBingwaQueuedTransactionsJob;
use App\Models\Transaction;
use App\Models\DeviceSetting;
use App\Support\AppTimezone;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Transactions')] class extends Component
{
    use WithPagination;

    public bool $loaded = true;

    public int $refreshKey = 0;

    /**
     * Selected transaction IDs for batch retry.
     *
     * @var array<int, int|string>
     */
    public array $selectedIds = [];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $filter = 'all';

    public ?string $errorMessage = null;

    public bool $showTransactionDetails = false;

    public ?int $selectedTransactionId = null;

    /**
     * Retry a failed or pending transaction by returning it to the queue.
     */
    public function retryTransaction(int $transactionId): void
    {
        $this->errorMessage = null;

        $transaction = Transaction::query()
            ->where('user_id', Auth::id())
            ->find($transactionId);

        if ($transaction === null) {
            $this->errorMessage = __('The selected transaction could not be found.');

            return;
        }

        if (! $this->canRetryTransaction($transaction)) {
            $this->errorMessage = __('Only failed or pending transactions can be retried.');

            return;
        }

        $transaction->update($this->retryPayload());

        $processingStarted = app(DispatchBingwaQueuedTransactionsJob::class)->dispatch((int) Auth::id());

        $this->selectedIds = [];
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $processingStarted
                ? __('Transaction queued and processing started.')
                : __('Transaction queued. Processing is paused.')
        );
    }

    /**
     * Retry the selected transactions in one batch.
     */
    public function retrySelectedTransactions(): void
    {
        $this->errorMessage = null;

        $selectedIds = $this->normalizedSelectedIds();

        if ($selectedIds === []) {
            $this->errorMessage = __('Please select one or more retryable transactions.');

            return;
        }

        $retryableIds = Transaction::query()
            ->where('user_id', Auth::id())
            ->whereIn('id', $selectedIds)
            ->get()
            ->filter(fn (Transaction $transaction): bool => $this->canRetryTransaction($transaction))
            ->pluck('id')
            ->all();

        if ($retryableIds === []) {
            $this->errorMessage = __('Only failed or pending transactions can be retried.');

            return;
        }

        $updatedCount = Transaction::query()
            ->where('user_id', Auth::id())
            ->whereIn('id', $retryableIds)
            ->update($this->retryPayload());

        if ($updatedCount === 0) {
            $this->errorMessage = __('Only failed or pending transactions can be retried.');

            return;
        }

        $processingStarted = app(DispatchBingwaQueuedTransactionsJob::class)->dispatch((int) Auth::id());

        $this->selectedIds = [];
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $updatedCount === 1
                ? ($processingStarted
                    ? __('1 transaction queued and processing started.')
                    : __('1 transaction queued. Processing is paused.'))
                : ($processingStarted
                    ? __(':count transactions queued and processing started.', ['count' => $updatedCount])
                    : __(':count transactions queued. Processing is paused.', ['count' => $updatedCount]))
        );
    }

    /**
     * Sync transactions from the backend manually (with toast).
     */
    public function syncTransactions(): void
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('bingwa:sync-transactions', [
                '--user-id' => Auth::id(),
            ]);

            $processingEnabled = DeviceSetting::isTransactionProcessingEnabledForUser((int) Auth::id());

            Flux::toast(
                variant: 'success',
                text: $processingEnabled
                    ? __('Transactions synced and queued for processing.')
                    : __('Transactions synced and queued. Processing is paused.')
            );
        } catch (\Throwable $e) {
            Log::error('Manual sync failed: ' . $e->getMessage());
            $this->errorMessage = __('Failed to sync transactions. Please try again.');
        }
    }

    /**
     * Delete selected transactions for the authenticated user.
     */
    public function deleteSelectedTransactions(): void
    {
        $selectedIds = $this->normalizedSelectedIds();

        if (empty($selectedIds)) {
            return;
        }

        Transaction::query()
            ->where('user_id', Auth::id())
            ->whereIn('id', $selectedIds)
            ->delete();

        $this->selectedIds = [];
        $this->resetPage();

        \Illuminate\Support\Facades\Cache::forget('dashboard:metrics:'.Auth::id().':'.AppTimezone::now()->toDateString());

        Flux::toast(
            variant: 'success',
            text: __('Selected transactions deleted successfully.')
        );
    }



    /**
     * Open a transaction details modal for the selected transaction.
     */
    public function openTransactionDetails(int $transactionId): void
    {
        $this->selectedTransactionId = $transactionId;
        $this->showTransactionDetails = true;
    }

    /**
     * Close the transaction details modal and clear the selection.
     */
    public function closeTransactionDetails(): void
    {
        $this->showTransactionDetails = false;
        $this->selectedTransactionId = null;
    }

    /**
     * Load transactions after page render without blocking sync.
     */
    public function loadTransactions(): void
    {
        $this->loaded = true;
    }

    /**
     * Reset pagination and clear selection when the search changes.
     */
    public function updatedSearch(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    /**
     * Reset pagination and clear selection when the filter changes.
     */
    public function updatedFilter(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    /**
     * Get the paginated transactions.
     */
    public function transactions()
    {
        if (! $this->loaded) {
            return collect();
        }

        return Transaction::query()
            ->with(['offer:id,name,ussd_code,ussd_mode'])
            ->select([
                'id',
                'user_id',
                'offer_id',
                'transaction_id',
                'mpesa_code',
                'sender_phone',
                'sender_name',
                'amount',
                'offer_name',
                'offer_type',
                'matched_offer',
                'balance',
                'occurred_at',
                'status',
                'status_desc',
                'processed_at',
                'created_at',
                'updated_at',
            ])
            ->where('user_id', Auth::id())
            ->when($this->filter !== 'all', function ($query) {
                $status = $this->filter === 'success' ? 'completed' : $this->filter;
                $query->where('status', $status);
            })
            ->when($this->search !== '', function ($query) {
                $query->where(function ($q) {
                    $q->where('sender_phone', 'like', '%' . $this->search . '%')
                        ->orWhere('sender_name', 'like', '%' . $this->search . '%')
                        ->orWhere('mpesa_code', 'like', '%' . $this->search . '%')
                        ->orWhere('transaction_id', 'like', '%' . $this->search . '%');
                });
            })
            ->orderBy('id', 'desc')
            ->paginate(10);
    }

    /**
     * Get the selected transaction with its detailed context.
     */
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

    /**
     * Format matched offer details for a mobile-friendly display.
     */
    public function matchedOfferSummary(mixed $matchedOffer): string
    {
        if (is_array($matchedOffer)) {
            return collect([
                $matchedOffer['offer_name'] ?? null,
                $matchedOffer['offer_key'] ?? null,
            ])->filter()->implode(' · ');
        }

        return is_string($matchedOffer) && $matchedOffer !== '' ? $matchedOffer : '—';
    }

    /**
     * Resolve the display label for the app product matched to a transaction.
     */
    public function transactionProductLabel(Transaction $transaction): string
    {
        return $transaction->offer?->name
            ?? ($transaction->matched_offer['offer_name'] ?? $transaction->offer_name ?? '—');
    }

    /**
     * Resolve the final USSD code sent to the device.
     */
    public function resolvedUssdCode(Transaction $transaction): string
    {
        $ussdCode = (string) ($transaction->offer?->ussd_code ?? '');

        if ($ussdCode === '') {
            return '—';
        }

        return str_replace('PN', (string) $transaction->sender_phone, $ussdCode);
    }

    /**
     * Format a scalar or array value for the detail panel.
     */
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

    /**
     * Determine whether the transaction can be retried from the UI.
     */
    public function canRetryTransaction(Transaction $transaction): bool
    {
        $status = strtolower(trim((string) $transaction->status));

        return in_array($status, ['failed', 'pending', ''], true);
    }

    /**
     * Return the payload used when queueing a transaction for retry.
     *
     * @return array<string, mixed>
     */
    private function retryPayload(): array
    {
        return [
            'status' => 'queued',
            'status_desc' => __('Retry requested from the Transactions page.'),
            'next_attempt_at' => now(),
            'processed_at' => null,
            'auto_reply_id' => null,
            'auto_reply_trigger_condition' => null,
            'auto_reply_message' => null,
            'auto_reply_recipient_phone' => null,
            'auto_reply_sim_slot' => null,
            'auto_reply_status' => null,
            'auto_reply_attempts' => 0,
            'auto_reply_sent_at' => null,
            'auto_reply_failed_at' => null,
            'auto_reply_failure_reason' => null,
        ];
    }

    /**
     * Normalize selected IDs to a unique integer list.
     *
     * @return array<int, int>
     */
    private function normalizedSelectedIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (int|string $selectedId): int => (int) $selectedId,
            $this->selectedIds
        )));
    }

    /**
     * Toggle selection of all retryable transactions on the current page.
     */
    public function toggleSelectAllPage(bool $currentlySelected): void
    {
        $transactions = $this->transactions();
        $pageIds = [];
        if ($transactions instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator || method_exists($transactions, 'items')) {
            $pageIds = collect($transactions->items())
                ->filter(fn (Transaction $t): bool => $this->canRetryTransaction($t))
                ->pluck('id')
                ->all();
        }

        if ($currentlySelected) {
            // Remove page IDs from selection
            $this->selectedIds = array_values(array_diff($this->selectedIds, $pageIds));
        } else {
            // Add page IDs to selection
            $this->selectedIds = array_values(array_unique(array_merge($this->selectedIds, $pageIds)));
        }
    }
};
?>

<div class="flex flex-col gap-3 px-4 pb-24 pt-3 bg-app-bg text-zinc-900 min-h-screen">
    @php
        $transactions = $this->transactions();
    @endphp

    <div class="flex items-center justify-between px-1">
        <div class="text-xl font-bold text-zinc-900">{{ __('Transactions') }}</div>
        <flux:button
            type="button"
            variant="ghost"
            wire:click="syncTransactions"
            wire:loading.attr="disabled"
            class="app-secondary-button !h-9 px-3 text-[10px] font-bold uppercase tracking-widest"
        >
            <span wire:loading.remove wire:target="syncTransactions">
                <flux:icon.arrow-path variant="mini" class="size-3.5 mr-1" />
                {{ __('Sync') }}
            </span>
            <span wire:loading wire:target="syncTransactions" class="inline-flex items-center gap-1.5">
                <flux:icon.loading variant="mini" class="size-3" />
                {{ __('Syncing…') }}
            </span>
        </flux:button>
    </div>



    <div class="flex flex-col gap-3">
        <flux:input 
            wire:model.live.debounce.400ms="search" 
            placeholder="{{ __('Search phone, name, or M-PESA code…') }}" 
            class="rounded-xl shadow-sm bg-white ring-1 ring-zinc-200 text-zinc-900"
        >
            <x-slot name="icon">
                <flux:icon.magnifying-glass class="text-zinc-500" />
            </x-slot>
        </flux:input>

        <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
            @foreach ([
                'all' => ['label' => __('All'), 'active' => 'bg-green-600 text-white shadow-sm ring-1 ring-inset ring-green-700/20', 'inactive' => 'bg-white text-zinc-500 ring-1 ring-inset ring-zinc-200 hover:bg-zinc-50 hover:text-zinc-900'],
                'success' => ['label' => __('Success'), 'active' => 'bg-green-600 text-white shadow-sm ring-1 ring-inset ring-green-700/20', 'inactive' => 'bg-white text-zinc-500 ring-1 ring-inset ring-zinc-200 hover:bg-zinc-50 hover:text-zinc-900'],
                'failed' => ['label' => __('Failed'), 'active' => 'bg-rose-600 text-white shadow-sm ring-1 ring-inset ring-rose-700/20', 'inactive' => 'bg-white text-zinc-500 ring-1 ring-inset ring-zinc-200 hover:bg-zinc-50 hover:text-zinc-900'],
                'queued' => ['label' => __('Queued'), 'active' => 'bg-amber-500 text-white shadow-sm ring-1 ring-inset ring-amber-600/20', 'inactive' => 'bg-white text-zinc-500 ring-1 ring-inset ring-zinc-200 hover:bg-zinc-50 hover:text-zinc-900'],
            ] as $value => $styles)
                <flux:button
                    type="button"
                    variant="ghost"
                    wire:click="$set('filter', '{{ $value }}')"
                    @class([
                        'shrink-0 rounded-xl h-8 px-4 text-[10px] font-bold uppercase tracking-widest transition active:scale-95',
                        $styles['active'] => $this->filter === $value,
                        $styles['inactive'] => $this->filter !== $value,
                    ])
                >
                    <span class="inline-flex items-center gap-1.5">
                        @if ($this->filter === $value)
                            <span class="size-1.5 rounded-full bg-current"></span>
                        @endif

                        <span>{{ $styles['label'] }}</span>
                    </span>
                </flux:button>
            @endforeach
        </div>
    </div>

    @if ($this->loaded && ! empty($this->selectedIds))
        <div class="flex items-center justify-between gap-3 rounded-xl bg-green-50 px-4 py-3 ring-1 ring-green-100">
            <div class="text-sm font-bold text-green-800">
                {{ count($this->selectedIds) === 1 ? __('1 transaction selected') : __(':count transactions selected', ['count' => count($this->selectedIds)]) }}
            </div>

            <div class="flex items-center gap-2">
                <flux:modal.trigger name="confirm-delete">
                    <flux:button
                        type="button"
                        variant="ghost"
                        class="!h-8 px-3 text-[10px] font-bold uppercase tracking-widest text-rose-600 hover:text-rose-700 hover:bg-rose-50"
                    >
                        <flux:icon.trash variant="mini" class="size-3.5 mr-1 text-rose-600" />
                        {{ __('Delete') }}
                    </flux:button>
                </flux:modal.trigger>


                <flux:button
                    type="button"
                    variant="ghost"
                    wire:click="retrySelectedTransactions"
                    wire:loading.attr="disabled"
                    class="app-secondary-button !h-8 px-3 text-[10px] font-bold uppercase tracking-widest"
                >
                    <span wire:loading.remove wire:target="retrySelectedTransactions">
                        {{ __('Retry Selected') }}
                    </span>
                    <span wire:loading wire:target="retrySelectedTransactions" class="inline-flex items-center gap-1.5">
                        <flux:icon.loading variant="mini" class="size-3" />
                        {{ __('Retrying…') }}
                    </span>
                </flux:button>


                <flux:button
                    type="button"
                    variant="ghost"
                    wire:click="$set('selectedIds', [])"
                    class="app-secondary-button !h-8 px-3 text-[10px] font-bold uppercase tracking-widest"
                >
                    {{ __('Clear') }}
                </flux:button>
            </div>
        </div>
    @endif

    @if (! $this->loaded)
        <div class="flex flex-col gap-4">
            @for ($i = 0; $i < 4; $i++)
                <div class="relative overflow-hidden rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200 animate-pulse">
                    <div class="relative h-4 w-24 rounded bg-zinc-100"></div>
                    <div class="relative mt-4 h-6 w-40 rounded bg-zinc-100"></div>
                    <div class="relative mt-4 h-16 w-full rounded bg-zinc-100/70"></div>
                </div>
            @endfor
        </div>
    @elseif ($this->errorMessage)
        <div class="rounded-2xl bg-rose-500/10 p-4 text-sm text-rose-600 dark:text-rose-400 font-bold border border-rose-500/20">
            {{ $this->errorMessage }}
        </div>
    @elseif ($transactions->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-zinc-200">
            <div class="flex size-12 items-center justify-center rounded-2xl bg-green-50 text-green-600 mb-4 shadow-inner">
                <flux:icon.banknotes class="size-6" />
            </div>
            <div class="text-base font-bold text-zinc-900">
                {{ __('Empty Ledger') }}
            </div>
            <flux:text class="mt-1 text-sm text-zinc-500 max-w-[200px] mx-auto">
                {{ __('Once payments arrive, the transaction history will appear here.') }}
            </flux:text>
        </div>
    @else
        <div class="flex flex-col gap-4">
            @php
                $retryableTransactionsOnPage = collect($transactions->items())->filter(fn($t) => $this->canRetryTransaction($t));
                $retryableCount = $retryableTransactionsOnPage->count();
                $selectedRetryableCount = collect($this->selectedIds)->intersect($retryableTransactionsOnPage->pluck('id'))->count();
                $allPageRetryableSelected = $retryableCount > 0 && $selectedRetryableCount === $retryableCount;
            @endphp

            @if ($retryableCount > 0)
                <div class="flex items-center gap-3 px-2 py-1">
                    <label class="relative flex items-center cursor-pointer">
                        <input
                            type="checkbox"
                            wire:click="toggleSelectAllPage({{ $allPageRetryableSelected ? 'true' : 'false' }})"
                            @checked($allPageRetryableSelected)
                            class="peer sr-only"
                            id="select-all-checkbox"
                        >
                        <div @class([
                            'flex size-4 items-center justify-center rounded border-2 bg-white transition-all',
                            'border-zinc-200' => ! $allPageRetryableSelected,
                            'border-green-600 bg-green-600' => $allPageRetryableSelected,
                        ])>
                            <flux:icon.check variant="mini" @class([
                                'size-3 text-white transition-transform',
                                'scale-100' => $allPageRetryableSelected,
                                'scale-0' => ! $allPageRetryableSelected,
                            ]) />
                        </div>
                    </label>
                    <span class="text-xs font-bold text-zinc-500 uppercase tracking-widest">{{ __('Select All') }}</span>
                </div>
            @endif

            @foreach ($transactions as $transaction)
                @php
                    $status = strtolower((string) ($transaction->status ?? ''));
                    $isSuccess = in_array($status, ['completed', 'successful']);
                    $isFailed = $status === 'failed';
                    $canRetry = $this->canRetryTransaction($transaction);
                    $productLabel = $this->transactionProductLabel($transaction);
                    $isSelected = in_array($transaction->id, $this->selectedIds ?? []);
                @endphp
                <article
                    wire:key="transaction-{{ $transaction->id }}"
                    wire:click="openTransactionDetails({{ $transaction->id }})"
                    role="button"
                    tabindex="0"
                    @class([
                        'group relative rounded-xl bg-white p-2 shadow-sm ring-1 transition hover:-translate-y-0.5 hover:ring-green-500/30 cursor-pointer',
                        'ring-green-500/50 bg-green-50/10' => $isSelected,
                        'ring-zinc-200' => ! $isSelected,
                    ])
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <label
                                x-on:click.stop
                                @class([
                                'relative flex items-center',
                                'cursor-pointer' => $canRetry,
                                'cursor-not-allowed opacity-40' => ! $canRetry,
                            ])>
                                <input
                                    type="checkbox"
                                    wire:model.live="selectedIds"
                                    value="{{ $transaction->id }}"
                                    @disabled(! $canRetry)
                                    class="peer sr-only"
                                >
                                <div @class([
                                    'flex size-4 items-center justify-center rounded border-2 bg-white transition-all',
                                    'border-zinc-200' => ! $isSelected,
                                    'border-green-600 bg-green-600' => $isSelected,
                                ])>
                                    <flux:icon.check variant="mini" @class([
                                        'size-3 text-white transition-transform',
                                        'scale-100' => $isSelected,
                                        'scale-0' => ! $isSelected,
                                    ]) />
                                </div>
                            </label>
                            <div class="flex items-center gap-2">
                                <span @class([
                                    'inline-flex items-center rounded-md px-1.5 py-0.5 text-[8px] font-black uppercase tracking-widest',
                                    'bg-green-500/10 text-green-600 dark:bg-green-500/20 dark:text-green-400' => $isSuccess,
                                    'bg-rose-500/10 text-rose-600 dark:bg-rose-500/20 dark:text-rose-400' => $isFailed,
                                    'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400' => $status === 'queued',
                                    'bg-zinc-100 text-zinc-600 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-700' => ! $isSuccess && ! $isFailed && $status !== 'queued',
                                ])>
                                    {{ blank($transaction->status) ? __('Pending') : $transaction->status }}
                                </span>
                            </div>

                            <div class="flex items-center gap-2 text-xs font-medium text-zinc-700">
                                @if ($transaction->sender_name || $transaction->sender_phone)
                                    <span>{{ $transaction->sender_name ?: $transaction->sender_phone }}</span>
                                    @if ($transaction->sender_phone && $transaction->sender_name)
                                        <span class="text-[10px] text-zinc-400">({{ $transaction->sender_phone }})</span>
                                    @endif
                                @else
                                    <span class="text-zinc-400 italic">{{ __('Unknown sender') }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex min-w-0 flex-col items-end gap-0.5 text-right">
                            <div @class([
                                'text-xs font-bold tracking-tight',
                                'text-green-600' => $isSuccess,
                                'text-zinc-900' => ! $isSuccess,
                            ])>
                                Ksh {{ number_format((float) ($transaction->amount ?? 0)) }}
                            </div>
                            <span class="text-[9px] font-bold text-zinc-400 uppercase tracking-widest whitespace-nowrap">
                                {{ AppTimezone::format($transaction->occurred_at, 'H:i, M j') }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-2 dark:border-zinc-800">
                        @if (! empty($transaction->mpesa_code))
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-green-50 px-1.5 py-0.5 text-[9px] font-bold text-green-700 ring-1 ring-green-100 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20">
                                <span class="text-[7px] opacity-60 uppercase tracking-widest">{{ __('M-PESA') }}</span>
                                {{ $transaction->mpesa_code }}
                            </span>
                        @endif


                        @if ($productLabel !== '—')
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-zinc-50 px-1.5 py-0.5 text-[9px] font-bold text-zinc-600 ring-1 ring-zinc-200 dark:bg-zinc-800/50 dark:text-zinc-400 dark:ring-zinc-700/50">
                                <span class="text-[7px] opacity-60 uppercase tracking-widest">{{ __('Product') }}</span>
                                <span class="truncate max-w-[120px]">{{ $productLabel }}</span>
                            </span>
                        @endif

                    </div>

                    @if (filled($transaction->status_desc))
                        <div @class([
                            'mt-2 rounded-lg px-2 py-1.5 text-[10px] font-medium leading-snug ring-1',
                            'bg-green-50 text-green-800 ring-green-100 dark:bg-green-500/10 dark:text-green-300 dark:ring-green-500/20' => $isSuccess,
                            'bg-rose-50 text-rose-800 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20' => $isFailed,
                            'bg-zinc-50 text-zinc-700 ring-zinc-200 dark:bg-zinc-800/60 dark:text-zinc-300 dark:ring-zinc-700' => ! $isSuccess && ! $isFailed,
                        ])>
                            <span class="mr-2 text-[8px] font-black uppercase tracking-widest opacity-60">{{ __('USSD') }}</span>
                            {{ $transaction->status_desc }}
                        </div>
                    @endif


                    @if ($canRetry)
                        <div class="mt-2 flex justify-end">
                            <flux:button
                                type="button"
                                variant="ghost"
                                x-on:click.stop
                                wire:click="retryTransaction({{ $transaction->id }})"
                                wire:loading.attr="disabled"
                                wire:target="retryTransaction({{ $transaction->id }})"
                                class="app-secondary-button !h-6 px-3 text-[9px] font-bold uppercase tracking-widest"
                            >
                                <span wire:loading.remove wire:target="retryTransaction({{ $transaction->id }})">
                                    {{ __('Retry') }}
                                </span>
                                <span wire:loading wire:target="retryTransaction({{ $transaction->id }})" class="inline-flex items-center gap-1.5">
                                    <flux:icon.loading variant="mini" class="size-3" />
                                    {{ __('Retrying…') }}
                                </span>
                            </flux:button>
                        </div>
                    @endif
                </article>
            @endforeach


            <div class="mt-4 px-2">
                {{ $transactions->links() }}
            </div>
        </div>
    @endif

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
            <div x-data="{ copied: false }" class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('Transaction #:id', ['id' => $selectedTransaction->id]) }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">
                        {{ $this->transactionProductLabel($selectedTransaction) }}
                    </flux:text>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl bg-zinc-50 p-4 ring-1 ring-zinc-200">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('Status') }}</div>
                        <div class="mt-1 text-sm font-bold text-zinc-900">{{ blank($selectedTransaction->status) ? __('Pending') : $selectedTransaction->status }}</div>
                    </div>
                    <div class="rounded-2xl bg-zinc-50 p-4 ring-1 ring-zinc-200">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('Amount') }}</div>
                        <div class="mt-1 text-sm font-bold text-zinc-900">Ksh {{ number_format((float) $selectedTransaction->amount) }}</div>
                    </div>
                    <div class="rounded-2xl bg-zinc-50 p-4 ring-1 ring-zinc-200">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('Sender') }}</div>
                        <div class="mt-1 text-sm font-bold text-zinc-900">{{ $selectedTransaction->sender_name ?: __('Unknown sender') }}</div>
                        <div class="mt-1 flex items-center gap-2">
                            <div class="text-xs text-zinc-500">{{ $selectedTransaction->sender_phone }}</div>
                            @if (filled($selectedTransaction->sender_phone))
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="!h-6 px-2 text-[10px] font-bold uppercase tracking-widest text-zinc-500"
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
                    <div class="rounded-2xl bg-zinc-50 p-4 ring-1 ring-zinc-200">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('M-PESA Code') }}</div>
                        <div class="mt-1 text-sm font-bold text-zinc-900">{{ $selectedTransaction->mpesa_code ?: '—' }}</div>
                    </div>
                    <div class="rounded-2xl bg-zinc-50 p-4 ring-1 ring-zinc-200 sm:col-span-2">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('USSD Code') }}</div>
                        <div class="mt-1 font-mono text-sm font-bold text-zinc-900 break-all">{{ $this->resolvedUssdCode($selectedTransaction) }}</div>
                        <div class="mt-1 text-xs text-zinc-500">{{ $selectedTransaction->offer?->ussd_mode ?: '—' }}</div>
                    </div>
                    <div class="rounded-2xl bg-zinc-50 p-4 ring-1 ring-zinc-200 sm:col-span-2">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('Matched App Product') }}</div>
                        <div class="mt-1 text-sm font-bold text-zinc-900">{{ $this->transactionProductLabel($selectedTransaction) }}</div>
                        <div class="mt-1 text-xs text-zinc-500">{{ $selectedTransaction->offer_type ?: '—' }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl bg-white p-4 ring-1 ring-zinc-200">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('Occurred At') }}</div>
                        <div class="mt-1 text-sm font-bold text-zinc-900">{{ AppTimezone::format($selectedTransaction->occurred_at) }}</div>
                    </div>
                    <div class="rounded-2xl bg-white p-4 ring-1 ring-zinc-200">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('Processed At') }}</div>
                        <div class="mt-1 text-sm font-bold text-zinc-900">{{ AppTimezone::format($selectedTransaction->processed_at) }}</div>
                    </div>
                    <div class="rounded-2xl bg-white p-4 ring-1 ring-zinc-200">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('Created At') }}</div>
                        <div class="mt-1 text-sm font-bold text-zinc-900">{{ AppTimezone::format($selectedTransaction->created_at) }}</div>
                    </div>
                    <div class="rounded-2xl bg-white p-4 ring-1 ring-zinc-200">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('Updated At') }}</div>
                        <div class="mt-1 text-sm font-bold text-zinc-900">{{ AppTimezone::format($selectedTransaction->updated_at) }}</div>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('USSD Result') }}</div>
                    <div class="rounded-2xl bg-zinc-50 p-4 text-sm leading-relaxed text-zinc-800 ring-1 ring-zinc-200">
                        {{ $selectedTransaction->status_desc ?: __('—') }}
                    </div>
                </div>

                @if ($selectedTransaction->balance)
                    <div class="space-y-3">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ __('Balance Payload') }}</div>
                        <pre class="overflow-x-auto rounded-2xl bg-zinc-950 p-4 text-[11px] leading-relaxed text-zinc-100 ring-1 ring-zinc-900">{{ $this->formatDetailValue($selectedTransaction->balance) }}</pre>
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

    <flux:modal name="confirm-delete" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Transactions') }}</flux:heading>
                <flux:text class="mt-2 text-sm text-zinc-500">
                    {{ __('Are you sure you want to delete the selected transactions? This action cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="danger"
                    wire:click="deleteSelectedTransactions"
                    x-on:click="$dispatch('modal-close', { name: 'confirm-delete' })"
                >
                    {{ __('Yes, Delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
