<?php

use App\Models\AutoReply;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Auto Replies')] class extends Component {
    use WithPagination;

    public bool $loaded = true;

    public bool $showForm = false;

    public ?int $editingAutoReplyId = null;

    public string $name = '';

    public string $triggerCondition = 'successful_transaction';

    public string $replyMessage = '';

    public bool $isActive = false;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    /**
     * Ensure the standard reply templates exist for the current user.
     */
    public function mount(): void
    {
        $this->ensureDefaultReplies();
    }

    /**
     * Set the loaded flag so the list renders after the shell is painted.
     */
    public function loadPage(): void
    {
        $this->loaded = true;
    }

    /**
     * Open the create form with default values.
     */
    public function createAutoReply(): void
    {
        $this->resetForm();
        $this->showForm = true;
        $this->dispatch('modal-show', name: 'auto-reply-form');
    }

    /**
     * Load an existing reply into the form for editing.
     */
    public function editAutoReply(int $autoReplyId): void
    {
        $autoReply = $this->ownedAutoReply($autoReplyId);

        $this->editingAutoReplyId = $autoReply->id;
        $this->name = $autoReply->name;
        $this->triggerCondition = $autoReply->trigger_condition;
        $this->replyMessage = $autoReply->reply_message;
        $this->isActive = $autoReply->is_active;
        $this->showForm = true;
        $this->dispatch('modal-show', name: 'auto-reply-form');
    }

    /**
     * Persist the auto reply being edited or created.
     */
    public function saveAutoReply(): void
    {
        $validated = $this->validate($this->rules());
        $payload = [
            'name' => $validated['name'],
            'trigger_condition' => $validated['triggerCondition'],
            'reply_message' => $validated['replyMessage'],
            'is_active' => (bool) $validated['isActive'],
            'sort_order' => $this->sortOrderFor($validated['triggerCondition']),
        ];

        DB::transaction(function () use ($payload, $validated): void {
            if ($this->editingAutoReplyId !== null) {
                $autoReply = $this->ownedAutoReply($this->editingAutoReplyId);
                $autoReply->update($payload);
            } else {
                $autoReply = AutoReply::query()->create($payload + [
                    'user_id' => Auth::id(),
                    'is_default' => false,
                ]);
            }

            if ($autoReply->is_active) {
                $this->deactivateOtherActiveReplies($autoReply->trigger_condition, $autoReply->id);
            }
        });

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('modal-close', name: 'auto-reply-form');

        Flux::toast(variant: 'success', text: __('Auto reply saved.'));
        $this->successMessage = __('Auto reply saved.');
    }

    /**
     * Toggle the enabled state for a reply.
     */
    public function toggleAutoReply(int $autoReplyId): void
    {
        $autoReply = $this->ownedAutoReply($autoReplyId);
        $newState = ! $autoReply->is_active;

        DB::transaction(function () use ($autoReply, $newState): void {
            $autoReply->update([
                'is_active' => $newState,
            ]);

            if ($newState) {
                $this->deactivateOtherActiveReplies($autoReply->trigger_condition, $autoReply->id);
            }
        });

        Flux::toast(variant: 'success', text: $newState ? __('Auto reply enabled.') : __('Auto reply disabled.'));
    }

    /**
     * Get the configured auto replies for the current user.
     */
    #[Computed]
    public function autoReplies()
    {
        return AutoReply::query()
            ->where('user_id', Auth::id())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Provide the trigger condition options.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public function triggerOptions(): array
    {
        return [
            'successful_transaction' => [
                'label' => __('Successful Response'),
                'description' => __('Triggered after an award is completed successfully.'),
            ],
            'failed_transaction' => [
                'label' => __('Failed Request'),
                'description' => __('Triggered when an award fails.'),
            ],
            'offer_unavailable' => [
                'label' => __('Unavailable Offer'),
                'description' => __('Triggered when no matching offer is available.'),
            ],
            'already_recommended' => [
                'label' => __('Already Recommended'),
                'description' => __('Triggered when the customer already has this offer.'),
            ],
            'app_paused' => [
                'label' => __('App Paused'),
                'description' => __('Triggered when the app is paused or unavailable.'),
            ],
            'blacklisted_customer' => [
                'label' => __('Blacklisted Customer'),
                'description' => __('Triggered when the customer cannot receive the award.'),
            ],
        ];
    }

    /**
     * Describe a trigger condition in plain text.
     */
    public function triggerLabel(?string $condition): string
    {
        return $this->triggerOptions()[$condition]['label'] ?? __('Unknown Condition');
    }

    /**
     * Return the helper text for a trigger condition.
     */
    public function triggerDescription(?string $condition): string
    {
        return $this->triggerOptions()[$condition]['description'] ?? __('Condition not configured.');
    }

    /**
     * Return the standard placeholder guide.
     *
     * @return array<int, array{token: string, description: string}>
     */
    public function placeholderGuide(): array
    {
        return [
            ['token' => '<firstName>', 'description' => __('Customer first name')],
            ['token' => '<surname>', 'description' => __("Customer's surname")],
            ['token' => '<mpesaCode>', 'description' => __('M-Pesa transaction code')],
            ['token' => '<amount>', 'description' => __('Amount paid by the customer')],
            ['token' => '<offerName>', 'description' => __('Offer name')],
            ['token' => '<offerPrice>', 'description' => __('Current offer price')],
        ];
    }

    /**
     * Get a short message preview for list rendering.
     */
    public function messagePreview(string $message): string
    {
        return Str::limit($message, 110);
    }

    /**
     * Get the status label for the toggle.
     */
    public function statusLabel(bool $active): string
    {
        return $active ? __('Active') : __('Inactive');
    }

    /**
     * Get the CSS classes for a status chip.
     */
    public function statusClasses(bool $active): string
    {
        return $active
            ? 'bg-green-50 text-green-700 ring-green-100'
            : 'bg-zinc-100 text-zinc-600 ring-zinc-200';
    }

    /**
     * Get the CSS classes for a trigger chip.
     */
    public function triggerClasses(string $condition): string
    {
        return match ($condition) {
            'successful_transaction' => 'bg-green-50 text-green-700 ring-green-100',
            'failed_transaction' => 'bg-rose-50 text-rose-700 ring-rose-100',
            'offer_unavailable' => 'bg-amber-50 text-amber-700 ring-amber-100',
            'already_recommended' => 'bg-sky-50 text-sky-700 ring-sky-100',
            'app_paused' => 'bg-zinc-100 text-zinc-600 ring-zinc-200',
            'blacklisted_customer' => 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-100',
            default => 'bg-zinc-100 text-zinc-600 ring-zinc-200',
        };
    }

    /**
     * Close the form without saving.
     */
    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('modal-close', name: 'auto-reply-form');
    }

    /**
     * Build the validation rules for the auto reply form.
     *
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'triggerCondition' => ['required', 'string', 'max:80'],
            'replyMessage' => ['required', 'string', 'max:1000'],
            'isActive' => ['boolean'],
        ];
    }

    /**
     * Reset the active form state.
     */
    private function resetForm(): void
    {
        $this->editingAutoReplyId = null;
        $this->name = '';
        $this->triggerCondition = 'successful_transaction';
        $this->replyMessage = '';
        $this->isActive = false;
    }

    /**
     * Seed the default replies for the current user.
     */
    private function ensureDefaultReplies(): void
    {
        $existingTriggers = AutoReply::query()
            ->where('user_id', Auth::id())
            ->pluck('trigger_condition')
            ->all();

        $missingReplies = [];

        foreach ($this->defaultReplies() as $index => $reply) {
            if (in_array($reply['trigger_condition'], $existingTriggers, true)) {
                continue;
            }

            $missingReplies[] = [
                'user_id' => Auth::id(),
                'name' => $reply['name'],
                'trigger_condition' => $reply['trigger_condition'],
                'reply_message' => $reply['reply_message'],
                'is_active' => false,
                'is_default' => true,
                'sort_order' => $index,
            ];
        }

        if ($missingReplies !== []) {
            AutoReply::query()->insert($missingReplies);
        }
    }

    /**
     * Default reply templates.
     *
     * @return array<int, array{name: string, trigger_condition: string, reply_message: string}>
     */
    private function defaultReplies(): array
    {
        return [
            [
                'name' => __('Successful Response'),
                'trigger_condition' => 'successful_transaction',
                'reply_message' => __('Hi <firstName>, thank you for purchasing from Bingwa Hybrid.'),
            ],
            [
                'name' => __('Offer Already Recommended'),
                'trigger_condition' => 'already_recommended',
                'reply_message' => __('Hello <firstName>, you have already purchased this offer today. Please try again tomorrow.'),
            ],
            [
                'name' => __('Failed Request'),
                'trigger_condition' => 'failed_transaction',
                'reply_message' => __('Hello <firstName>, your request failed. Please hold as we look into the issue.'),
            ],
            [
                'name' => __('Unavailable Offer'),
                'trigger_condition' => 'offer_unavailable',
                'reply_message' => __('Hi <firstName>, there is no offer matching the amount you have paid. Please pay the correct amount and try again.'),
            ],
            [
                'name' => __('App Paused'),
                'trigger_condition' => 'app_paused',
                'reply_message' => __('Hi <firstName>, there is an issue affecting our systems. You will get your offer as soon as service resumes.'),
            ],
            [
                'name' => __('Customer Blacklisted'),
                'trigger_condition' => 'blacklisted_customer',
                'reply_message' => __('Hi <firstName>, there is an issue affecting your account. Please reach out to us for assistance.'),
            ],
        ];
    }

    /**
     * Convert a condition into a display order.
     */
    private function sortOrderFor(string $condition): int
    {
        $conditions = array_keys($this->triggerOptions());
        $position = array_search($condition, $conditions, true);

        return $position !== false ? $position + 1 : 99;
    }

    /**
     * Find an auto reply belonging to the current user.
     */
    private function ownedAutoReply(int $autoReplyId): AutoReply
    {
        return AutoReply::query()
            ->where('user_id', Auth::id())
            ->findOrFail($autoReplyId);
    }

    /**
     * Disable all other active replies for the same trigger.
     */
    private function deactivateOtherActiveReplies(string $triggerCondition, int $exceptAutoReplyId): void
    {
        AutoReply::query()
            ->where('user_id', Auth::id())
            ->where('trigger_condition', $triggerCondition)
            ->whereKeyNot($exceptAutoReplyId)
            ->update([
                'is_active' => false,
            ]);
    }
}; ?>

