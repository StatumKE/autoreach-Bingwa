<?php

use App\Actions\QuickDial\QuickDialContacts;
use App\Models\Offer;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Quick Dial')] class extends Component {
    public string $customerPhone = '';

    public ?string $awardMessage = null;

    public ?string $awardError = null;

    public ?int $selectedOfferId = null;

    /**
     * Clear the manual customer details.
     */
    public function clearSelectedContact(): void
    {
        $this->customerPhone = '';
        $this->awardMessage = null;
        $this->awardError = null;
    }

    /**
     * Prepare a focused confirmation flow for the selected offer.
     */
    public function prepareAwardOffer(int $offerId): void
    {
        $this->awardMessage = null;
        $this->awardError = null;

        if (! $this->canAward) {
            $this->selectedOfferId = null;
            $this->awardError = __('Enter or select a valid customer phone number first.');

            return;
        }

        $offer = $this->activeOffers->firstWhere('id', $offerId);

        if (! $offer instanceof Offer) {
            $this->selectedOfferId = null;
            $this->awardError = __('The selected offer is not available.');

            return;
        }

        if (blank($offer->ussd_code)) {
            $this->selectedOfferId = null;
            $this->awardError = __('This offer has no USSD code configured.');

            return;
        }

        $this->selectedOfferId = $offer->id;
    }

    /**
     * Start a native USSD award for the selected configured offer.
     */
    public function awardOffer(int $offerId): void
    {
        $this->awardMessage = null;
        $this->awardError = null;

        $phone = $this->normalizedCustomerPhone;

        if (! $this->canAward) {
            $this->awardError = __('Enter or select a valid customer phone number first.');

            return;
        }

        $offer = $this->activeOffers->firstWhere('id', $offerId);

        if (! $offer instanceof Offer) {
            $this->awardError = __('The selected offer is not available.');

            return;
        }

        if (blank($offer->ussd_code)) {
            $this->awardError = __('This offer has no USSD code configured.');

            return;
        }

        $this->selectedOfferId = $offer->id;
        $code = str_replace('PN', $phone, $offer->ussd_code);
        $payload = Js::from([
            'method' => 'ExecuteUssd',
            'offerId' => $offer->id,
            'code' => $code,
            'mode' => $offer->ussd_mode,
            'simSlot' => $this->primaryTransactionSimSlot(),
            'phone' => $phone,
        ]);

        $this->js(<<<JS
            (async () => {
                const payload = {$payload};

                try {
                    const response = await fetch('/_native/api/call', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        },
                        body: JSON.stringify({
                            method: payload.method,
                            params: {
                                code: payload.code,
                                mode: payload.mode,
                                simSlot: payload.simSlot,
                                isSambaza: false
                            }
                        })
                    });

                    const result = await response.json();
                    const nativeData = result.data?.data ?? result.data ?? {};
                    const success = response.ok && result.status === 'success' && nativeData.success === true;
                    const message = nativeData.message || result.message || 'USSD request completed.';

                    @this.recordQuickDialResult(payload.offerId, success, message, payload.phone);
                } catch (error) {
                    @this.recordQuickDialResult(payload.offerId, false, error?.message || 'Unable to reach native USSD bridge.', payload.phone);
                }
            })();
        JS);
    }

    /**
     * Record the native USSD result locally for visibility in Transactions.
     */
    public function recordQuickDialResult(int $offerId, bool $success, string $message = '', ?string $customerPhone = null): void
    {
        $offer = $this->activeOffers->firstWhere('id', $offerId);

        if (! $offer instanceof Offer) {
            $this->selectedOfferId = null;
            $this->awardError = __('The selected offer is not available.');

            return;
        }

        $phone = $this->normalizePhone($customerPhone ?? $this->customerPhone);

        if (preg_match('/^0\d{9}$/', $phone) !== 1) {
            $this->selectedOfferId = null;
            $this->awardError = __('Enter or select a valid customer phone number first.');

            return;
        }

        Transaction::query()->create([
            'user_id' => Auth::id(),
            'offer_id' => $offer->id,
            'transaction_id' => 'QD-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)),
            'mpesa_code' => null,
            'sender_phone' => $phone,
            'sender_name' => null,
            'amount' => $offer->price,
            'offer_name' => $offer->name,
            'offer_type' => $offer->category,
            'matched_offer' => [
                'offer_local_id' => (string) $offer->id,
                'offer_name' => $offer->name,
                'offer_type' => $offer->category,
                'offer_amount' => $offer->price,
                'source' => 'quick_dial',
            ],
            'occurred_at' => now(),
            'status' => $success ? 'completed' : 'failed',
            'status_desc' => $message !== '' ? $message : ($success ? __('Quick Dial award completed.') : __('Quick Dial award failed.')),
            'processed_at' => now(),
        ]);

        $this->selectedOfferId = null;
        $this->awardMessage = $success ? __('Award sent through :offer.', ['offer' => $offer->name]) : null;
        $this->awardError = $success ? null : ($message !== '' ? $message : __('Quick Dial award failed.'));
    }

    #[Computed]
    public function activeOffers()
    {
        return Auth::user()
            ->offers()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('price')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function normalizedCustomerPhone(): string
    {
        return $this->normalizePhone($this->customerPhone);
    }

    #[Computed]
    public function canAward(): bool
    {
        $phone = $this->normalizedCustomerPhone;

        return preg_match('/^0\d{9}$/', $phone) === 1;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';

        if (str_starts_with($phone, '+254')) {
            return '0'.substr($phone, 4);
        }

        if (str_starts_with($phone, '254') && strlen($phone) === 12) {
            return '0'.substr($phone, 3);
        }

        return $phone;
    }

    private function primaryTransactionSimSlot(): int
    {
        return Auth::user()->deviceSetting?->primary_transaction_sim === 'slot_2' ? 1 : 0;
    }
}; ?>

