<?php

use App\Models\Offer;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('My Offers')] class extends Component {
    use WithPagination;

    public bool $showForm = false;

    public ?int $editingOfferId = null;

    public ?int $deletingOfferId = null;

    public string $activeCategory = 'all';

    public string $name = '';

    public string $category = 'data';

    public string $price = '';

    public string $ussd_code = '*180*5*PN#';

    public string $ussd_mode = 'express';

    public bool $is_active = true;

    /**
     * Open the create offer form.
     */
    public function createOffer(): void
    {
        $this->resetForm();
        $this->showForm = true;
        $this->dispatch('modal-show', name: 'offer-form');
    }

    /**
     * Load an existing offer into the form for editing.
     */
    public function editOffer(int $offerId): void
    {
        $offer = $this->ownedOffer($offerId);

        $this->editingOfferId = $offer->id;
        $this->name = $offer->name;
        $this->category = $offer->category;
        $this->price = (string) $offer->price;
        $this->ussd_code = $offer->ussd_code ?? '';
        $this->ussd_mode = $offer->ussd_mode;
        $this->is_active = $offer->is_active;
        $this->showForm = true;
        $this->dispatch('modal-show', name: 'offer-form');
    }

    /**
     * Persist the offer being edited or created.
     */
    public function saveOffer(): void
    {
        $validated = $this->validate($this->rules());

        $payload = [
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'category' => $validated['category'],
            'price' => (int) $validated['price'],
            'ussd_code' => $validated['ussd_code'] ?? null,
            'ussd_mode' => $validated['ussd_mode'],
            'is_active' => (bool) $validated['is_active'],
        ];

        if ($this->editingOfferId !== null) {
            $offer = $this->ownedOffer($this->editingOfferId);
            $offer->update($payload);
        } else {
            Offer::create($payload);
        }

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('modal-close', name: 'offer-form');

        Flux::toast(variant: 'success', text: __('Offer saved.'));
    }

    /**
     * Ask for delete confirmation for a given offer.
     */
    public function confirmDeleteOffer(int $offerId): void
    {
        $this->deletingOfferId = $this->ownedOffer($offerId)->id;
    }

    /**
     * Delete the selected offer.
     */
    public function deleteOffer(): void
    {
        if ($this->deletingOfferId === null) {
            return;
        }

        $offer = $this->ownedOffer($this->deletingOfferId);
        $offer->delete();

        if ($this->editingOfferId === $offer->id) {
            $this->resetForm();
            $this->showForm = false;
        }

        $this->deletingOfferId = null;

        Flux::toast(variant: 'success', text: __('Offer deleted.'));
    }

    /**
     * Reset the active category filter.
     */
    public function setCategoryFilter(string $category): void
    {
        $this->activeCategory = $category;
    }

    /**
     * Close the editor modal without saving.
     */
    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('modal-close', name: 'offer-form');
    }

    /**
     * Get the offers for the current account and filter.
     */
    #[Computed]
    public function offers()
    {
        return Offer::query()
            ->where('user_id', Auth::id())
            ->when($this->activeCategory !== 'all', function ($query): void {
                $query->where('category', $this->activeCategory);
            })
            ->orderBy('id', 'desc')
            ->paginate(10);
    }

    /**
     * Get the total number of offers for the active filter.
     */
    #[Computed]
    public function offersCount(): int
    {
        return $this->offers->count();
    }

    /**
     * Get the label for a category.
     */
    public function categoryLabel(?string $category): string
    {
        return match ($category) {
            'data' => __('Data'),
            'airtime' => __('Airtime'),
            'sms' => __('SMS'),
            default => __('Other'),
        };
    }

    /**
     * Get the display label for the USSD mode.
     */
    public function ussdModeLabel(?string $mode): string
    {
        return match ($mode) {
            'advanced' => __('Advanced Mode - Step by Step Dials'),
            default => __('Express Mode - Direct USSD Dials'),
        };
    }

    /**
     * Get the category options for the form.
     *
     * @return array<string, string>
     */
    public function categoryOptions(): array
    {
        return [
            'sms' => __('SMS'),
            'airtime' => __('Airtime'),
            'data' => __('Data'),
        ];
    }

    /**
     * Get the USSD mode options for the form.
     *
     * @return array<string, string>
     */
    public function ussdModeOptions(): array
    {
        return [
            'express' => __('Express Mode - Direct USSD Dials'),
            'advanced' => __('Advanced Mode - Step by Step Dials'),
        ];
    }

    /**
     * Get the category filters for the list.
     *
     * @return array<string, string>
     */
    public function categoryFilters(): array
    {
        return [
            'all' => __('All'),
            'sms' => __('SMS'),
            'airtime' => __('Airtime'),
            'data' => __('Data'),
        ];
    }

    /**
     * Build the validation rules for the offer form.
     *
     * @return array<string, array<int, Rule|array<mixed>|string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys($this->categoryOptions()))],
            'price' => ['required', 'integer', 'min:0', 'max:999999'],
            'ussd_code' => ['required', 'string', 'max:100'],
            'ussd_mode' => ['required', Rule::in(array_keys($this->ussdModeOptions()))],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Find an offer belonging to the current user.
     */
    private function ownedOffer(int $offerId): Offer
    {
        return Offer::query()
            ->where('user_id', Auth::id())
            ->findOrFail($offerId);
    }

    /**
     * Reset the offer form to its default state.
     */
    private function resetForm(): void
    {
        $this->editingOfferId = null;
        $this->name = '';
        $this->category = 'data';
        $this->price = '';
        $this->ussd_code = '*180*5*PN#';
        $this->ussd_mode = 'express';
        $this->is_active = true;
    }
}; ?>