<section class="min-h-screen bg-app-bg px-4 pb-24 pt-3">
    @php
        $autoReplies = $this->loaded ? $this->autoReplies : collect();
    @endphp

    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between px-1">
            <div class="text-xl font-bold text-zinc-900">{{ __('Auto Replies') }}</div>
            <button
                type="button"
                wire:click="createAutoReply"
                class="app-primary-button flex h-9 items-center gap-2 px-4 text-[10px] font-bold uppercase tracking-widest transition active:scale-95"
            >
                <flux:icon.plus class="size-3.5" />
                {{ __('Add') }}
            </button>
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

        @if (! $this->loaded)
            <div class="flex flex-col gap-3">
                @for ($i = 0; $i < 4; $i++)
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200 animate-pulse">
                        <div class="h-4 w-28 rounded bg-zinc-100"></div>
                        <div class="mt-4 h-4 w-40 rounded bg-zinc-100"></div>
                        <div class="mt-3 h-16 w-full rounded bg-zinc-100/70"></div>
                    </div>
                @endfor
            </div>
        @elseif ($autoReplies->isEmpty())
            <div class="rounded-xl bg-white px-4 py-10 text-center shadow-sm ring-1 ring-zinc-200">
                <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-green-50 text-green-600 ring-1 ring-green-100 shadow-inner">
                    <flux:icon.sparkles class="size-6" />
                </div>
                <div class="mt-3 text-base font-bold text-zinc-900">
                    {{ __('No auto replies yet') }}
                </div>
                <div class="mx-auto mt-1 max-w-sm text-sm text-zinc-500">
                    {{ __('Default reply templates will be created automatically.') }}
                </div>
            </div>
        @else
            <div class="flex flex-col gap-3">
                @foreach ($autoReplies as $autoReply)
                    <article class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-zinc-200">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1 space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="truncate text-sm font-bold text-zinc-900">
                                        {{ $autoReply->name }}
                                    </span>

                                    @if ($autoReply->is_default)
                                        <span class="inline-flex items-center rounded-full bg-zinc-100 px-2.5 py-1 text-[9px] font-black uppercase tracking-widest text-zinc-600 ring-1 ring-zinc-200">
                                            {{ __('Default') }}
                                        </span>
                                    @endif
                                </div>

                                <div class="text-sm font-medium italic leading-relaxed text-zinc-600">
                                    {{ $this->messagePreview($autoReply->reply_message) }}
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <span @class([
                                        'inline-flex items-center rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-widest ring-1',
                                        $this->triggerClasses($autoReply->trigger_condition),
                                    ])>
                                        {{ $this->triggerLabel($autoReply->trigger_condition) }}
                                    </span>

                                    <span @class([
                                        'inline-flex items-center rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-widest ring-1',
                                        $this->statusClasses($autoReply->is_active),
                                    ])>
                                        {{ $this->statusLabel($autoReply->is_active) }}
                                    </span>
                                </div>
                            </div>

                            <button
                                type="button"
                                wire:click="toggleAutoReply({{ $autoReply->id }})"
                                class="flex h-10 w-16 items-center rounded-full ring-2 ring-inset transition {{ $autoReply->is_active ? 'justify-end bg-green-500/15 ring-green-500/35' : 'justify-start bg-zinc-100 ring-zinc-300' }}"
                                aria-label="{{ $autoReply->is_active ? __('Disable auto reply') : __('Enable auto reply') }}"
                            >
                                <span class="mx-1.5 size-7 rounded-full bg-white shadow-sm ring-1 ring-black/10"></span>
                            </button>
                        </div>

                        <div class="mt-4 flex items-center justify-between border-t border-zinc-100 pt-3">
                            <button
                                type="button"
                                wire:click="editAutoReply({{ $autoReply->id }})"
                                class="app-secondary-button px-4 py-2 text-[10px] font-black uppercase tracking-widest"
                            >
                                {{ __('Edit') }}
                            </button>

                            <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400">
                                {{ $this->triggerDescription($autoReply->trigger_condition) }}
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>



    <flux:modal name="auto-reply-form" focusable class="max-w-lg">
        <form wire:submit="saveAutoReply" class="space-y-5 p-1">
            <div>
                <flux:heading size="lg" class="text-zinc-950">
                    {{ $editingAutoReplyId ? __('Edit Auto Reply') : __('Create AutoReply') }}
                </flux:heading>
                <flux:subheading class="mt-1">{{ __('Set the trigger condition and reply content.') }}</flux:subheading>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-500" for="auto-reply-name">
                    {{ __('Name of AutoReply') }}
                </label>
                <input
                    id="auto-reply-name"
                    wire:model="name"
                    type="text"
                    autocomplete="off"
                    placeholder="{{ __('Name of AutoReply') }}"
                    class="h-12 w-full rounded-2xl border border-zinc-300 bg-transparent px-4 text-base font-medium text-zinc-700 outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-500/10"
                >
            </div>

            <div class="space-y-3 rounded-[1.5rem] bg-zinc-100/80 p-4 ring-1 ring-zinc-200">
                <div class="text-[10px] font-black uppercase tracking-[0.24em] text-zinc-600">
                    {{ __('Trigger Conditions') }}
                </div>

                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($this->triggerOptions() as $condition => $option)
                        <button
                            type="button"
                            wire:click="$set('triggerCondition', '{{ $condition }}')"
                            @class([
                                'rounded-2xl px-4 py-3 text-left ring-1 transition',
                                'bg-green-100 text-zinc-950 ring-green-200 shadow-sm' => $this->triggerCondition === $condition,
                                'bg-white text-zinc-700 ring-zinc-200' => $this->triggerCondition !== $condition,
                            ])
                        >
                            <div class="text-sm font-black tracking-tight">
                                {{ $option['label'] }}
                            </div>
                            <div class="mt-1 text-[10px] font-medium leading-snug text-zinc-500">
                                {{ $option['description'] }}
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="rounded-[1.25rem] bg-white px-4 py-3 ring-1 ring-zinc-200">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-black text-zinc-950">{{ __('Activate') }}</div>
                    <input wire:model="isActive" type="checkbox" class="size-6 rounded border-zinc-300 text-green-600 focus:ring-green-500">
                </div>
            </div>

            <div>
                <div class="text-[10px] font-black uppercase tracking-[0.24em] text-zinc-500">
                    {{ __('PlaceHolders:') }}
                </div>
                <div class="mt-3 space-y-2 text-sm leading-relaxed text-zinc-700">
                    @foreach ($this->placeholderGuide() as $placeholder)
                        <div>
                            <span class="font-black text-zinc-950">{{ $placeholder['token'] }}</span>
                            <span class="text-zinc-500"> - {{ $placeholder['description'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-500" for="reply-message">
                    {{ __('Reply Message') }}
                </label>
                <textarea
                    id="reply-message"
                    wire:model="replyMessage"
                    rows="6"
                    placeholder="{{ __('Type the message that will be sent automatically…') }}"
                    class="w-full rounded-2xl border border-zinc-300 bg-transparent px-4 py-3 text-base font-medium text-zinc-700 outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-500/10"
                ></textarea>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeForm" class="app-secondary-button">
                    {{ __('Cancel') }}
                </flux:button>

                <button
                    type="submit"
                    class="app-primary-button h-14 px-6 text-[10px] font-black uppercase tracking-widest disabled:cursor-not-allowed disabled:opacity-60"
                    wire:loading.attr="disabled"
                    wire:target="saveAutoReply"
                >
                    <span wire:loading.remove wire:target="saveAutoReply">
                        {{ $editingAutoReplyId ? __('Update') : __('Save') }}
                    </span>
                    <span wire:loading wire:target="saveAutoReply" class="inline-flex items-center justify-center gap-2">
                        <flux:icon.loading variant="mini" class="size-4" />
                        {{ __('Saving…') }}
                    </span>
                </button>
            </div>
        </form>
    </flux:modal>
</section>
