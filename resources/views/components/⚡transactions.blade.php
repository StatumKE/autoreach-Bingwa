<?php

use App\Models\Transaction;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Transactions')] class extends Component
{
    use WithPagination;

    public bool $loaded = false;

    public int $refreshKey = 0;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $filter = 'all';

    public ?string $errorMessage = null;

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

        $transaction->update([
            'status' => 'queued',
            'status_desc' => __('Retry requested from the Transactions page.'),
            'processed_at' => null,
        ]);

        $this->resetPage();

        Flux::toast(variant: 'success', text: __('Transaction queued for retry.'));
    }

    /**
     * Load the transactions after the page has rendered.
     */
    public function loadTransactions(): void
    {
        $this->loaded = true;
        $this->refreshKey++;
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
     * Determine whether the transaction can be retried from the UI.
     */
    public function canRetryTransaction(Transaction $transaction): bool
    {
        $status = strtolower(trim((string) $transaction->status));

        return in_array($status, ['failed', 'pending', ''], true);
    }
};
?>

<div class="flex flex-col gap-6 p-4 md:p-6 pb-24 bg-app-bg text-zinc-950 min-h-screen" wire:init="loadTransactions" wire:poll.10s="loadTransactions">
    <style>
        @keyframes transactions-reveal {
            from { opacity: 0; transform: translateY(12px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .transactions-reveal { animation: transactions-reveal 420ms ease-out both; }
    </style>

    <div class="flex flex-col pt-0">
        <flux:text class="app-kicker">{{ __('Ledger') }}</flux:text>
        <flux:heading size="xl" class="text-3xl font-black tracking-tight text-zinc-950">{{ __('Transactions') }}</flux:heading>
    </div>

    <!-- Search & Filters -->
    <div class="flex flex-col gap-4">
        <flux:input 
            wire:model.live.debounce.400ms="search" 
            placeholder="{{ __('Search phone, name, or M-PESA code…') }}" 
            class="rounded-[1.5rem] shadow-sm bg-white ring-1 ring-zinc-200 text-zinc-950"
        >
            <x-slot name="icon">
                <flux:icon.magnifying-glass class="text-zinc-500" />
            </x-slot>
        </flux:input>

        <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
            @foreach ([
                'all' => ['label' => __('All'), 'active' => 'bg-green-600 text-white ring-2 ring-green-700/20 shadow-[0_10px_20px_-12px_rgba(42,135,50,0.7)] scale-[1.03] -translate-y-px', 'inactive' => 'bg-green-50 text-green-700 ring-1 ring-green-100'],
                'success' => ['label' => __('Success'), 'active' => 'bg-green-600 text-white ring-2 ring-green-700/20 shadow-[0_10px_20px_-12px_rgba(42,135,50,0.7)] scale-[1.03] -translate-y-px', 'inactive' => 'bg-green-50 text-green-800 ring-1 ring-green-100'],
                'failed' => ['label' => __('Failed'), 'active' => 'bg-rose-600 text-white ring-2 ring-rose-700/20 shadow-[0_10px_20px_-12px_rgba(225,29,72,0.7)] scale-[1.03] -translate-y-px', 'inactive' => 'bg-rose-50 text-rose-700 ring-1 ring-rose-100'],
                'queued' => ['label' => __('Queued'), 'active' => 'bg-amber-500 text-white ring-2 ring-amber-600/25 shadow-[0_10px_20px_-12px_rgba(245,158,11,0.65)] scale-[1.03] -translate-y-px', 'inactive' => 'bg-amber-50 text-amber-800 ring-1 ring-amber-100'],
            ] as $value => $styles)
                <flux:button
                    type="button"
                    variant="ghost"
                    wire:click="$set('filter', '{{ $value }}')"
                    size="sm"
                    @class([
                        'shrink-0 rounded-full px-5 text-[10px] font-black uppercase tracking-widest transition',
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

    @if (! $this->loaded)
        <div class="flex flex-col gap-4">
            @for ($i = 0; $i < 4; $i++)
                <div class="relative overflow-hidden rounded-[1.5rem] bg-white p-6 shadow-sm ring-1 ring-zinc-200">
                    <div class="absolute inset-0 bg-gradient-to-r from-transparent via-green-500/5 to-transparent motion-safe:animate-[pulse_1.8s_ease-in-out_infinite]"></div>
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
    @elseif ($this->transactions()->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-[1.75rem] bg-white p-12 text-center shadow-sm ring-1 ring-zinc-200">
            <div class="flex h-16 w-16 items-center justify-center rounded-3xl bg-green-50 text-green-600 mb-6 shadow-inner">
                <flux:icon.banknotes class="size-8" />
            </div>
            <flux:heading size="lg" class="text-zinc-950 font-black tracking-tight">
                {{ __('Empty Ledger') }}
            </flux:heading>
            <flux:text class="mt-2 text-sm text-zinc-500 max-w-[200px] mx-auto">
                {{ __('Once payments arrive, the transaction history will appear here.') }}
            </flux:text>
        </div>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($this->transactions() as $transaction)
                @php
                    $status = strtolower((string) ($transaction->status ?? ''));
                    $isSuccess = in_array($status, ['completed', 'successful']);
                    $isFailed = $status === 'failed';
                    $delay = ($loop->index * 60) + 100;
                @endphp
                <article class="transactions-reveal group relative overflow-hidden rounded-2xl bg-white p-4 shadow-sm ring-1 ring-zinc-200 transition hover:ring-green-500/30 dark:bg-zinc-900 dark:ring-zinc-800" style="animation-delay: {{ $delay }}ms">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex flex-col gap-1.5">
                            <div class="flex items-center gap-2.5">
                                <flux:heading class="text-base font-black tracking-tight text-zinc-950 dark:text-white">#{{ $transaction->id }}</flux:heading>
                                <span @class([
                                    'inline-flex items-center rounded-lg px-2 py-0.5 text-[9px] font-black uppercase tracking-widest',
                                    'bg-green-500/10 text-green-600 dark:bg-green-500/20 dark:text-green-400' => $isSuccess,
                                    'bg-rose-500/10 text-rose-600 dark:bg-rose-500/20 dark:text-rose-400' => $isFailed,
                                    'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400' => strtolower($status) === 'queued',
                                    'bg-zinc-100 text-zinc-600 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-700' => ! $isSuccess && ! $isFailed && strtolower($status) !== 'queued',
                                ])>
                                    {{ blank($transaction->status) ? __('Pending') : $transaction->status }}
                                </span>
                            </div>
                            
                            <div class="flex items-center gap-2 text-sm font-bold text-zinc-700 dark:text-zinc-300">
                                @if ($transaction->sender_name || $transaction->sender_phone)
                                    <span>{{ $transaction->sender_name ?: $transaction->sender_phone }}</span>
                                    @if ($transaction->sender_phone && $transaction->sender_name)
                                        <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500">({{ $transaction->sender_phone }})</span>
                                    @endif
                                @else
                                    <span class="text-zinc-400 italic">{{ __('Unknown sender') }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-1">
                            <div @class([
                                'text-lg font-black tracking-tighter',
                                'text-green-600 dark:text-green-400' => $isSuccess,
                                'text-zinc-950 dark:text-white' => ! $isSuccess,
                            ])>
                                Ksh {{ number_format((float) ($transaction->amount ?? 0)) }}
                            </div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">
                                {{ $transaction->occurred_at?->format('H:i, M j') ?? '—' }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        @if (! empty($transaction->mpesa_code))
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-green-50 px-2 py-1 text-[10px] font-bold text-green-700 ring-1 ring-green-100 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20">
                                <span class="text-[8px] opacity-60 uppercase tracking-widest">{{ __('M-PESA') }}</span>
                                {{ $transaction->mpesa_code }}
                            </span>
                        @endif
                        

                        @if ($transaction->offer_name)
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-50 px-2 py-1 text-[10px] font-bold text-zinc-600 ring-1 ring-zinc-200 dark:bg-zinc-800/50 dark:text-zinc-400 dark:ring-zinc-700/50">
                                <span class="text-[8px] opacity-60 uppercase tracking-widest">{{ __('Product') }}</span>
                                <span class="truncate max-w-[120px]">{{ $transaction->offer_name }}</span>
                            </span>
                        @endif

                    </div>

                    @if (filled($transaction->status_desc))
                        <div @class([
                            'mt-3 rounded-xl px-3 py-2 text-xs font-semibold leading-relaxed ring-1',
                            'bg-green-50 text-green-800 ring-green-100 dark:bg-green-500/10 dark:text-green-300 dark:ring-green-500/20' => $isSuccess,
                            'bg-rose-50 text-rose-800 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20' => $isFailed,
                            'bg-zinc-50 text-zinc-700 ring-zinc-200 dark:bg-zinc-800/60 dark:text-zinc-300 dark:ring-zinc-700' => ! $isSuccess && ! $isFailed,
                        ])>
                            <span class="mr-2 text-[9px] font-black uppercase tracking-widest opacity-60">{{ __('USSD') }}</span>
                            {{ $transaction->status_desc }}
                        </div>
                    @endif


                    @if ($this->canRetryTransaction($transaction))
                        <div class="mt-3 flex justify-end">
                            <flux:button
                                type="button"
                                variant="ghost"
                                wire:click="retryTransaction({{ $transaction->id }})"
                                wire:loading.attr="disabled"
                                wire:target="retryTransaction({{ $transaction->id }})"
                                class="app-secondary-button px-3 py-1.5 h-auto text-[10px] font-black uppercase tracking-widest text-zinc-700 hover:text-green-700 dark:text-zinc-400 dark:hover:text-green-400"
                            >
                                <span wire:loading.remove wire:target="retryTransaction({{ $transaction->id }})">
                                    {{ __('Retry') }}
                                </span>
                                <span wire:loading wire:target="retryTransaction({{ $transaction->id }})" class="inline-flex items-center gap-2">
                                    <flux:icon.loading variant="mini" class="size-3" />
                                    {{ __('Retrying…') }}
                                </span>
                            </flux:button>
                        </div>
                    @endif
                </article>
            @endforeach

            <div class="mt-4 px-2">
                {{ $this->transactions()->links() }}
            </div>
        </div>
    @endif
</div>