<section class="min-h-screen bg-app-bg px-4 pb-24 pt-3">
    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between px-1">
            <div class="text-xl font-bold text-zinc-900">{{ __('My Offers') }}</div>
            <flux:modal.trigger name="offer-form">
                <flux:button
                    type="button"
                    wire:click="createOffer"
                    class="app-primary-button !h-9 px-4 text-[10px] font-bold uppercase tracking-widest"
                >
                    <flux:icon.plus variant="mini" class="mr-1 size-3.5" />
                    {{ __('Add') }}
                </flux:button>
            </flux:modal.trigger>
        </div>

        <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
            @foreach ($this->categoryFilters() as $value => $label)
                <flux:button
                    type="button"
                    variant="ghost"
                    wire:click="setCategoryFilter('{{ $value }}')"
                    @class([
                        'shrink-0 rounded-xl h-8 px-4 text-[10px] font-bold uppercase tracking-widest transition active:scale-95',
                        'bg-green-600 text-white shadow-sm ring-1 ring-inset ring-green-700/20' => $this->activeCategory === $value,
                        'bg-white text-zinc-500 ring-1 ring-inset ring-zinc-200 hover:bg-zinc-50 hover:text-zinc-900' => $this->activeCategory !== $value,
                    ])
                >
                    {{ $label }}
                </flux:button>
            @endforeach
        </div>


        @if ($this->offers->isEmpty())
            <div class="rounded-xl bg-white px-6 py-12 text-center shadow-sm ring-1 ring-zinc-200">
                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-green-50 text-green-600 shadow-inner mb-4">
                    <flux:icon.sparkles class="size-6" />
                </div>
                <div class="text-base font-bold text-zinc-900">
                    {{ __('No offers found') }}
                </div>
                <div class="mt-1 text-sm text-zinc-500">
                    {{ __('Define your first automated USSD service to get started.') }}
                </div>

                <div class="mt-6">
                    <flux:modal.trigger name="offer-form">
                        <flux:button variant="primary" type="button" wire:click="createOffer" class="app-primary-button h-10 px-6 text-[10px] font-bold uppercase tracking-widest">
                            {{ __('Create first offer') }}
                        </flux:button>
                    </flux:modal.trigger>
                </div>
            </div>
        @else
            <div class="flex flex-col gap-3">
                @foreach ($this->offers as $offer)
                    <article @class([
                        'group relative overflow-hidden rounded-xl bg-white p-4 shadow-sm ring-1 transition hover:ring-green-500/30',
                        'ring-green-500/20' => $offer->is_active,
                        'ring-zinc-200 grayscale-[0.5]' => !$offer->is_active,
                    ])>
                        @if ($offer->is_active)
                            <div class="absolute inset-y-0 left-0 w-1 bg-green-500/40"></div>
                        @endif

                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 space-y-2.5">
                                <div class="flex items-center gap-3">
                                    <flux:heading size="lg" class="text-zinc-950 font-bold tracking-tight text-base">{{ $offer->name }}</flux:heading>
                                    @if ($offer->is_active)
                                        <span class="inline-flex items-center rounded-lg bg-green-50 px-2 py-0.5 text-[8px] font-black uppercase tracking-widest text-green-700 ring-1 ring-green-100">
                                            {{ __('Active') }}
                                        </span>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-xl bg-green-50 px-3 py-1.5 text-[10px] font-black uppercase tracking-widest text-green-700/70 ring-1 ring-green-100">
                                        {{ $this->categoryLabel($offer->category) }}
                                    </span>
                                    <span class="font-mono text-[10px] font-black text-zinc-600 tracking-tighter">
                                        {{ $offer->ussd_code }}
                                    </span>
                                </div>
                            </div>

                             <div class="text-right">
                                <div class="text-xl font-black text-green-700 leading-none">
                                    <span class="text-[10px] font-bold text-zinc-500 mr-0.5 uppercase">KES</span>{{ number_format($offer->price) }}
                                </div>
                                <div class="text-[8px] font-black text-zinc-600 uppercase tracking-[0.2em] mt-2">{{ __('UNIT PRICE') }}</div>
                            </div>
                        </div>

                        <div class="mt-4 flex items-center justify-between border-t border-zinc-200 pt-3">
                            <div class="flex items-center gap-2">
                                <div @class([
                                    'h-1.5 w-1.5 rounded-full',
                                    'bg-green-400 shadow-[0_0_8px_rgba(52,211,153,0.5)] animate-pulse' => $offer->is_active,
                                    'bg-zinc-300' => !$offer->is_active,
                                ])></div>
                                <span class="text-[9px] font-black text-zinc-500 uppercase tracking-[0.2em]">
                                    {{ $offer->ussd_mode === 'express' ? __('Direct execution') : __('Guided flow') }}
                                </span>
                            </div>

                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="editOffer({{ $offer->id }})" class="app-secondary-button flex size-8 items-center justify-center">
                                    <flux:icon.pencil-square class="size-4" />
                                </button>

                                <button type="button" wire:click="confirmDeleteOffer({{ $offer->id }})" class="app-danger-button flex size-8 items-center justify-center">
                                    <flux:icon.trash class="size-4" />
                                </button>
                            </div>
                        </div>
                    </article>
