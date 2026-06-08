<?php

namespace App\Jobs;

use App\Actions\Autoreach\FetchBingwaSubscriptionPlans;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PrefetchSubscriptionPlansJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
    ) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'prefetch-subscription-plans:'.$this->userId;
    }

    /**
     * Execute the job.
     */
    public function handle(FetchBingwaSubscriptionPlans $fetchPlansAction): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            Log::warning('Subscription plans prefetch job skipped because no user was found.', [
                'component' => 'plans_prefetch',
                'user_id' => $this->userId,
            ]);

            return;
        }

        Log::debug('Subscription plans prefetch job started.', [
            'component' => 'plans_prefetch',
            'user_id' => $user->getKey(),
        ]);

        try {
            $fetchPlansAction->fetch($user);

            Log::debug('Subscription plans prefetch job completed successfully.', [
                'component' => 'plans_prefetch',
                'user_id' => $user->getKey(),
            ]);
        } catch (\Throwable $throwable) {
            Log::warning('Subscription plans prefetch job failed or skipped.', [
                'component' => 'plans_prefetch',
                'user_id' => $user->getKey(),
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
