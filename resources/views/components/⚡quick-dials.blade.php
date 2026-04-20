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

    public string $selectedName = '';

    /** @var array<int, array{name: string, phone: string, label: ?string}> */
    public array $contactResults = [];

    public ?string $contactMessage = null;

    public ?string $awardMessage = null;

    public ?string $awardError = null;

    public ?int $selectedOfferId = null;

    /**
     * Search the native Android contacts provider using the current input.
     */
    public function searchContacts(): void
    {
        $this->contactMessage = null;
        $this->contactResults = [];

        $contacts = app(QuickDialContacts::class);

        if (! $contacts->checkPermission()) {
            $contacts->requestPermission();
            $this->contactMessage = __('Contacts permission is required. Allow it, then tap Contacts again.');

            return;
        }

        $this->contactResults = $contacts->search($this->customerPhone, 12);

        if ($this->contactResults === []) {
            $this->contactMessage = __('No matching contacts found.');
        }
    }

    /**
     * Select a phone number from native contact search results.
     */
    public function selectContact(string $phone, string $name = ''): void
    {
        $this->customerPhone = $this->normalizePhone($phone);
        $this->selectedName = trim($name);
        $this->contactResults = [];
        $this->contactMessage = null;
    }

    /**
     * Clear the selected/manual customer details.
     */
    public function clearSelectedContact(): void
    {
        $this->customerPhone = '';
        $this->selectedName = '';
        $this->contactResults = [];
        $this->contactMessage = null;
        $this->awardMessage = null;
        $this->awardError = null;
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
            'name' => $this->selectedName,
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

                    @this.recordQuickDialResult(payload.offerId, success, message, payload.phone, payload.name);
                } catch (error) {
                    @this.recordQuickDialResult(payload.offerId, false, error?.message || 'Unable to reach native USSD bridge.', payload.phone, payload.name);
                }
            })();
        JS);
    }

    /**
     * Record the native USSD result locally for visibility in Transactions.
     */
    public function recordQuickDialResult(int $offerId, bool $success, string $message = '', ?string $customerPhone = null, ?string $customerName = null): void
    {
        $offer = $this->activeOffers->firstWhere('id', $offerId);

        if (! $offer instanceof Offer) {
            $this->selectedOfferId = null;
            $this->awardError = __('The selected offer is not available.');

            return;
        }

        $phone = $this->normalizePhone($customerPhone ?? $this->customerPhone);
        $name = trim($customerName ?? $this->selectedName);

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
            'sender_name' => $name !== '' ? $name : null,
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

<section class="w-full p-4 md:p-6 bg-slate-950 min-h-screen">
    <div class="flex flex-col gap-5">
        <div class="relative overflow-hidden rounded-[2.5rem] bg-slate-900 p-6 shadow-2xl ring-1 ring-slate-800 md:p-8">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -top-12 -right-12 h-48 w-48 rounded-full bg-teal-500/5 blur-3xl"></div>
                <div class="absolute -bottom-16 -left-12 h-44 w-44 rounded-full bg-indigo-500/5 blur-3xl"></div>
            </div>

            <div class="relative flex items-center justify-between gap-4">
                <div>
                    <span class="text-[10px] font-black uppercase tracking-[0.3em] text-teal-400/40">{{ __('Ad-Hoc Awards') }}</span>
                    <flux:heading size="xl" class="mt-1 text-white font-black tracking-tight text-3xl">{{ __('Quick Dial') }}</flux:heading>
                </div>

                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-950 text-indigo-400 shadow-inner ring-1 ring-slate-800">
                    <flux:icon.phone class="size-6" />
                </div>
            </div>
        </div>

        <div class="rounded-[2.5rem] bg-slate-900 p-6 shadow-2xl ring-1 ring-slate-800">
            <div class="text-[10px] font-black uppercase tracking-widest text-teal-400/40">
                {{ __('Customer information') }}
            </div>

            <div class="mt-4 flex flex-col sm:flex-row gap-3">
                <div class="relative flex-1 group">
                    <flux:icon.device-phone-mobile class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-500 group-focus-within:text-teal-400 transition-colors" />
                    <input
                        wire:model.live.debounce.500ms="customerPhone"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        placeholder="07XXXXXXXX"
                        class="h-14 w-full rounded-2xl bg-slate-950 pl-12 pr-4 text-base font-black text-white outline-none ring-1 ring-slate-800 transition focus:ring-2 focus:ring-teal-500/50"
                    >
                </div>

                <button type="button" class="h-14 rounded-2xl bg-slate-800 px-8 font-black uppercase tracking-widest text-white shadow-xl ring-1 ring-slate-700 transition-all active:scale-95 hover:bg-slate-700" wire:click="searchContacts">
                    {{ __('Contacts') }}
                </button>
            </div>

            @if ($this->normalizedCustomerPhone !== '')
                <div class="mt-4 flex items-center justify-between gap-3 rounded-2xl bg-slate-950 px-5 py-4 text-sm ring-1 ring-slate-800 shadow-inner">
                    <div class="min-w-0">
                        <div class="truncate font-black text-white">
                            {{ $this->selectedName !== '' ? $this->selectedName : __('Manual entry') }}
                        </div>
                        <div class="text-[10px] font-black text-teal-400/60 uppercase tracking-widest mt-0.5">{{ $this->normalizedCustomerPhone }}</div>
                    </div>

                    <flux:button variant="ghost" size="sm" type="button" wire:click="clearSelectedContact" class="text-slate-500 hover:text-rose-500">
                        {{ __('Clear') }}
                    </flux:button>
                </div>
            @endif

            @if ($contactMessage)
                <div class="mt-4 rounded-2xl bg-indigo-500/10 px-5 py-4 text-xs font-black text-indigo-400 ring-1 ring-indigo-500/20">
                    {{ $contactMessage }}
                </div>
            @endif

            @if ($contactResults !== [])
                <div class="mt-4 overflow-hidden rounded-[2rem] bg-slate-950 ring-1 ring-slate-800">
                    @foreach ($contactResults as $contact)
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-4 border-b border-slate-900 px-5 py-4 text-left last:border-b-0 hover:bg-slate-900 transition-colors"
                            wire:click="selectContact(@js($contact['phone'] ?? ''), @js($contact['name'] ?? ''))"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-black text-white">{{ $contact['name'] ?? $contact['phone'] ?? '' }}</span>
                                <span class="block text-[10px] font-black text-teal-400/50 uppercase tracking-widest mt-0.5">{{ $contact['phone'] ?? '' }}</span>
                            </span>

                            @if (! empty($contact['label']))
                                <span class="shrink-0 rounded-xl bg-slate-900 px-3 py-1 text-[8px] font-black uppercase tracking-[0.2em] text-slate-500 ring-1 ring-slate-800">
                                    {{ $contact['label'] }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="overflow-hidden rounded-[2.5rem] bg-slate-900 shadow-2xl ring-1 ring-slate-800">
            <div class="border-b border-slate-800/50 px-8 py-6">
                <div class="text-[10px] font-black uppercase tracking-widest text-teal-400/40">
                    {{ __('Available offers') }}
                </div>
            </div>

            @if ($awardMessage)
                <div class="mx-8 mt-6 rounded-2xl bg-teal-500/10 px-5 py-4 text-xs font-black text-teal-400 ring-1 ring-teal-500/20">
                    {{ $awardMessage }}
                </div>
            @endif

            @if ($awardError)
                <div class="mx-8 mt-6 rounded-2xl bg-rose-500/10 px-5 py-4 text-xs font-black text-rose-400 ring-1 ring-rose-500/20">
                    {{ $awardError }}
                </div>
            @endif

            @if ($this->activeOffers->isEmpty())
                <div class="px-8 py-16 text-center">
                    <div class="text-sm font-black text-slate-600 uppercase tracking-widest italic">
                        {{ __('No active offers found.') }}
                    </div>
                </div>
            @else
                <div class="divide-y divide-slate-800/50">
                    @foreach ($this->activeOffers as $offer)
                        <article class="flex items-center justify-between gap-4 px-8 py-6 transition-colors hover:bg-slate-950/50">
                            <div class="min-w-0">
                                <div class="truncate text-base font-black text-white tracking-tight">{{ $offer->name }}</div>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-xl bg-slate-950 px-3 py-1.5 text-[9px] font-black uppercase tracking-widest text-teal-400/40 ring-1 ring-slate-800">
                                        {{ $offer->category }}
                                    </span>
                                    <span class="text-sm font-black text-teal-400">
                                        KES {{ number_format($offer->price) }}
                                    </span>
                                </div>
                            </div>

                            <button
                                type="button"
                                @disabled(! $this->canAward || $selectedOfferId === $offer->id)
                                wire:click="awardOffer({{ $offer->id }})"
                                @class([
                                    'h-12 rounded-[1.25rem] px-6 text-[10px] font-black uppercase tracking-widest transition-all active:scale-95',
                                    'bg-indigo-600 text-white shadow-xl shadow-indigo-600/20' => $this->canAward && $selectedOfferId !== $offer->id,
                                    'bg-slate-800 text-slate-600 cursor-not-allowed ring-1 ring-slate-700' => ! $this->canAward || $selectedOfferId === $offer->id,
                                ])
                            >
                                {{ $selectedOfferId === $offer->id ? __('Sending...') : __('Award') }}
                            </button>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
