<?php

use App\Support\AppTimezone;
use App\Models\Transaction;
use App\Support\KenyanPhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('SMS History')] class extends Component {
    use WithPagination;

    public bool $loaded = true;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $filter = 'all';

    /**
     * Load the history after the initial shell renders.
     */
    public function loadPage(): void
    {
        $this->loaded = true;
    }

    /**
     * Get the outbound auto-reply SMS history for the current user.
     */
    #[Computed]
    public function smsMessages()
    {
        return Transaction::query()
            ->with(['autoReply:id,name,trigger_condition'])
            ->select([
                'id',
                'user_id',
                'transaction_id',
                'sender_phone',
                'sender_name',
                'auto_reply_id',
                'auto_reply_message',
                'auto_reply_failure_reason',
                'auto_reply_status',
                'auto_reply_trigger_condition',
                'auto_reply_sim_slot',
                'auto_reply_attempts',
                'auto_reply_sent_at',
                'processed_at',
            ])
            ->where('user_id', Auth::id())
            ->whereNotNull('auto_reply_status')
            ->when($this->filter !== 'all', function ($query): void {
                $query->where('auto_reply_status', $this->filter);
            })
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($subQuery): void {
                    $subQuery->where('sender_phone', 'like', '%'.$this->search.'%')
                        ->orWhere('sender_name', 'like', '%'.$this->search.'%')
                        ->orWhere('transaction_id', 'like', '%'.$this->search.'%')
                        ->orWhere('auto_reply_message', 'like', '%'.$this->search.'%')
                        ->orWhere('auto_reply_failure_reason', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('auto_reply_sent_at')
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->paginate(10);
    }

    /**
     * Human-friendly label for a reply status.
     */
    public function statusLabel(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'queued' => __('Queued'),
            'sending' => __('Sending'),
            'sent' => __('Sent'),
            'failed' => __('Failed'),
            'skipped' => __('Skipped'),
            default => __('Unknown'),
        };
    }

    /**
     * CSS classes for a reply status badge.
     */
    public function statusClasses(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'queued' => 'bg-amber-50 text-amber-700 ring-amber-100',
            'sending' => 'bg-sky-50 text-sky-700 ring-sky-100',
            'sent' => 'bg-green-50 dark:bg-green-950/20 text-green-700 dark:text-green-400 dark:text-green-455 ring-green-100 dark:ring-green-900/50',
            'failed' => 'bg-rose-50 text-rose-700 dark:text-rose-455 ring-rose-100',
            'skipped' => 'bg-zinc-100 dark:bg-zinc-900 text-zinc-600 dark:text-zinc-400 dark:text-zinc-500 ring-zinc-200 dark:ring-zinc-800',
            default => 'bg-zinc-100 dark:bg-zinc-900 text-zinc-600 dark:text-zinc-400 dark:text-zinc-500 ring-zinc-200 dark:ring-zinc-800',
        };
    }

    /**
     * Human-friendly label for a trigger condition.
     */
    public function triggerLabel(?string $condition): string
    {
        return match (strtolower((string) $condition)) {
            'successful_transaction' => __('Successful Response'),
            'failed_transaction' => __('Failed Request'),
            'offer_unavailable' => __('Unavailable Offer'),
            'already_recommended' => __('Already Recommended'),
            'app_paused' => __('App Paused'),
            'blacklisted_customer' => __('Blacklisted Customer'),
            default => __('Unknown Condition'),
        };
    }

    /**
     * CSS classes for a trigger chip.
     */
    public function triggerClasses(?string $condition): string
    {
        return match (strtolower((string) $condition)) {
            'successful_transaction' => 'bg-green-50 dark:bg-green-950/20 text-green-700 dark:text-green-400 dark:text-green-455 ring-green-100 dark:ring-green-900/50',
            'failed_transaction' => 'bg-rose-50 text-rose-700 dark:text-rose-455 ring-rose-100',
            'offer_unavailable' => 'bg-amber-50 text-amber-700 ring-amber-100',
            'already_recommended' => 'bg-sky-50 text-sky-700 ring-sky-100',
            'app_paused' => 'bg-zinc-100 dark:bg-zinc-900 text-zinc-600 dark:text-zinc-400 dark:text-zinc-500 ring-zinc-200 dark:ring-zinc-800',
            'blacklisted_customer' => 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-100',
            default => 'bg-zinc-100 dark:bg-zinc-900 text-zinc-600 dark:text-zinc-400 dark:text-zinc-500 ring-zinc-200 dark:ring-zinc-800',
        };
    }

    /**
     * Preview the SMS body for compact display.
     */
    public function messagePreview(?string $message): string
    {
        return $message !== null && $message !== '' ? Str::limit($message, 140) : '—';
    }

    /**
     * Display the SIM used for sending.
     */
    public function simLabel(?string $simSlot): string
    {
        return match ($simSlot) {
            'slot_2' => __('Slot 2'),
            'slot_1' => __('Slot 1'),
            default => __('Unknown SIM'),
        };
    }

    /**
     * Normalize the buyer phone for display.
     */
    public function buyerPhone(Transaction $transaction): string
    {
        $phone = KenyanPhoneNumber::normalizeToLocal((string) $transaction->sender_phone);

        return $phone !== '' ? $phone : '—';
    }

    /**
     * Reset pagination when the search or filter changes.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when the status filter changes.
     */
    public function updatedFilter(): void
    {
        $this->resetPage();
    }
}; ?>

