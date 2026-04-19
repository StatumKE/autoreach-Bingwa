<?php

use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Transactions')] class extends Component
{
    use WithPagination;

    public bool $loaded = false;

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

<section class="w-full p-4 md:p-6" wire:init="loadTransactions">
    <style>
        @keyframes transactions-reveal {
            from {
                opacity: 0;
                transform: translateY(12px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .transactions-reveal {
            animation: transactions-reveal 420ms ease-out both;
        }
    </style>

    <div class="flex flex-col gap-4">
        <div class="relative overflow-hidden rounded-3xl border border-emerald-800 bg-gradient-to-br from-emerald-950 via-emerald-900 to-zinc-900 p-5 text-white shadow-lg dark:border-emerald-700 md:p-6">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -top-16 -right-10 h-40 w-40 rounded-full bg-emerald-400/15 blur-3xl motion-safe:animate-pulse"></div>
                <div class="absolute -bottom-16 -left-10 h-44 w-44 rounded-full bg-zinc-400/10 blur-3xl motion-safe:animate-pulse" style="animation-delay: 300ms;"></div>
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/30 to-transparent"></div>
            </div>

            <div class="relative flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400 shadow-[0_0_0_6px_rgba(16,185,129,0.15)] motion-safe:animate-pulse"></span>
                    <span class="text-[10px] font-bold uppercase tracking-[0.25em] text-emerald-300/60">{{ __('Live sync') }}</span>
                </div>

                <div class="flex items-start justify-between gap-3">
                    <div>
                        <flux:heading size="xl" class="text-white">{{ __('Transactions') }}</flux:heading>
                        <flux:text class="max-w-2xl text-emerald-100/80">
                            {{ __('Track USSD execution identifiers, sender details, and plan status updates.') }}
                        </flux:text>
                    </div>

                    <flux:button
                        type="button"
                        variant="ghost"
                        class="shrink-0 border border-white/15 bg-white/5 text-white hover:bg-white/10"
                        wire:click="loadTransactions"
                    >
                        {{ __('Refresh') }}
                    </flux:button>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/85 backdrop-blur-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span>{{ __('Syncing latest network events in background') }}</span>
                        @if (! $this->loaded)
                            <span class="inline-flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-emerald-300">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-300 motion-safe:animate-pulse"></span>
                                {{ __('Syncing') }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @if (! $this->loaded)
            <div class="flex flex-col gap-4">
                @for ($i = 0; $i < 4; $i++)
                    <div class="relative overflow-hidden rounded-3xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/40 to-transparent opacity-0 motion-safe:animate-[pulse_1.8s_ease-in-out_infinite] dark:via-white/5"></div>
                        <div class="relative h-4 w-28 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                        <div class="relative mt-4 h-6 w-40 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                        <div class="relative mt-3 h-4 w-full rounded bg-zinc-200 dark:bg-zinc-700"></div>
                        <div class="relative mt-2 h-4 w-2/3 rounded bg-zinc-200 dark:bg-zinc-700"></div>
                    </div>
                @endfor
            </div>
        @elseif ($this->errorMessage)
            <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 px-4 py-4 text-sm text-rose-50">
                {{ $this->errorMessage }}
            </div>
        @elseif ($this->transactions()->isEmpty())
            <div class="rounded-[28px] border border-dashed border-zinc-200 bg-white px-6 py-12 text-center shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-zinc-100 text-3xl dark:bg-zinc-800 text-emerald-600">
                    ₭
                </div>
                <div class="mt-5 text-lg font-semibold text-zinc-950 dark:text-zinc-50">
                    {{ __('No transactions found yet.') }}
                </div>
                <div class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Once payments arrive, the transaction history will appear here.') }}
                </div>
            </div>
        @else
            <div class="flex flex-col gap-4">
                @foreach ($this->transactions() as $transaction)
                    @php
                        $status = strtolower((string) ($transaction->status ?? ''));
                        $statusClasses = match ($status) {
                            'completed', 'successful' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
                            'failed' => 'bg-rose-500/10 text-rose-700 dark:text-rose-400',
                            default => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                        };
                        $delay = ($loop->index * 90) + 120;
                    @endphp

                    <article class="transactions-reveal rounded-3xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/50" style="animation-delay: {{ $delay }}ms">
                        <div class="flex items-start justify-between gap-4">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">{{ $transaction->transaction_id }}</flux:heading>

                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide',
                                        $statusClasses,
                                    ])>
                                        {{ $transaction->status ?? __('Unknown') }}
                                    </span>
                                </div>

                                @if (! empty($transaction->mpesa_code))
                                    <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        {{ __('M-Pesa :code', ['code' => $transaction->mpesa_code]) }}
                                    </div>
                                @endif
                            </div>

                            <div class="text-lg font-bold text-emerald-600 dark:text-emerald-400">
                                {{ __('Ksh :amount', ['amount' => number_format((float) ($transaction->amount ?? 0))]) }}
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 text-sm text-zinc-600 dark:text-zinc-400">
                            <div class="flex items-center justify-between gap-3">
                                <span>{{ __('Sender phone') }}</span>
                                <span class="font-medium text-zinc-950 dark:text-zinc-50">{{ $transaction->sender_phone ?? '—' }}</span>
                            </div>

                            @if (! empty($transaction->sender_name))
                                <div class="flex items-center justify-between gap-3">
                                    <span>{{ __('Sender name') }}</span>
                                    <span class="font-medium text-zinc-950 dark:text-zinc-50">{{ $transaction->sender_name }}</span>
                                </div>
                            @endif

                            <div class="flex items-center justify-between gap-3">
                                <span>{{ __('Offer name') }}</span>
                                <span class="font-medium text-zinc-950 dark:text-zinc-50">{{ $transaction->offer_name ?? '—' }}</span>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <span>{{ __('Offer type') }}</span>
                                <span class="font-medium text-zinc-950 dark:text-zinc-50">{{ ucfirst((string) ($transaction->offer_type ?? '')) }}</span>
                            </div>

                            @if (! empty($transaction->matched_offer))
                                <div class="flex items-center justify-between gap-3">
                                    <span>{{ __('Matched offer') }}</span>
                                    <span class="font-medium text-zinc-950 dark:text-zinc-50">{{ $this->matchedOfferSummary($transaction->matched_offer) }}</span>
                                </div>
                            @endif

                            @if (! empty($transaction->occurred_at))
                                <div class="flex items-center justify-between gap-3">
                                    <span>{{ __('Occurred at') }}</span>
                                    <span class="font-medium text-zinc-950 dark:text-zinc-50">{{ $transaction->occurred_at->format('M j, Y g:i A') }}</span>
                                </div>
                            @endif

                            @if (! empty($transaction->status_desc))
                                <div class="rounded-2xl bg-zinc-50 px-4 py-3 text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    {{ $transaction->status_desc }}
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach

                <div class="mt-4">
                    {{ $this->transactions()->links() }}
                </div>
            </div>
        @endif
        @endif
    </div>
</section>
