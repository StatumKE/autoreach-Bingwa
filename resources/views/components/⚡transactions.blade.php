<?php

use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Transactions')] class extends Component
{
    use WithPagination;

    public bool $loaded = false;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $filter = 'all';

    public ?string $errorMessage = null;

    /**
     * Load the transactions after the page has rendered.
     */
    public function loadTransactions(): void
    {
        $this->loaded = true;
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
};
?>

<div class="flex flex-col gap-6 p-4 md:p-6 pb-24 bg-zinc-50/50 dark:bg-zinc-950/50 min-h-screen">
    <style>
        @keyframes transactions-reveal {
            from { opacity: 0; transform: translateY(12px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .transactions-reveal { animation: transactions-reveal 420ms ease-out both; }
    </style>

    <div class="flex flex-col pt-2">
        <flux:text class="text-[10px] font-black uppercase tracking-[0.2em] text-zinc-500/80 dark:text-zinc-400/60">{{ __('Ledger') }}</flux:text>
        <flux:heading size="xl" class="text-3xl font-black tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('Transactions') }}</flux:heading>
    </div>

    <!-- Search & Filters -->
    <div class="flex flex-col gap-4">
        <flux:input 
            wire:model.live.debounce.400ms="search" 
            placeholder="{{ __('Search phone, name, or M-PESA code...') }}" 
            class="rounded-[2rem] shadow-sm bg-white dark:bg-zinc-900 ring-1 ring-zinc-200/50 dark:ring-zinc-800"
        >
            <x-slot name="icon">
                <flux:icon.magnifying-glass class="text-zinc-400" />
            </x-slot>
        </flux:input>

        <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
            @foreach (['all' => __('All'), 'success' => __('Success'), 'failed' => __('Failed'), 'queued' => __('Queued')] as $value => $label)
                <flux:button
                    type="button"
                    class="shrink-0 rounded-full px-5 text-[10px] font-black uppercase tracking-widest transition-all"
                    variant="{{ $this->filter === $value ? 'primary' : 'ghost' }}"
                    wire:click="$set('filter', '{{ $value }}')"
                    size="sm"
                >
                    {{ $label }}
                </flux:button>
            @endforeach
        </div>
    </div>

    @if (! $this->loaded)
        <div class="flex flex-col gap-4">
            @for ($i = 0; $i < 4; $i++)
                <div class="relative overflow-hidden rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-zinc-200/50 dark:bg-zinc-900 dark:ring-zinc-800">
                    <div class="absolute inset-0 bg-gradient-to-r from-transparent via-zinc-100/50 to-transparent motion-safe:animate-[pulse_1.8s_ease-in-out_infinite] dark:via-zinc-800/50"></div>
                    <div class="relative h-4 w-24 rounded bg-zinc-100 dark:bg-zinc-800"></div>
                    <div class="relative mt-4 h-6 w-40 rounded bg-zinc-100 dark:bg-zinc-800"></div>
                    <div class="relative mt-4 h-16 w-full rounded bg-zinc-50 dark:bg-zinc-800/50"></div>
                </div>
            @endfor
        </div>
    @elseif ($this->errorMessage)
        <div class="rounded-2xl bg-rose-500/10 p-4 text-sm text-rose-600 dark:text-rose-400 font-bold border border-rose-500/20">
            {{ $this->errorMessage }}
        </div>
    @elseif ($this->transactions()->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-[2.5rem] bg-white p-12 text-center shadow-sm ring-1 ring-zinc-200/50 dark:bg-zinc-900 dark:ring-zinc-800">
            <div class="flex h-16 w-16 items-center justify-center rounded-3xl bg-zinc-50 text-emerald-600 dark:bg-zinc-800 dark:text-emerald-400 mb-6">
                <flux:icon.banknotes class="size-8" />
            </div>
            <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100 font-black tracking-tight">
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
                <article class="transactions-reveal group relative overflow-hidden rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-zinc-200/50 transition-all hover:shadow-md dark:bg-zinc-900 dark:ring-zinc-800" style="animation-delay: {{ $delay }}ms">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 space-y-3">
                            <div class="flex items-center gap-3">
                                <flux:heading class="text-lg font-black tracking-tight text-zinc-900 dark:text-zinc-50">{{ $transaction->transaction_id }}</flux:heading>
                                <span @class([
                                    'inline-flex items-center rounded-lg px-2 py-0.5 text-[8px] font-black uppercase tracking-widest',
                                    'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400' => $isSuccess,
                                    'bg-rose-50 text-rose-600 dark:bg-rose-950/30 dark:text-rose-400' => $isFailed,
                                    'bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => ! $isSuccess && ! $isFailed,
                                ])>
                                    {{ $transaction->status ?? __('Pending') }}
                                </span>
                            </div>

                            @if (! empty($transaction->mpesa_code))
                                <div class="inline-flex items-center gap-2 rounded-xl bg-zinc-50 px-3 py-1.5 text-[10px] font-black text-zinc-600 border border-zinc-100 dark:bg-zinc-800/40 dark:text-zinc-400 dark:border-zinc-700/50">
                                    <span class="text-[7px] opacity-40 uppercase tracking-[0.2em]">{{ __('M-PESA') }}</span>
                                    {{ $transaction->mpesa_code }}
                                </div>
                            @endif
                        </div>

                        <div @class([
                            'text-xl font-black tracking-tighter',
                            'text-emerald-600 dark:text-emerald-400' => $isSuccess,
                            'text-zinc-900 dark:text-zinc-50' => ! $isSuccess,
                        ])>
                            Ksh {{ number_format((float) ($transaction->amount ?? 0)) }}
                        </div>
                    </div>

                    <div class="mt-6 space-y-4">
                        <div class="flex items-center justify-between gap-6 rounded-2xl bg-zinc-50/50 p-4 dark:bg-zinc-800/30 border border-zinc-100/50 dark:border-zinc-700/50">
                            <div class="flex flex-col">
                                <span class="text-[8px] font-black text-zinc-400 uppercase tracking-[0.2em]">{{ __('Counterparty') }}</span>
                                <div class="flex items-center gap-3 mt-1.5">
                                    <div class="h-8 w-8 rounded-xl bg-white shadow-sm ring-1 ring-zinc-200/50 dark:bg-zinc-800 dark:ring-zinc-700 flex items-center justify-center text-xs font-black text-zinc-500">
                                        {{ strtoupper(substr($transaction->sender_name ?: '?', 0, 1)) }}
                                    </div>
                                    <span class="text-sm font-black text-zinc-900 dark:text-zinc-100">{{ $transaction->sender_name ?: $transaction->sender_phone }}</span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end">
                                <span class="text-[8px] font-black text-zinc-400 uppercase tracking-[0.2em]">{{ __('Product') }}</span>
                                <span class="text-[11px] font-black text-zinc-600 dark:text-zinc-400 mt-1.5 truncate max-w-[140px] uppercase tracking-tighter">{{ $transaction->offer_name ?: '—' }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 px-2">
                            <div class="flex flex-col">
                                <span class="text-[8px] font-black text-zinc-400 uppercase tracking-[0.2em]">{{ __('Timeline') }}</span>
                                <span class="text-[10px] font-bold text-zinc-500 mt-1 uppercase">{{ $transaction->occurred_at?->format('H:i, M j') ?? '—' }}</span>
                            </div>
                            <div class="flex flex-col items-end">
                                <span class="text-[8px] font-black text-zinc-400 uppercase tracking-[0.2em]">{{ __('Category') }}</span>
                                <span class="text-[10px] font-black text-emerald-600 dark:text-emerald-400 mt-1 uppercase tracking-widest">{{ $transaction->offer_type ?: 'PAYMENT' }}</span>
                            </div>
                        </div>
                    </div>

                    @if (! empty($transaction->status_desc))
                        <div class="mt-5 rounded-2xl bg-rose-50/50 border border-rose-100/50 px-4 py-3 text-[10px] font-bold text-rose-600/80 dark:bg-rose-950/20 dark:border-rose-900/30 dark:text-rose-400/80 italic leading-relaxed">
                            “{{ $transaction->status_desc }}”
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
