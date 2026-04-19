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

<section class="w-full p-4 md:p-6">
    <div class="flex flex-col gap-5">
        <div class="relative overflow-hidden rounded-[2rem] border border-emerald-800 bg-gradient-to-br from-emerald-950 via-emerald-900 to-zinc-900 p-6 text-white shadow-xl dark:border-emerald-700/50">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -top-12 -right-12 h-48 w-48 rounded-full bg-emerald-400/10 blur-3xl motion-safe:animate-pulse"></div>
                <div class="absolute -bottom-16 -left-12 h-44 w-44 rounded-full bg-zinc-400/5 blur-3xl motion-safe:animate-pulse" style="animation-delay: 240ms;"></div>
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent"></div>
            </div>

            <div class="relative flex items-center justify-between gap-4">
                <div>
                    <span class="text-[10px] font-black uppercase tracking-[0.2em] text-emerald-300/60">{{ __('Ad-Hoc Awards') }}</span>
                    <flux:heading size="xl" class="mt-1 text-white font-bold tracking-tight">{{ __('Quick Dial') }}</flux:heading>
                </div>

                <div class="flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-emerald-400 shadow-sm backdrop-blur-sm">
                    <flux:icon.phone class="size-6" />
                </div>
            </div>
        </div>

        <div class="rounded-[2rem] border border-zinc-100 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/50">
            <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400/80">
                {{ __('Customer information') }}
            </div>

            <div class="mt-4 flex flex-col sm:flex-row gap-3">
                <div class="relative flex-1">
                    <flux:icon.device-phone-mobile class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-zinc-400" />
                    <input
                        wire:model.live.debounce.500ms="customerPhone"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        placeholder="07XXXXXXXX"
                        class="h-14 w-full rounded-2xl border border-zinc-100 bg-zinc-50 pl-12 pr-4 text-base font-bold text-zinc-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 dark:border-zinc-800 dark:bg-zinc-800 dark:text-zinc-50 dark:focus:border-emerald-500"
                    >
                </div>

                <button type="button" class="h-14 rounded-2xl bg-zinc-900 px-6 font-black uppercase tracking-widest text-white shadow-lg shadow-zinc-950/20 transition-all active:scale-95 dark:bg-emerald-600 dark:shadow-emerald-500/20" wire:click="searchContacts">
                    {{ __('Contacts') }}
                </button>
            </div>

            @if ($this->normalizedCustomerPhone !== '')
                <div class="mt-3 flex items-center justify-between gap-3 rounded-2xl bg-zinc-50 px-4 py-3 text-sm dark:bg-zinc-800/70">
                    <div class="min-w-0">
                        <div class="truncate font-semibold text-zinc-950 dark:text-zinc-50">
                            {{ $this->selectedName !== '' ? $this->selectedName : __('Manual entry') }}
                        </div>
                        <div class="text-zinc-500 dark:text-zinc-400">{{ $this->normalizedCustomerPhone }}</div>
                    </div>

                    <flux:button variant="ghost" size="sm" type="button" wire:click="clearSelectedContact">
                        {{ __('Clear') }}
                    </flux:button>
                </div>
            @endif

            @if ($contactMessage)
                <div class="mt-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                    {{ $contactMessage }}
                </div>
            @endif

            @if ($contactResults !== [])
                <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-100 dark:border-zinc-800">
                    @foreach ($contactResults as $contact)
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 border-b border-zinc-100 px-4 py-3 text-left last:border-b-0 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/70"
                            wire:click="selectContact(@js($contact['phone'] ?? ''), @js($contact['name'] ?? ''))"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-zinc-950 dark:text-zinc-50">{{ $contact['name'] ?? $contact['phone'] ?? '' }}</span>
                                <span class="block text-sm text-zinc-500 dark:text-zinc-400">{{ $contact['phone'] ?? '' }}</span>
                            </span>

                            @if (! empty($contact['label']))
                                <span class="shrink-0 rounded-full bg-zinc-100 px-3 py-1 text-xs font-bold uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                    {{ $contact['label'] }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="overflow-hidden rounded-[2rem] border border-zinc-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900/50">
            <div class="border-b border-zinc-100 px-6 py-5 dark:border-zinc-800">
                <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400/80">
                    {{ __('Available offers') }}
                </div>
            </div>

            @if ($awardMessage)
                <div class="mx-6 mt-6 rounded-2xl bg-emerald-50 px-4 py-4 text-xs font-bold text-emerald-700 border border-emerald-100 dark:bg-emerald-950/20 dark:text-emerald-400 dark:border-emerald-900/30">
                    {{ $awardMessage }}
                </div>
            @endif

            @if ($awardError)
                <div class="mx-6 mt-6 rounded-2xl bg-rose-50 px-4 py-4 text-xs font-bold text-rose-700 border border-rose-100 dark:bg-rose-950/20 dark:text-rose-400 dark:border-rose-900/30">
                    {{ $awardError }}
                </div>
            @endif

            @if ($this->activeOffers->isEmpty())
                <div class="px-6 py-12 text-center">
                    <div class="text-base font-bold text-zinc-400 dark:text-zinc-500 italic">
                        {{ __('No offers available for awarding.') }}
                    </div>
                </div>
            @else
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800/50">
                    @foreach ($this->activeOffers as $offer)
                        <article class="flex items-center justify-between gap-4 px-6 py-5 transition-colors hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                            <div class="min-w-0">
                                <div class="truncate text-base font-bold text-zinc-900 dark:text-zinc-50 tracking-tight">{{ $offer->name }}</div>
                                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-lg bg-zinc-50 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-zinc-500 border border-zinc-100 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700/50">
                                        {{ $offer->category }}
                                    </span>
                                    <span class="text-xs font-black text-emerald-600 dark:text-emerald-400">
                                        KES {{ number_format($offer->price) }}
                                    </span>
                                </div>
                            </div>

                            <button
                                type="button"
                                @disabled(! $this->canAward || $selectedOfferId === $offer->id)
                                wire:click="awardOffer({{ $offer->id }})"
                                @class([
                                    'h-10 rounded-xl px-4 text-xs font-black uppercase tracking-widest transition-all active:scale-95',
                                    'bg-emerald-600 text-white shadow-lg shadow-emerald-500/20' => $this->canAward && $selectedOfferId !== $offer->id,
                                    'bg-zinc-100 text-zinc-400 cursor-not-allowed dark:bg-zinc-800 dark:text-zinc-600' => ! $this->canAward || $selectedOfferId === $offer->id,
                                ])
                            >
                                {{ $selectedOfferId === $offer->id ? __('Sending') : __('Award') }}
                            </button>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
