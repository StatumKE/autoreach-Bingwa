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
use Illuminate\Support\Facades\Log;

class RefreshAirtimeBalanceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $userId,
    ) {}

    public function uniqueId(): string
    {
        return 'refresh-airtime-balance';
    }

    public function handle(RefreshAirtimeBalance $refreshAirtimeBalance): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            Log::warning('Airtime balance refresh job skipped because no user was found.', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        $refreshAirtimeBalance->refresh($user);
    }
}
