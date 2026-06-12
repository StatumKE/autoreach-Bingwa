import re

dashboard_path = 'resources/views/components/⚡dashboard.blade.php'
transactions_path = 'resources/views/components/⚡transactions.blade.php'

with open(dashboard_path, 'r') as f:
    dashboard_content = f.read()

with open(transactions_path, 'r') as f:
    transactions_content = f.read()

# 1. Add use Livewire\Attributes\Computed;
if 'use Livewire\Attributes\Computed;' not in dashboard_content:
    dashboard_content = dashboard_content.replace('use Flux\Flux;\n', 'use Flux\Flux;\nuse Livewire\Attributes\Computed;\n')

# 2. Add properties
if 'public bool $showTransactionDetails = false;' not in dashboard_content:
    props = """    public bool $isProcessingEnabled = true;

    public bool $showTransactionDetails = false;

    public ?int $selectedTransactionId = null;"""
    dashboard_content = dashboard_content.replace('    public bool $isProcessingEnabled = true;', props)

# 3. Add methods
if 'public function openTransactionDetails' not in dashboard_content:
    methods = """
    public function openTransactionDetails(int $transactionId): void
    {
        $this->selectedTransactionId = $transactionId;
        $this->showTransactionDetails = true;
    }

    public function closeTransactionDetails(): void
    {
        $this->showTransactionDetails = false;
        $this->selectedTransactionId = null;
    }

    #[Computed]
    public function selectedTransaction(): ?Transaction
    {
        if ($this->selectedTransactionId === null) {
            return null;
        }

        return Transaction::query()
            ->with(['offer:id,name,ussd_code,ussd_mode'])
            ->where('user_id', Auth::id())
            ->find($this->selectedTransactionId);
    }

    public function transactionProductLabel(Transaction $transaction): string
    {
        return $transaction->offer?->name
            ?? ($transaction->matched_offer['offer_name'] ?? $transaction->offer_name ?? '—');
    }

    public function resolvedUssdCode(Transaction $transaction): string
    {
        $ussdCode = (string) ($transaction->offer?->ussd_code ?? '');

        if ($ussdCode === '') {
            return '—';
        }

        return str_replace('PN', (string) $transaction->sender_phone, $ussdCode);
    }

    public function formatDetailValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || $value === '') {
            return '—';
        }

        return (string) $value;
    }
"""
    dashboard_content = dashboard_content.replace('}; ?>', methods + '\n}; ?>')

# 4. Modify the loop item
old_div = """                            <div @class([
                                'px-3 py-2 text-left transition',
                                'bg-emerald-50/40 hover:bg-emerald-50/60' => $isSuccess,
                                'bg-rose-50/40 hover:bg-rose-50/60' => $isFailed,
                                'bg-zinc-50/40 hover:bg-zinc-50/60' => ! $isSuccess && ! $isFailed,
                            ])>"""

new_div = """                            <div 
                                wire:click="openTransactionDetails({{ $tx->id }})"
                                role="button"
                                tabindex="0"
                                @class([
                                'px-3 py-2 text-left transition cursor-pointer',
                                'bg-emerald-50/40 hover:bg-emerald-50/60' => $isSuccess,
                                'bg-rose-50/40 hover:bg-rose-50/60' => $isFailed,
                                'bg-zinc-50/40 hover:bg-zinc-50/60' => ! $isSuccess && ! $isFailed,
                            ])>"""
dashboard_content = dashboard_content.replace(old_div, new_div)

# 5. Extract and add the modal
modal_start = '    <flux:modal\n        name="transaction-details"'
modal_end_regex = r'        @endif\n    </flux:modal>'

import re
match = re.search(re.escape(modal_start) + r'.*?' + modal_end_regex, transactions_content, re.DOTALL)
if match:
    modal_content = match.group(0)
    
    if 'name="transaction-details"' not in dashboard_content:
        dashboard_content = dashboard_content.replace('    </div>\n</div>', '\n' + modal_content + '\n    </div>\n</div>')

with open(dashboard_path, 'w') as f:
    f.write(dashboard_content)

print("Dashboard patched.")
