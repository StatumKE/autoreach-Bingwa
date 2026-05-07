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

<div class="flex flex-col gap-3 px-4 pb-24 pt-3 bg-app-bg text-zinc-900 min-h-screen" wire:init="loadTransactions" wire:poll.10s="loadTransactions">
    <style>
        @keyframes transactions-reveal {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .transactions-reveal { animation: transactions-reveal 300ms ease-out both; }
    </style>

    <div class="px-1">
        <div class="text-xl font-bold text-zinc-900">{{ __('Transactions') }}</div>
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

    @if (! $this->loaded)
        <div class="flex flex-col gap-4">
            @for ($i = 0; $i < 4; $i++)
            <div class="relative overflow-hidden rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200">
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
            @foreach ($this->transactions() as $transaction)
                @php
                    $status = strtolower((string) ($transaction->status ?? ''));
                    $isSuccess = in_array($status, ['completed', 'successful']);
                    $isFailed = $status === 'failed';
                    $delay = ($loop->index * 60) + 100;
                @endphp
                <article class="transactions-reveal group relative overflow-hidden rounded-xl bg-white p-3 shadow-sm ring-1 ring-zinc-200 transition hover:ring-green-500/30" style="animation-delay: {{ $delay }}ms">
                    <div class="flex items-center justify-between gap-4">
                            <div class="flex flex-col gap-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-bold text-zinc-900">#{{ $transaction->id }}</span>
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
                            
                            <div class="flex items-center gap-2 text-sm font-medium text-zinc-700">
                                @if ($transaction->sender_name || $transaction->sender_phone)
                                    <span>{{ $transaction->sender_name ?: $transaction->sender_phone }}</span>
                                    @if ($transaction->sender_phone && $transaction->sender_name)
                                        <span class="text-xs text-zinc-400">({{ $transaction->sender_phone }})</span>
                                    @endif
                                @else
                                    <span class="text-zinc-400 italic">{{ __('Unknown sender') }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-1">
                            <div @class([
                                'text-sm font-bold tracking-tight',
                                'text-green-600' => $isSuccess,
                                'text-zinc-900' => ! $isSuccess,
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
                                class="app-secondary-button h-8 px-4 text-[10px] font-bold uppercase tracking-widest"
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
