<?php

namespace App\Livewire\Actions;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class RecentTransactions extends Component
{
    #[Computed]
    public function transactions(): Collection
    {
        return Cache::remember($this->cacheKey(), now()->addSeconds(10), function (): Collection {
            return Transaction::query()
                ->where('user_id', Auth::id())
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->take(10)
                ->get([
                    'id',
                    'sender_name',
                    'sender_phone',
                    'offer_name',
                    'amount',
                    'status',
                    'status_desc',
                    'occurred_at',
                ]);
        });
    }

    public function render(): View
    {
        return view('livewire.actions.recent-transactions');
    }

    private function cacheKey(): string
    {
        return 'recent-transactions:'.Auth::id();
    }
}
