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

<section class="w-full p-4 md:p-6 bg-slate-950 min-h-screen">
    <div class="flex flex-col gap-4">
        <div class="relative overflow-hidden rounded-[2.5rem] bg-slate-900 p-6 shadow-2xl ring-1 ring-slate-800 md:p-8">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -top-12 -right-12 h-48 w-48 rounded-full bg-teal-500/5 blur-3xl"></div>
                <div class="absolute -bottom-16 -left-12 h-44 w-44 rounded-full bg-indigo-500/5 blur-3xl"></div>
            </div>

            <div class="relative flex items-center justify-between gap-4">
                <div>
                    <span class="text-[10px] font-black uppercase tracking-[0.3em] text-teal-400/40">{{ __('Service Portfolio') }}</span>
                    <flux:heading size="xl" class="mt-1 text-white font-black tracking-tight text-3xl">{{ __('My Offers') }}</flux:heading>
                </div>

                <div class="flex flex-col items-end rounded-[1.5rem] bg-slate-950 px-6 py-4 shadow-inner ring-1 ring-slate-800">
                    <div class="text-[9px] font-black uppercase tracking-widest text-teal-400/40">{{ __('Active') }}</div>
                    <div class="text-2xl font-black text-teal-400 leading-none mt-1">{{ $this->offersCount }}</div>
                </div>
            </div>
        </div>

        <div class="flex gap-2 overflow-x-auto scrollbar-hide pb-1">
            @foreach ($this->categoryFilters() as $value => $label)
                <flux:button
                    type="button"
                    class="shrink-0 rounded-2xl px-6 text-[10px] font-black uppercase tracking-widest transition-all active:scale-95"
                    variant="{{ $this->activeCategory === $value ? 'primary' : 'ghost' }}"
                    wire:click="setCategoryFilter('{{ $value }}')"
                    @class([
                        'bg-indigo-600 text-white shadow-xl shadow-indigo-600/20' => $this->activeCategory === $value,
                        'bg-slate-900 text-slate-400 ring-1 ring-slate-800' => $this->activeCategory !== $value,
                    ])
                >
                    {{ $label }}
                </flux:button>
            @endforeach
        </div>

        @if ($this->offers->isEmpty())
            <div class="rounded-[2.5rem] bg-slate-900 px-6 py-16 text-center shadow-2xl ring-1 ring-slate-800">
                <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-slate-950 text-indigo-400 shadow-inner mb-6">
                    <flux:icon.sparkles class="size-8" />
                </div>
                <div class="text-xl font-black text-white tracking-tight">
                    {{ __('No offers found') }}
                </div>
                <div class="mt-2 text-sm font-medium text-slate-500">
                    {{ __('Define your first automated USSD service to get started.') }}
                </div>

                <div class="mt-8">
                    <flux:modal.trigger name="offer-form">
                        <flux:button variant="primary" type="button" wire:click="createOffer" class="h-12 px-8 font-black uppercase tracking-widest text-[10px]">
                            {{ __('Create first offer') }}
                        </flux:button>
                    </flux:modal.trigger>
                </div>
            </div>
        @else
            <div class="flex flex-col gap-3">
                @foreach ($this->offers as $offer)
                    <article @class([
                        'group relative overflow-hidden rounded-[2.5rem] bg-slate-900 p-6 shadow-2xl ring-1 transition-all hover:ring-indigo-500/30',
                        'ring-teal-500/20' => $offer->is_active,
                        'ring-slate-800 grayscale-[0.5]' => !$offer->is_active,
                    ])>
                        @if ($offer->is_active)
                            <div class="absolute inset-y-0 left-0 w-1 bg-teal-500/40"></div>
                        @endif

                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 space-y-2.5">
                                <div class="flex items-center gap-3">
                                    <flux:heading size="lg" class="text-white font-black tracking-tight">{{ $offer->name }}</flux:heading>
                                    @if ($offer->is_active)
                                        <span class="inline-flex items-center rounded-lg bg-teal-500/10 px-2 py-0.5 text-[8px] font-black uppercase tracking-widest text-teal-400">
                                            {{ __('Active') }}
                                        </span>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-xl bg-slate-950 px-3 py-1.5 text-[10px] font-black uppercase tracking-widest text-teal-400/40 ring-1 ring-slate-800">
                                        {{ $this->categoryLabel($offer->category) }}
                                    </span>
                                    <span class="font-mono text-[10px] font-black text-slate-600 tracking-tighter">
                                        {{ $offer->ussd_code }}
                                    </span>
                                </div>
                            </div>

                             <div class="text-right">
                                <div class="text-xl font-black text-teal-400 leading-none">
                                    <span class="text-[10px] font-bold text-slate-500 mr-0.5 uppercase">KES</span>{{ number_format($offer->price) }}
                                </div>
                                <div class="text-[8px] font-black text-slate-600 uppercase tracking-[0.2em] mt-2">{{ __('UNIT PRICE') }}</div>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center justify-between border-t border-slate-800/50 pt-5">
                            <div class="flex items-center gap-2">
                                <div @class([
                                    'h-1.5 w-1.5 rounded-full',
                                    'bg-teal-400 shadow-[0_0_8px_rgba(45,212,191,0.5)] animate-pulse' => $offer->is_active,
                                    'bg-slate-700' => !$offer->is_active,
                                ])></div>
                                <span class="text-[9px] font-black text-slate-500 uppercase tracking-[0.2em]">
                                    {{ $offer->ussd_mode === 'express' ? __('Direct execution') : __('Guided flow') }}
                                </span>
                            </div>

                            <div class="flex items-center gap-2">
                                <flux:button variant="ghost" size="sm" type="button" wire:click="editOffer({{ $offer->id }})" class="rounded-2xl bg-slate-950 text-slate-400 ring-1 ring-slate-800 hover:text-white">
                                    <flux:icon.pencil-square class="size-4" />
                                </flux:button>

                                <flux:button variant="ghost" size="sm" type="button" wire:click="confirmDeleteOffer({{ $offer->id }})" class="rounded-2xl bg-rose-950/20 text-rose-500 ring-1 ring-rose-900/30 hover:bg-rose-900/40">
                                    <flux:icon.trash class="size-4" />
                                </flux:button>
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

    <div class="fixed bottom-6 end-6 z-20">
        <flux:modal.trigger name="offer-form">
            <flux:button
                variant="primary"
                type="button"
                class="h-16 w-16 rounded-[2rem] shadow-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 ring-4 ring-slate-950"
                wire:click="createOffer"
            >
                <flux:icon.plus class="size-8 text-white" />
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

            <div class="rounded-[2rem] bg-slate-950 p-6 ring-1 ring-slate-800 shadow-inner">
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