@endforeach

                <div class="mt-4">
                    {{ $this->offers->links() }}
                </div>
            </div>
        @endif
    </div>

    <flux:modal name="offer-form" focusable class="max-w-2xl">
        <form wire:submit="saveOffer" class="space-y-6 p-1">
            <div>
                <flux:heading size="lg">{{ $editingOfferId ? __('Edit Offer') : __('Create Offer') }}</flux:heading>
                <flux:subheading>{{ __('Define pricing and USSD flow') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Offer Name')" type="text" required autocomplete="off" placeholder="e.g. 1.25 GB Midnight Bundles" />

            <flux:select wire:model="category" :label="__('Category')" required>
                <flux:select.option value="sms">{{ __('SMS') }}</flux:select.option>
                <flux:select.option value="airtime">{{ __('Airtime') }}</flux:select.option>
                <flux:select.option value="data">{{ __('Data') }}</flux:select.option>
            </flux:select>

            <flux:input wire:model="price" :label="__('Price (KES)')" type="number" min="0" step="1" required autocomplete="off" placeholder="e.g. 50" />

            <flux:input wire:model="ussd_code" :label="__('USSD Code')" type="text" required autocomplete="off" placeholder="*180*5*PN#" />
            <div class="text-sm font-medium text-green-600 dark:text-green-300">
                {{ __('Use PN as the placeholder for the recipient\'s phone number.') }}
            </div>

            <flux:select wire:model="ussd_mode" :label="__('USSD Mode')" required>
                <flux:select.option value="express">{{ __('Express Mode - Direct USSD Dials') }}</flux:select.option>
                <flux:select.option value="advanced">{{ __('Advanced Mode - Step by Step Dials') }}</flux:select.option>
            </flux:select>

            <div class="rounded-[1.5rem] bg-zinc-50 p-6 ring-1 ring-zinc-200 shadow-inner">
                <flux:checkbox wire:model="is_active" :label="__('Enable this offer immediately')" />
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeForm" class="app-secondary-button">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button variant="primary" type="submit" class="app-primary-button" wire:loading.attr="disabled" wire:target="saveOffer">
                    <span wire:loading.remove wire:target="saveOffer">
                        {{ $editingOfferId ? __('Update Offer') : __('Save Offer') }}
                    </span>
                    <span wire:loading wire:target="saveOffer" class="inline-flex items-center justify-center gap-2">
                        <flux:icon.loading variant="mini" class="size-4" />
                        {{ __('Saving…') }}
                    </span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :show="$deletingOfferId !== null" focusable class="max-w-lg">
        <div class="space-y-6 p-1">
            <div>
                <flux:heading size="lg">{{ __('Delete offer?') }}</flux:heading>
                <flux:subheading>{{ __('This removes the offer from this device account.') }}</flux:subheading>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                <flux:button type="button" variant="ghost" wire:click="$set('deletingOfferId', null)" class="app-secondary-button">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button variant="danger" type="button" wire:click="deleteOffer" class="app-danger-button" wire:loading.attr="disabled" wire:target="deleteOffer">
                    <span wire:loading.remove wire:target="deleteOffer">{{ __('Delete offer') }}</span>
                    <span wire:loading wire:target="deleteOffer" class="inline-flex items-center justify-center gap-2">
                        <flux:icon.loading variant="mini" class="size-4" />
                        {{ __('Deleting…') }}
                    </span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