<section class="min-h-screen bg-app-bg px-4 pb-24 pt-3">
    @php
        $messages = $this->loaded ? $this->smsMessages : collect();
    @endphp

    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between gap-3 px-1">
            <div>
                <div class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ __('SMS History') }}</div>
                <div class="mt-1 text-xs font-medium text-zinc-500 dark:text-zinc-400 dark:text-zinc-500">
                    {{ __('Outbound auto-replies saved from completed transactions.') }}
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-3">
            <flux:input
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Search phone, message, or transaction…') }}"
                class="rounded-xl bg-white dark:bg-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:ring-zinc-800 dark:ring-zinc-800 text-zinc-900 dark:text-zinc-100"
            >
                <x-slot name="icon">
                    <flux:icon.magnifying-glass class="text-zinc-500 dark:text-zinc-400 dark:text-zinc-500" />
                </x-slot>
            </flux:input>

            <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
                @foreach ([
                    'all' => ['label' => __('All'), 'active' => 'bg-green-600 text-white shadow-sm ring-1 ring-inset ring-green-700/20', 'inactive' => 'bg-white text-zinc-500 dark:text-zinc-400 dark:text-zinc-500 ring-1 ring-inset ring-zinc-200 dark:ring-zinc-800 hover:bg-zinc-50 dark:bg-zinc-950/40 hover:text-zinc-900 dark:text-zinc-100'],
                    'queued' => ['label' => __('Queued'), 'active' => 'bg-amber-500 text-white shadow-sm ring-1 ring-inset ring-amber-600/20', 'inactive' => 'bg-white text-zinc-500 dark:text-zinc-400 dark:text-zinc-500 ring-1 ring-inset ring-zinc-200 dark:ring-zinc-800 hover:bg-zinc-50 dark:bg-zinc-950/40 hover:text-zinc-900 dark:text-zinc-100'],
                    'sending' => ['label' => __('Sending'), 'active' => 'bg-sky-500 text-white shadow-sm ring-1 ring-inset ring-sky-600/20', 'inactive' => 'bg-white text-zinc-500 dark:text-zinc-400 dark:text-zinc-500 ring-1 ring-inset ring-zinc-200 dark:ring-zinc-800 hover:bg-zinc-50 dark:bg-zinc-950/40 hover:text-zinc-900 dark:text-zinc-100'],
                    'sent' => ['label' => __('Sent'), 'active' => 'bg-green-600 text-white shadow-sm ring-1 ring-inset ring-green-700/20', 'inactive' => 'bg-white text-zinc-500 dark:text-zinc-400 dark:text-zinc-500 ring-1 ring-inset ring-zinc-200 dark:ring-zinc-800 hover:bg-zinc-50 dark:bg-zinc-950/40 hover:text-zinc-900 dark:text-zinc-100'],
                    'failed' => ['label' => __('Failed'), 'active' => 'bg-rose-600 text-white shadow-sm ring-1 ring-inset ring-rose-700/20', 'inactive' => 'bg-white text-zinc-500 dark:text-zinc-400 dark:text-zinc-500 ring-1 ring-inset ring-zinc-200 dark:ring-zinc-800 hover:bg-zinc-50 dark:bg-zinc-950/40 hover:text-zinc-900 dark:text-zinc-100'],
                    'skipped' => ['label' => __('Skipped'), 'active' => 'bg-zinc-700 text-white shadow-sm ring-1 ring-inset ring-zinc-800/20', 'inactive' => 'bg-white text-zinc-500 dark:text-zinc-400 dark:text-zinc-500 ring-1 ring-inset ring-zinc-200 dark:ring-zinc-800 hover:bg-zinc-50 dark:bg-zinc-950/40 hover:text-zinc-900 dark:text-zinc-100'],
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
            <div class="grid gap-3">
                @for ($i = 0; $i < 4; $i++)
                    <article class="rounded-2xl bg-white dark:bg-zinc-900 p-4 shadow-sm ring-1 ring-zinc-200 dark:ring-zinc-800 dark:ring-zinc-800">
                        <div class="h-4 w-32 animate-pulse rounded bg-zinc-100 dark:bg-zinc-900"></div>
                        <div class="mt-3 h-3 w-24 animate-pulse rounded bg-zinc-100 dark:bg-zinc-900"></div>
                        <div class="mt-4 h-20 w-full animate-pulse rounded bg-zinc-100 dark:bg-zinc-900/70"></div>
                    </article>
                @endfor
            </div>
        @elseif ($messages->total() === 0)
            <div class="rounded-2xl bg-white dark:bg-zinc-900 p-5 text-sm text-zinc-600 dark:text-zinc-400 dark:text-zinc-500 shadow-sm ring-1 ring-zinc-200 dark:ring-zinc-800">
                <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('No SMS history yet.') }}</div>
                <div class="mt-1">
                    {{ __('Completed or failed transactions with active auto-replies will appear here.') }}
                </div>
            </div>
        @else
            <div class="grid gap-3">
                @foreach ($messages as $message)
                    <article class="rounded-2xl bg-white dark:bg-zinc-900 p-4 shadow-sm ring-1 ring-zinc-200 dark:ring-zinc-800 dark:ring-zinc-800">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ $message->sender_name ?: $this->buyerPhone($message) }}
                                </div>
                                <div class="mt-1 text-[11px] font-medium text-zinc-500 dark:text-zinc-400 dark:text-zinc-500">
                                    {{ $message->transaction_id }}
                                </div>
                            </div>

                            <div class="flex shrink-0 flex-col items-end gap-2">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-widest ring-1 ring-inset',
                                    $this->statusClasses($message->auto_reply_status),
                                ])>
                                    {{ $this->statusLabel($message->auto_reply_status) }}
                                </span>

                                <span @class([
                                    'inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-widest ring-1 ring-inset',
                                    $this->triggerClasses($message->auto_reply_trigger_condition),
                                ])>
                                    {{ $this->triggerLabel($message->auto_reply_trigger_condition) }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                            <div class="rounded-xl bg-zinc-50 dark:bg-zinc-950/40 p-3">
                                <div class="text-[10px] font-black uppercase tracking-widest text-zinc-500 dark:text-zinc-400 dark:text-zinc-500">{{ __('Message') }}</div>
                                <div class="mt-1 leading-relaxed text-zinc-900 dark:text-zinc-100">
                                    {{ $this->messagePreview($message->auto_reply_message) }}
                                </div>
                            </div>

                            <div class="grid gap-2 text-[11px] font-medium text-zinc-500 dark:text-zinc-400 dark:text-zinc-500 sm:grid-cols-2">
                                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-950/40 p-3">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 dark:text-zinc-500">{{ __('Buyer Phone') }}</div>
                                    <div class="mt-1 text-zinc-900 dark:text-zinc-100">{{ $this->buyerPhone($message) }}</div>
                                </div>

                                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-950/40 p-3">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 dark:text-zinc-500">{{ __('SIM Used') }}</div>
                                    <div class="mt-1 text-zinc-900 dark:text-zinc-100">{{ $this->simLabel($message->auto_reply_sim_slot) }}</div>
                                </div>

                                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-950/40 p-3">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 dark:text-zinc-500">{{ __('Attempts') }}</div>
                                    <div class="mt-1 text-zinc-900 dark:text-zinc-100">{{ number_format((int) $message->auto_reply_attempts) }}</div>
                                </div>

                                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-950/40 p-3">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 dark:text-zinc-500">{{ __('Sent At') }}</div>
                                    <div class="mt-1 text-zinc-900 dark:text-zinc-100">
                                        {{ AppTimezone::format($message->auto_reply_sent_at, 'M j, Y g:i A') }}
                                    </div>
                                </div>
                            </div>

                            @if (filled($message->auto_reply_failure_reason))
                                <div class="rounded-xl bg-rose-50 p-3 text-rose-700 dark:text-rose-455 ring-1 ring-rose-100">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-rose-500">{{ __('Failure Reason') }}</div>
                                    <div class="mt-1 leading-relaxed">
                                        {{ $message->auto_reply_failure_reason }}
                                    </div>
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="pt-2">
                {{ $messages->links() }}
            </div>
        @endif
    </div>
</section>
