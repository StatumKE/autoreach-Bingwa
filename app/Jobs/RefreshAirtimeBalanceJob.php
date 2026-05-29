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
        $user = User::query()->first();

        if ($user) {
            app(RefreshAirtimeBalance::class)->refresh($user);
        }
    }
}
