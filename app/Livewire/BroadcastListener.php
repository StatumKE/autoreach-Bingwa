<?php

namespace App\Livewire;

use App\Actions\Autoreach\PersistBingwaTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class BroadcastListener extends Component
{
    /**
     * Register Echo listeners for the authenticated device.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $registration = Auth::user()?->bingwaDeviceRegistration;
        $bhcCode = $registration?->bhc_code;

        if (! is_string($bhcCode) || $bhcCode === '') {
            return [];
        }

        return [
            "echo-private:autoreach-bingwa.devices.{$bhcCode},transaction.queued" => 'transactionQueued',
        ];
    }

    /**
     * Persist the queued transaction payload when the backend broadcasts it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function transactionQueued(array $payload): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $result = app(PersistBingwaTransaction::class)->persist($user, $payload);

        if ($result['transaction'] === null || $result['skipped']) {
            return;
        }

        $this->dispatch('autoreach-transaction-saved', transactionId: $result['transaction']->transaction_id);
    }

    public function render(): View
    {
        return view('livewire.broadcast-listener');
    }
}
