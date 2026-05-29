<?php

namespace App\Jobs;

use App\Actions\Autoreach\RefreshAirtimeBalance;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshAirtimeBalanceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct() {}

    public function uniqueId(): string
    {
        return 'refresh-airtime-balance';
    }

    public function handle(): void
    {
        $users = User::query()
            ->whereHas('bingwaDeviceRegistration', function ($query) {
                $query->whereNotNull('device_token')
                    ->where('device_token', '!=', '')
                    ->where(function ($query) {
                        $query->whereNull('status')
                            ->orWhere('status', '!=', 'stopped');
                    });
            })
            ->get();

        foreach ($users as $user) {
            app(RefreshAirtimeBalance::class)->refresh($user);
        }
    }
}
