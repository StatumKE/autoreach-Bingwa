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

    public string $ussd_code = '';

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
        $this->ussd_code = '';
        $this->ussd_mode = 'express';
        $this->is_active = true;
    }
}; ?>

<section class="w-full p-4 md:p-6">
    <div class="flex flex-col gap-4">
        <div class="relative overflow-hidden rounded-3xl border border-emerald-800 bg-gradient-to-br from-emerald-950 via-emerald-900 to-zinc-900 p-5 text-white shadow-lg dark:border-emerald-700 md:p-6">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -top-12 -right-12 h-36 w-36 rounded-full bg-emerald-400/10 blur-3xl motion-safe:animate-pulse"></div>
                <div class="absolute -bottom-16 -left-12 h-40 w-40 rounded-full bg-zinc-400/10 blur-3xl motion-safe:animate-pulse" style="animation-delay: 240ms;"></div>
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent"></div>
            </div>

            <div class="relative flex items-start justify-between gap-4">
                <div>
                    <flux:heading size="xl" class="text-white">{{ __('My Offers') }}</flux:heading>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-right shadow-sm backdrop-blur-sm">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-emerald-300/60">{{ __('Offers') }}</div>
                    <div class="mt-1 text-2xl font-bold text-white">{{ $this->offersCount }}</div>
                </div>
            </div>
        </div>

        <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
            @foreach ($this->categoryFilters() as $value => $label)
                <flux:button
                    type="button"
                    class="shrink-0 rounded-full px-4"
                    variant="{{ $this->activeCategory === $value ? 'primary' : 'ghost' }}"
                    wire:click="setCategoryFilter('{{ $value }}')"
                >
                    {{ $label }}
                </flux:button>
            @endforeach
        </div>

        @if ($this->offers->isEmpty())
            <div class="rounded-[28px] border border-dashed border-zinc-200 bg-white px-6 py-12 text-center shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-zinc-100 text-3xl dark:bg-zinc-800">
                    +
                </div>
                <div class="mt-5 text-lg font-semibold text-zinc-950 dark:text-zinc-50">
                    {{ __('No offers found in this category.') }}
                </div>
                <div class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Tap the button below to add your first offer.') }}
                </div>

                <div class="mt-6">
                    <flux:modal.trigger name="offer-form">
                        <flux:button variant="primary" type="button" wire:click="createOffer">
                            {{ __('Add offer') }}
                        </flux:button>
                    </flux:modal.trigger>
                </div>
            </div>
        @else
            <div class="flex flex-col gap-3">
                @foreach ($this->offers as $offer)
                    <article class="rounded-3xl border border-zinc-100 bg-white p-5 shadow-sm transition-colors dark:border-zinc-800 dark:bg-zinc-900/50">
                        <div class="flex items-start justify-between gap-3">
                            <div class="space-y-1.5">
                                <div class="flex items-center gap-2">
                                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">{{ $offer->name }}</flux:heading>
                                    @if ($offer->is_active)
                                        <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">
                                            {{ __('Active') }}
                                        </span>
                                    @else
                                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                            {{ __('Paused') }}
                                        </span>
                                    @endif
                                </div>

                                <div class="flex flex-wrap gap-1.5">
                                    <span class="rounded-lg bg-zinc-50 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                                        {{ $this->categoryLabel($offer->category) }}
                                    </span>
                                    <span class="rounded-lg bg-emerald-50 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:bg-emerald-900/20 dark:text-emerald-400">
                                        {{ $this->ussdModeLabel($offer->ussd_mode) }}
                                    </span>
                                </div>
                            </div>

                            <div class="text-lg font-bold text-emerald-600 dark:text-emerald-400">
                                {{ __('Ksh :price', ['price' => number_format($offer->price)]) }}
                            </div>
                        </div>

                        <div class="mt-4 grid gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                            <div class="flex items-center justify-between gap-3">
                                <span>{{ __('USSD code') }}</span>
                                <span class="font-medium text-zinc-950 dark:text-zinc-50">{{ $offer->ussd_code }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span>{{ __('Mode') }}</span>
                                <span class="font-medium text-zinc-950 dark:text-zinc-50">{{ $this->ussdModeLabel($offer->ussd_mode) }}</span>
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <flux:button variant="ghost" type="button" wire:click="editOffer({{ $offer->id }})">
                                {{ __('Edit') }}
                            </flux:button>

                            <flux:button variant="danger" type="button" wire:click="confirmDeleteOffer({{ $offer->id }})">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    </article>
                @endforeach

                <div class="mt-4">
                    {{ $this->offers->links() }}
                </div>
            </div>
        @endif
    </div>

    <div class="fixed bottom-6 end-6 z-20">
        <flux:modal.trigger name="offer-form">
            <flux:button
                variant="primary"
                type="button"
                class="h-14 w-14 rounded-full shadow-lg"
                wire:click="createOffer"
            >
                <span class="text-3xl leading-none">+</span>
            </flux:button>
        </flux:modal.trigger>
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
            <div class="text-sm font-medium text-indigo-600 dark:text-indigo-300">
                {{ __('Use PN as the placeholder for the recipient\'s phone number.') }}
            </div>

            <flux:select wire:model="ussd_mode" :label="__('USSD Mode')" required>
                <flux:select.option value="express">{{ __('Express Mode - Direct USSD Dials') }}</flux:select.option>
                <flux:select.option value="advanced">{{ __('Advanced Mode - Step by Step Dials') }}</flux:select.option>
            </flux:select>

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:checkbox wire:model="is_active" :label="__('Enable this offer immediately')" />
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeForm">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button variant="primary" type="submit">
                    {{ $editingOfferId ? __('Update Offer') : __('Save Offer') }}
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
                <flux:button type="button" variant="ghost" wire:click="$set('deletingOfferId', null)">
                    {{ __('Cancel') }}
                </flux:button>

                <flux:button variant="danger" type="button" wire:click="deleteOffer">
                    {{ __('Delete offer') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