<section class="min-h-screen bg-app-bg px-4 pb-24 pt-3">
    <div class="flex flex-col gap-3">
        <div class="px-1">
            <div class="text-xl font-bold text-zinc-900">{{ __('Quick Dial') }}</div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200">
            <div class="text-[10px] font-black uppercase tracking-widest text-green-600/70">
                {{ __('Customer information') }}
            </div>

            <div class="mt-4 flex flex-col sm:flex-row gap-3">
                <div class="relative flex-1 group">
                    <flux:icon.device-phone-mobile class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-zinc-500 group-focus-within:text-green-600 transition-colors" />
                    <input
                        wire:model.live.debounce.500ms="customerPhone"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        placeholder="07XXXXXXXX"
                        class="h-14 w-full rounded-2xl bg-zinc-50 pl-12 pr-4 text-base font-black text-zinc-950 outline-none ring-1 ring-zinc-200 transition focus:ring-2 focus:ring-green-500/50"
                    >
                </div>
            </div>

            @if ($this->normalizedCustomerPhone !== '')
                <div class="mt-4 flex items-center justify-between gap-3 rounded-2xl bg-zinc-50 px-5 py-4 text-sm ring-1 ring-zinc-200 shadow-inner">
                    <div class="min-w-0">
                        <div class="truncate font-black text-zinc-950">
                            {{ __('Manual entry') }}
                        </div>
                        <div class="text-[10px] font-black text-green-600/70 uppercase tracking-widest mt-0.5">{{ $this->normalizedCustomerPhone }}</div>
                    </div>

                    <flux:button variant="ghost" size="sm" type="button" wire:click="clearSelectedContact" class="app-secondary-button text-zinc-700 hover:text-rose-500">
                        {{ __('Clear') }}
                    </flux:button>
                </div>
            @endif
        </div>

        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-zinc-200">
            <div class="border-b border-zinc-200 px-8 py-6">
                <div class="text-[10px] font-black uppercase tracking-widest text-green-600/70">
                    {{ __('Available offers') }}
                </div>
            </div>

            @if ($awardMessage)
                <div class="mx-8 mt-6 rounded-2xl bg-green-50 px-5 py-4 text-xs font-black text-green-700 ring-1 ring-green-100">
                    {{ $awardMessage }}
                </div>
            @endif

            @if ($awardError)
                <div class="mx-8 mt-6 rounded-2xl bg-rose-50 px-5 py-4 text-xs font-black text-rose-700 ring-1 ring-rose-100">
                    {{ $awardError }}
                </div>
            @endif

            @if ($this->activeOffers->isEmpty())
                <div class="px-8 py-16 text-center">
                    <div class="text-sm font-black text-zinc-600 uppercase tracking-widest italic">
                        {{ __('No active offers found.') }}
                    </div>
                </div>
            @else
                <div class="divide-y divide-zinc-200">
                    @foreach ($this->activeOffers as $offer)
                        <article class="flex items-center justify-between gap-4 px-8 py-6 transition-colors hover:bg-zinc-50/80">
                            <div class="min-w-0">
                                <div class="truncate text-base font-black text-zinc-950 tracking-tight">{{ $offer->name }}</div>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-xl bg-green-50 px-3 py-1.5 text-[9px] font-black uppercase tracking-widest text-green-700/70 ring-1 ring-green-100">
                                        {{ $offer->category }}
                                    </span>
                                    <span class="text-sm font-black text-green-700">
                                        KES {{ number_format($offer->price) }}
                                    </span>
                                </div>
                            </div>

                            <button
                                type="button"
                                @disabled(! $this->canAward)
                                wire:click="prepareAwardOffer({{ $offer->id }})"
                                @class([
                                    'h-12 rounded-[1.25rem] px-6 text-[10px] font-black uppercase tracking-widest transition active:scale-95',
                                    'bg-green-50 text-zinc-950 shadow-sm ring-1 ring-green-100 hover:bg-green-100' => $this->canAward,
                                    'bg-zinc-100 text-zinc-600 cursor-not-allowed ring-1 ring-zinc-300' => ! $this->canAward,
                                ])
                            >
                                {{ __('Award') }}
                            </button>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </div>



    @if ($selectedOfferId !== null)
        @php
            $selectedOffer = $this->activeOffers->firstWhere('id', $selectedOfferId);
        @endphp

        @if ($selectedOffer instanceof \App\Models\Offer)
            <div class="fixed inset-0 z-[70] flex items-end justify-center bg-zinc-950/45 p-4 backdrop-blur-sm sm:items-center sm:p-6">
                <button
                    type="button"
                    wire:click="$set('selectedOfferId', null)"
                    class="absolute inset-0 cursor-default"
                    aria-label="{{ __('Close quick dial confirmation') }}"
                ></button>

                <div class="relative z-10 w-full max-w-xl overflow-hidden rounded-[2rem] bg-white shadow-2xl ring-1 ring-zinc-200 plans-reveal">
                    <div class="bg-app-shell px-6 pb-5 pt-6 text-white">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="text-[10px] font-bold uppercase tracking-[0.24em] text-green-300/80">{{ __('Confirm Award') }}</div>
                                <div class="mt-2 text-2xl font-black tracking-tight text-white">{{ $selectedOffer->name }}</div>
                                <div class="mt-1 text-[11px] font-bold uppercase tracking-[0.2em] text-white/55">{{ $selectedOffer->category }}</div>
                            </div>

                            <button
                                type="button"
                                wire:click="$set('selectedOfferId', null)"
                                class="app-secondary-button flex size-11 shrink-0 items-center justify-center rounded-2xl border-0 bg-white/8 text-white ring-1 ring-white/10 hover:bg-white/14"
                                aria-label="{{ __('Close') }}"
                            >
                                <flux:icon.x-mark class="size-5" />
                            </button>
                        </div>
                    </div>

                    <div class="space-y-6 px-6 py-6">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-2xl bg-zinc-50 px-4 py-4 ring-1 ring-zinc-200">
                                <div class="text-[9px] font-bold uppercase tracking-[0.22em] text-zinc-500">{{ __('Customer') }}</div>
                                <div class="mt-2 text-lg font-black text-zinc-950">{{ __('Manual entry') }}</div>
                                <div class="mt-1 text-sm font-bold text-green-700">{{ $this->normalizedCustomerPhone }}</div>
                            </div>

                            <div class="rounded-2xl bg-zinc-50 px-4 py-4 ring-1 ring-zinc-200">
                                <div class="text-[9px] font-bold uppercase tracking-[0.22em] text-zinc-500">{{ __('Amount') }}</div>
                                <div class="mt-2 text-2xl font-black tracking-tight text-green-700">KES {{ number_format((float) $selectedOffer->price) }}</div>
                            </div>
                        </div>

                        <div class="rounded-[1.75rem] bg-zinc-50 p-4 ring-1 ring-zinc-200">
                            <div class="text-[10px] font-bold uppercase tracking-[0.24em] text-zinc-600">{{ __('Routing') }}</div>
                            <div class="mt-3 flex items-center justify-between">
                                <div>
                                    <div class="text-sm font-black text-zinc-950">{{ __('Primary Transaction SIM') }}</div>
                                    <div class="mt-1 text-xs font-bold uppercase tracking-[0.18em] text-green-700">
                                        {{ $this->primaryTransactionSimSlot() === 1 ? __('SIM 2') : __('SIM 1') }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row">
                            <button
                                type="button"
                                wire:click="$set('selectedOfferId', null)"
                                class="app-secondary-button flex h-12 w-full items-center justify-center text-[10px] font-bold uppercase tracking-widest sm:w-40"
                            >
                                {{ __('Cancel') }}
                            </button>

                            <button
                                type="button"
                                wire:click="awardOffer({{ $selectedOffer->id }})"
                                class="app-primary-button flex h-12 w-full items-center justify-center text-[10px] font-bold uppercase tracking-widest"
                                wire:loading.attr="disabled"
                                wire:target="awardOffer"
                            >
                                <span wire:loading.remove wire:target="awardOffer">{{ __('Award Now') }}</span>
                                <span wire:loading wire:target="awardOffer" class="inline-flex items-center justify-center gap-2">
                                    <flux:icon.loading variant="mini" class="size-4" />
                                    {{ __('Sending…') }}
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif
</section>
