<?php

namespace App\Livewire\Actions;

use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class RecentTransactions extends Component
{
    public function mount()
    {
        Log::info('RecentTransactions class-based component mounted.');
    }

    public function transactions()
    {
        $txs = Transaction::where('user_id', Auth::id())
            ->orderBy('id', 'desc')
            ->take(10)
            ->get();

        Log::info('RecentTransactions data fetched.', [
            'count' => $txs->count(),
            'user_id' => Auth::id(),
        ]);

        return $txs;
    }

    public function render()
    {
        return view('livewire.actions.recent-transactions');
    }
}
