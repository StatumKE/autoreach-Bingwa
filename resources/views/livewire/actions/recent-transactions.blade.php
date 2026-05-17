<div class="divide-y divide-zinc-100 text-center">
    @forelse($this->transactions() as $tx)
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
