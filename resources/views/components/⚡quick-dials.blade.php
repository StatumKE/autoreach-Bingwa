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
    private const CONTACT_SEARCH_LIMIT = 100;

    public string $customerPhone = '';

    public string $selectedName = '';

    public bool $showContactPicker = false;

    public string $contactSearch = '';

    /** @var array<int, array{name: string, phone: string, label: ?string}> */
    public array $contactResults = [];

    public ?string $contactMessage = null;

    public ?string $awardMessage = null;

    public ?string $awardError = null;

    public ?int $selectedOfferId = null;

    /**
     * Open the contact picker modal.
     */
    public function openContactPicker(): void
    {
        $this->showContactPicker = true;
        $this->contactSearch = trim($this->customerPhone);
        $this->contactMessage = null;
        $this->contactResults = [];

        if ($this->contactSearch !== '') {
            $this->searchContacts($this->contactSearch);
        } else {
            $this->contactMessage = __('Type a name or number to search contacts.');
        }
    }

    /**
     * Close the contact picker modal.
     */
    public function closeContactPicker(): void
    {
        $this->showContactPicker = false;
        $this->contactSearch = '';
        $this->contactResults = [];
        $this->contactMessage = null;
    }

    /**
     * Search the native Android contacts provider using the current input.
     */
    public function searchContacts(?string $query = null): void
    {
        $this->contactMessage = null;
        $this->contactResults = [];

        $query = $query ?? $this->contactSearch;

        if (blank($query)) {
            $query = $this->customerPhone;
        }

        $query = trim($query);
        $this->contactSearch = $query;

        if ($query === '') {
            $this->contactMessage = __('Type a name or number to search contacts.');

            return;
        }

        $contacts = app(QuickDialContacts::class);

        if (! $contacts->checkPermission()) {
            $contacts->requestPermission();
            $this->contactMessage = __('Contacts permission is required. Allow it, then tap Contacts again.');

            return;
        }

        $this->contactResults = $contacts->search($query, self::CONTACT_SEARCH_LIMIT);

        if ($this->contactResults === []) {
            $this->contactMessage = __('No matching contacts found.');
        }
    }

    /**
     * Keep the modal search responsive as the user types.
     */
    public function updatedContactSearch(): void
    {
        if (! $this->showContactPicker) {
            return;
        }

        if (trim($this->contactSearch) === '') {
            $this->contactResults = [];
            $this->contactMessage = __('Type a name or number to search contacts.');

            return;
        }

        $this->searchContacts($this->contactSearch);
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
        $this->closeContactPicker();
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

<section class="w-full p-4 md:p-6 bg-app-bg min-h-screen">
    <div class="flex flex-col gap-5">
        <div class="px-1 pt-1">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <span class="app-kicker">{{ __('Ad-Hoc Awards') }}</span>
                    <flux:heading size="xl" class="mt-1 text-zinc-950 font-black tracking-tight text-3xl">{{ __('Quick Dial') }}</flux:heading>
                    <flux:text class="mt-1 text-sm font-medium text-zinc-600">
                        {{ __('Directly award offers to customers') }}
                    </flux:text>
                </div>

                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-green-600 shadow-sm ring-1 ring-zinc-200">
                    <flux:icon.phone class="size-6" />
                </div>
            </div>
        </div>

        <div class="rounded-[1.75rem] bg-white p-6 shadow-sm ring-1 ring-zinc-200">
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

                <flux:modal.trigger name="contact-picker">
                    <button type="button" class="app-primary-button h-14 px-8 font-black uppercase tracking-widest text-[10px] transition active:scale-95" wire:click="openContactPicker">
                        {{ __('Contacts') }}
                    </button>
                </flux:modal.trigger>
            </div>

            @if ($this->normalizedCustomerPhone !== '')
                <div class="mt-4 flex items-center justify-between gap-3 rounded-2xl bg-zinc-50 px-5 py-4 text-sm ring-1 ring-zinc-200 shadow-inner">
                    <div class="min-w-0">
                        <div class="truncate font-black text-zinc-950">
                            {{ $this->selectedName !== '' ? $this->selectedName : __('Manual entry') }}
                        </div>
                        <div class="text-[10px] font-black text-green-600/70 uppercase tracking-widest mt-0.5">{{ $this->normalizedCustomerPhone }}</div>
                    </div>

                    <flux:button variant="ghost" size="sm" type="button" wire:click="clearSelectedContact" class="app-secondary-button text-zinc-700 hover:text-rose-500">
                        {{ __('Clear') }}
                    </flux:button>
                </div>
            @endif

            @if ($contactMessage)
                <div class="mt-4 rounded-2xl bg-green-50 px-5 py-4 text-xs font-black text-green-700 ring-1 ring-green-100">
                    {{ $contactMessage }}
                </div>
            @endif
        </div>

        <div class="overflow-hidden rounded-[1.75rem] bg-white shadow-sm ring-1 ring-zinc-200">
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
                                @disabled(! $this->canAward || $selectedOfferId === $offer->id)
                                wire:click="awardOffer({{ $offer->id }})"
                                wire:loading.attr="disabled"
                                wire:target="awardOffer"
                                @class([
                                    'h-12 rounded-[1.25rem] px-6 text-[10px] font-black uppercase tracking-widest transition active:scale-95',
                                    'bg-green-50 text-zinc-950 shadow-sm ring-1 ring-green-100 hover:bg-green-100' => $this->canAward && $selectedOfferId !== $offer->id,
                                    'bg-zinc-100 text-zinc-600 cursor-not-allowed ring-1 ring-zinc-300' => ! $this->canAward || $selectedOfferId === $offer->id,
                                ])
                            >
                                <span wire:loading.remove wire:target="awardOffer">{{ $selectedOfferId === $offer->id ? __('Sending…') : __('Award') }}</span>
                                <span wire:loading wire:target="awardOffer" class="inline-flex items-center justify-center gap-2">
                                    <flux:icon.loading variant="mini" class="size-4" />
                                    {{ __('Sending…') }}
                                </span>
                            </button>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <flux:modal name="contact-picker" class="max-w-lg" @close="closeContactPicker">
        <div class="space-y-5 p-1">
            <div>
                <flux:heading size="lg" class="font-black tracking-tight">{{ __('Find contact') }}</flux:heading>
                <flux:subheading>{{ __('Search your device contacts by name or number.') }}</flux:subheading>
            </div>

            <div class="relative group">
                <flux:icon.magnifying-glass class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-zinc-500 group-focus-within:text-green-600 transition-colors" />
                <input
                    type="text"
                    wire:model.live.debounce.350ms="contactSearch"
                    placeholder="{{ __('Search name or number') }}"
                    class="h-14 w-full rounded-2xl bg-zinc-50 pl-12 pr-4 text-base font-black text-zinc-950 outline-none ring-1 ring-zinc-200 transition focus:ring-2 focus:ring-green-500/50"
                >
            </div>

            @if ($contactMessage)
                <div class="rounded-2xl bg-green-50 px-5 py-4 text-xs font-black text-green-700 ring-1 ring-green-100">
                    {{ $contactMessage }}
                </div>
            @endif

            @if ($contactResults !== [])
                <div class="overflow-hidden rounded-[1.5rem] bg-zinc-50 ring-1 ring-zinc-200">
                    @foreach ($contactResults as $contact)
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-4 border-b border-zinc-200 px-5 py-4 text-left last:border-b-0 hover:bg-white transition-colors"
                            wire:click="selectContact(@js($contact['phone'] ?? ''), @js($contact['name'] ?? ''))"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-black text-zinc-950">{{ $contact['name'] ?? $contact['phone'] ?? '' }}</span>
                                <span class="block text-[10px] font-black text-green-600/70 uppercase tracking-widest mt-0.5">{{ $contact['phone'] ?? '' }}</span>
                            </span>

                            @if (! empty($contact['label']))
                                <span class="shrink-0 rounded-xl bg-white px-3 py-1 text-[8px] font-black uppercase tracking-[0.2em] text-zinc-500 ring-1 ring-zinc-200">
                                    {{ $contact['label'] }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @else
                <div class="rounded-[1.5rem] border border-dashed border-zinc-200 bg-zinc-50 p-6 text-center">
                    <div class="text-sm font-black text-zinc-600">
                        {{ __('Type at least one character to search.') }}
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>
</section>
