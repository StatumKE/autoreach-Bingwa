<?php

use App\Support\AppTimezone;
use App\Models\AutoRenewal;
use App\Models\Offer;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Auto Renewals')] class extends Component {
    use WithPagination;

    public bool $loaded = true;

    public string $customerPhone = '';

    public ?int $offerId = null;

    public string $scheduledDate = '';

    public string $scheduledTime = '';

    public bool $autoRenew = true;

    public string $renewDays = '1';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    /**
     * Boot the form with a sensible default schedule.
     */
    public function mount(): void
    {
        $this->scheduledDate = AppTimezone::now()->format('Y-m-d');
        $this->scheduledTime = AppTimezone::now()->addMinutes(15)->format('H:i');
    }

    /**
     * Load the page data after the initial shell renders.
     */
    public function loadPage(): void
    {
        $this->loaded = true;
        $this->offerId = $this->activeOffers->first()?->id;
    }

    /**
     * Persist a new auto-renewal schedule.
     */
    public function scheduleRenewal(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;

        if ($this->activeOffers->isEmpty()) {
            $this->errorMessage = __('Create at least one active offer before scheduling renewals.');

            return;
        }

        $validated = $this->validate($this->rules());
        $scheduledFor = Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['scheduledDate'].' '.$validated['scheduledTime'],
            AppTimezone::name()
        );

        if ($scheduledFor === false || $scheduledFor->isPast()) {
            throw ValidationException::withMessages([
                'scheduledDate' => __('Choose a future date and time.'),
            ]);
        }

        $offer = $this->ownedOffer((int) $validated['offerId']);

        AutoRenewal::query()->create([
            'user_id' => Auth::id(),
            'offer_id' => $offer->id,
            'customer_phone' => $this->normalizePhone($validated['customerPhone']),
            'scheduled_for' => $scheduledFor,
            'auto_renew' => (bool) $validated['autoRenew'],
            'renew_days' => (int) $validated['renewDays'],
            'status' => 'scheduled',
            'notes' => null,
        ]);

        $this->resetForm();
        $this->successMessage = __('Auto-renewal scheduled for :offer.', ['offer' => $offer->name]);
        $this->dispatch('modal-close', name: 'renewal-form');

        Flux::toast(variant: 'success', text: __('Auto-renewal scheduled.'));
    }

    /**
     * Explain why scheduling cannot start yet.
     */
    public function explainSchedulingRequirement(): void
    {
        $this->successMessage = null;
        $this->errorMessage = __('Create and activate at least one offer before scheduling renewals.');
    }

    /**
     * Cancel a scheduled auto-renewal.
     */
    public function cancelAutoRenewal(int $autoRenewalId): void
    {
        $this->errorMessage = null;
        $renewal = $this->ownedAutoRenewal($autoRenewalId);

        if ($renewal->status !== 'scheduled') {
            $this->errorMessage = __('Only scheduled renewals can be cancelled.');

            return;
        }

        $renewal->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->resetPage();
        $this->successMessage = __('Auto-renewal cancelled.');

        Flux::toast(variant: 'success', text: __('Auto-renewal cancelled.'));
    }

    /**
     * Get the active offers available for scheduling.
     */
    #[Computed]
    public function activeOffers()
    {
        return Offer::query()
            ->where('user_id', Auth::id())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the scheduled auto-renewals for the current user.
     */
    #[Computed]
    public function autoRenewals()
    {
        return AutoRenewal::query()
            ->with(['offer:id,name,price'])
            ->select([
                'id',
                'user_id',
                'offer_id',
                'customer_phone',
                'scheduled_for',
                'status',
                'auto_renew',
                'renew_days',
                'notes',
                'cancelled_at',
                'processing_started_at',
                'completed_at',
                'failed_at',
                'created_at',
                'updated_at',
            ])
            ->where('user_id', Auth::id())
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->paginate(8);
    }

    /**
     * Human-friendly label for a renewal status.
     */
    public function statusLabel(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'processing' => __('Processing'),
            'completed' => __('Completed'),
            'failed' => __('Failed'),
            'cancelled' => __('Cancelled'),
            default => __('Scheduled'),
        };
    }

    /**
     * CSS classes for a renewal status badge.
     */
    public function statusClasses(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'processing' => 'bg-amber-50 text-amber-700 ring-amber-100',
            'completed' => 'bg-green-50 text-green-700 ring-green-100',
            'failed' => 'bg-rose-50 text-rose-700 ring-rose-100',
            'cancelled' => 'bg-zinc-100 text-zinc-600 ring-zinc-200',
            default => 'bg-sky-50 text-sky-700 ring-sky-100',
        };
    }

    /**
     * Describe the recurrence rule in plain language.
     */
    public function recurrenceLabel(AutoRenewal $renewal): string
    {
        if (! $renewal->auto_renew) {
            return __('One-time schedule');
        }

        return __('Renew daily for the next :days day(s)', ['days' => $renewal->renew_days]);
    }

    /**
     * Reset the form fields after saving.
     */
    private function resetForm(): void
    {
        $this->customerPhone = '';
        $this->scheduledDate = AppTimezone::now()->format('Y-m-d');
        $this->scheduledTime = AppTimezone::now()->addMinutes(15)->format('H:i');
        $this->autoRenew = true;
        $this->renewDays = '1';
        $this->offerId = $this->activeOffers->first()?->id;
    }

    /**
     * Build the validation rules for the schedule form.
     *
     * @return array<string, array<int, Rule|array<mixed>|string>>
     */
    private function rules(): array
    {
        return [
            'customerPhone' => ['required', 'string', 'regex:/^(?:0|\+?254)\d{9}$/'],
            'offerId' => [
                'required',
                'integer',
                Rule::exists('offers', 'id')->where(function ($query): void {
                    $query->where('user_id', Auth::id())
                        ->where('is_active', true);
                }),
            ],
            'scheduledDate' => ['required', 'date_format:Y-m-d'],
            'scheduledTime' => ['required', 'date_format:H:i'],
            'autoRenew' => ['boolean'],
            'renewDays' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * Find an offer belonging to the current user.
     */
    private function ownedOffer(int $offerId): Offer
    {
        return Offer::query()
            ->where('user_id', Auth::id())
            ->where('is_active', true)
            ->findOrFail($offerId);
    }

    /**
     * Find an auto-renewal belonging to the current user.
     */
    private function ownedAutoRenewal(int $autoRenewalId): AutoRenewal
    {
        return AutoRenewal::query()
            ->where('user_id', Auth::id())
            ->findOrFail($autoRenewalId);
    }

    /**
     * Normalize a Kenyan phone number to a local 07XXXXXXXX format when possible.
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s\-\(\)]/', '', trim($phone)) ?? '';
        $phone = ltrim($phone, '+');

        if (preg_match('/^254\d{9}$/', $phone) === 1) {
            return '0'.substr($phone, 3);
        }

        return $phone;
    }
}; ?>

<section class="min-h-screen bg-app-bg px-4 pb-24 pt-3">
    @php
        $activeOffers = $this->loaded ? $this->activeOffers : collect();
        $renewals = $this->loaded ? $this->autoRenewals : collect();
    @endphp

    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between px-1">
            <div class="text-xl font-bold text-zinc-900">{{ __('Auto Renewals') }}</div>
            @if ($activeOffers->isEmpty())
                <button
                    type="button"
                    wire:click="explainSchedulingRequirement"
                    class="app-secondary-button inline-flex h-9 shrink-0 items-center gap-2 px-4 text-[10px] font-bold uppercase tracking-widest"
                >
                    <flux:icon.plus class="size-3.5" />
                    {{ __('Create') }}
                </button>
            @else
                <flux:modal.trigger name="renewal-form">
                    <button
                        type="button"
                        class="app-primary-button inline-flex h-9 shrink-0 items-center gap-2 px-4 text-[10px] font-bold uppercase tracking-widest"
                    >
                        <flux:icon.plus class="size-3.5" />
                        {{ __('Create') }}
                    </button>
                </flux:modal.trigger>
            @endif
        </div>

        @if ($this->successMessage)
            <div class="rounded-xl bg-green-50 px-4 py-3 text-sm font-medium text-green-700 ring-1 ring-green-100 shadow-sm">
                {{ $this->successMessage }}
            </div>
        @endif

        @if ($this->errorMessage)
            <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 ring-1 ring-rose-100 shadow-sm">
                {{ $this->errorMessage }}
            </div>
        @endif

        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-zinc-200">
            <div class="flex items-center justify-between gap-4 border-b border-zinc-200 px-4 py-3">
                <div class="text-sm font-bold text-zinc-900">{{ __('Scheduled Awards') }}</div>
                @if ($this->loaded)
                    <div class="rounded-full bg-green-50 px-3 py-1 text-xs font-bold text-green-700 ring-1 ring-green-100">
                        {{ $renewals->total() }}
                    </div>
                @else
                    <div class="h-6 w-12 animate-pulse rounded-full bg-zinc-100"></div>
                @endif
            </div>

            @if (! $this->loaded)
                <div class="space-y-4 px-4 py-6">
                    @for ($i = 0; $i < 3; $i++)
                        <div class="rounded-2xl bg-zinc-50 p-4 ring-1 ring-zinc-200">
                            <div class="h-4 w-28 animate-pulse rounded bg-zinc-100"></div>
                            <div class="mt-3 h-4 w-40 animate-pulse rounded bg-zinc-100"></div>
                            <div class="mt-3 h-12 w-full animate-pulse rounded bg-zinc-100/70"></div>
                        </div>
                    @endfor
                </div>
            @elseif ($renewals->isEmpty())
                <div class="px-4 py-10 text-center">
                    <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-green-50 text-green-600 ring-1 ring-green-100 shadow-inner">
                        <flux:icon.calendar-days class="size-6" />
                    </div>
                    <div class="mt-3 text-base font-bold text-zinc-900">
                        {{ __('No scheduled renewals yet') }}
                    </div>
                    <div class="mx-auto mt-1 max-w-sm text-sm text-zinc-500">
                        {{ __('Scheduled awards will appear here.') }}
                    </div>
                </div>
            @else
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200">
                        <thead class="bg-zinc-50/70">
                            <tr>
                                <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-widest text-zinc-500">{{ __('Customer') }}</th>
                                <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-widest text-zinc-500">{{ __('Offer') }}</th>
                                <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-widest text-zinc-500">{{ __('Scheduled For') }}</th>
                                <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-widest text-zinc-500">{{ __('Renewal') }}</th>
                                <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-widest text-zinc-500">{{ __('Status') }}</th>
                                <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-zinc-500">{{ __('Action') }}</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-100">
                            @foreach ($renewals as $renewal)
                                <tr class="transition hover:bg-zinc-50/80">
                                    <td class="px-5 py-4 align-top">
                                        <div class="text-sm font-black text-zinc-950">{{ $renewal->customer_phone }}</div>
                                        <div class="mt-1 text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ $renewal->id }}</div>
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        <div class="text-sm font-black text-zinc-950">{{ $renewal->offer?->name ?? __('Unknown offer') }}</div>
                                        <div class="mt-1 text-[10px] font-black uppercase tracking-widest text-zinc-400">
                                            Ksh {{ number_format((float) ($renewal->offer?->price ?? 0)) }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        <div class="text-sm font-black text-zinc-950">
                                        {{ AppTimezone::format($renewal->scheduled_for, 'D d/m/Y') }} {{ __('at') }} {{ AppTimezone::format($renewal->scheduled_for, 'H:i') }} Hrs
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        <div class="text-sm font-black text-zinc-950">
                                            {{ $this->recurrenceLabel($renewal) }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-widest ring-1',
                                            $this->statusClasses($renewal->status),
                                        ])>
                                            {{ $this->statusLabel($renewal->status) }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 align-top text-right">
                                        <button
                                            type="button"
                                            wire:click="cancelAutoRenewal({{ $renewal->id }})"
                                            @disabled($renewal->status !== 'scheduled')
                                            class="app-secondary-button px-4 py-2 text-[10px] font-black uppercase tracking-widest disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {{ __('Cancel') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-zinc-100 md:hidden">
                    @foreach ($renewals as $renewal)
                        <article class="space-y-4 px-5 py-4 transition hover:bg-zinc-50/80">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="text-base font-black text-zinc-950">{{ $renewal->customer_phone }}</div>
                                    <div class="mt-1 text-[10px] font-black uppercase tracking-widest text-zinc-400">{{ $renewal->offer?->name ?? __('Unknown offer') }}</div>
                                </div>

                                <span @class([
                                    'inline-flex items-center rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-widest ring-1',
                                    $this->statusClasses($renewal->status),
                                ])>
                                    {{ $this->statusLabel($renewal->status) }}
                                </span>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-2xl bg-zinc-50 px-4 py-3 ring-1 ring-zinc-200">
                                    <div class="text-[8px] font-black uppercase tracking-[0.24em] text-zinc-400">{{ __('Scheduled For') }}</div>
                                    <div class="mt-1 text-sm font-black text-zinc-950">
                                        {{ AppTimezone::format($renewal->scheduled_for, 'D d/m/Y') }}
                                        <span class="block text-[10px] font-medium text-zinc-500">
                                            {{ __('at') }} {{ AppTimezone::format($renewal->scheduled_for, 'H:i') }} Hrs
                                        </span>
                                    </div>
                                </div>

                                <div class="rounded-2xl bg-zinc-50 px-4 py-3 ring-1 ring-zinc-200">
                                    <div class="text-[8px] font-black uppercase tracking-[0.24em] text-zinc-400">{{ __('Renewal') }}</div>
                                    <div class="mt-1 text-sm font-black text-zinc-950">
                                        {{ $this->recurrenceLabel($renewal) }}
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center justify-between gap-3 border-t border-zinc-100 pt-3">
                                <div class="text-sm font-black text-zinc-950">
                                    Ksh {{ number_format((float) ($renewal->offer?->price ?? 0)) }}
                                </div>

                                <button
                                    type="button"
                                    wire:click="cancelAutoRenewal({{ $renewal->id }})"
                                    @disabled($renewal->status !== 'scheduled')
                                    class="app-secondary-button px-4 py-2 text-[10px] font-black uppercase tracking-widest disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {{ __('Cancel') }}
                                </button>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            @if ($this->loaded && $renewals->hasPages())
                <div class="border-t border-zinc-200 px-5 py-4">
                    {{ $renewals->links() }}
                </div>
            @endif
        </div>

        <flux:modal name="renewal-form" focusable class="max-w-lg">
            <form wire:submit="scheduleRenewal" class="space-y-5 p-1">
                <div>
                    <flux:heading size="lg" class="text-zinc-950">{{ __('Create Auto Renewal') }}</flux:heading>
                    <flux:subheading class="mt-1">{{ __('Schedule a renewal for a customer offer.') }}</flux:subheading>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-500" for="customer-phone">
                        {{ __('Customer Phone') }}
                    </label>
                    <input
                        id="customer-phone"
                        wire:model="customerPhone"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        placeholder="07XXXXXXXX"
                        class="h-12 w-full rounded-2xl border border-zinc-300 bg-transparent px-4 text-base font-medium text-zinc-700 outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-500/10"
                    >
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-500" for="offer-id">
                        {{ __('Select Offer') }}
                    </label>
                    <select
                        id="offer-id"
                        wire:model="offerId"
                        class="h-12 w-full rounded-2xl border-0 bg-zinc-100 px-4 text-base font-medium text-zinc-800 outline-none ring-1 ring-transparent transition focus:ring-2 focus:ring-green-500/20"
                        @disabled($activeOffers->isEmpty())
                    >
                        @if ($activeOffers->isEmpty())
                            <option value="">{{ __('No active offers available') }}</option>
                        @else
                            <option value="">{{ __('Select Offer') }}</option>
                            @foreach ($activeOffers as $offer)
                                <option value="{{ $offer->id }}">
                                    {{ $offer->name }} - Ksh {{ number_format($offer->price) }}
                                </option>
                            @endforeach
                        @endif
                    </select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-500" for="scheduled-date">
                            {{ __('Date') }}
                        </label>
                        <input
                            id="scheduled-date"
                            wire:model="scheduledDate"
                            type="date"
                            class="h-12 w-full rounded-2xl border-0 bg-zinc-100 px-4 text-base font-medium text-zinc-800 outline-none ring-1 ring-transparent transition focus:ring-2 focus:ring-green-500/20"
                        >
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-500" for="scheduled-time">
                            {{ __('Time') }}
                        </label>
                        <input
                            id="scheduled-time"
                            wire:model="scheduledTime"
                            type="time"
                            class="h-12 w-full rounded-2xl border-0 bg-zinc-100 px-4 text-base font-medium text-zinc-800 outline-none ring-1 ring-transparent transition focus:ring-2 focus:ring-green-500/20"
                        >
                    </div>
                </div>

                <div class="flex items-center gap-3 rounded-2xl bg-white/70 px-1 py-2">
                    <label class="inline-flex items-center gap-3 text-lg font-medium text-zinc-800">
                        <input
                            wire:model="autoRenew"
                            type="checkbox"
                            class="size-7 rounded-md border-zinc-300 text-green-600 focus:ring-green-500"
                        >
                        <span>{{ __('Auto-Renew') }}</span>
                    </label>
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-base leading-tight text-zinc-800 sm:text-lg">
                    <span>{{ __('Renew daily for the next') }}</span>
                    <input
                        wire:model="renewDays"
                        type="number"
                        min="1"
                        max="365"
                        inputmode="numeric"
                        class="w-16 border-0 border-b-2 border-zinc-300 bg-transparent px-1 pb-1 text-center text-lg font-medium text-zinc-800 outline-none ring-0 placeholder:text-zinc-300 focus:border-green-500 focus:ring-0"
                    >
                    <span>{{ __('Day(s)') }}</span>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <flux:button type="button" variant="ghost" class="app-secondary-button" x-on:click="$dispatch('modal-close', { name: 'renewal-form' })">
                        {{ __('Cancel') }}
                    </flux:button>

                    <button
                        type="submit"
                        class="app-primary-button h-14 px-6 text-[10px] font-black uppercase tracking-widest disabled:cursor-not-allowed disabled:opacity-60 sm:min-w-40"
                        @disabled($activeOffers->isEmpty())
                        wire:loading.attr="disabled"
                        wire:target="scheduleRenewal"
                    >
                        <span wire:loading.remove wire:target="scheduleRenewal">{{ __('Schedule') }}</span>
                        <span wire:loading wire:target="scheduleRenewal" class="inline-flex items-center justify-center gap-2">
                            <flux:icon.loading variant="mini" class="size-4" />
                            {{ __('Scheduling…') }}
                        </span>
                    </button>
                </div>
            </form>
        </flux:modal>
    </div>
</section>
