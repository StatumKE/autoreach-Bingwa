<?php

namespace App\Jobs;

use App\Actions\Autoreach\RecoverBingwaDeviceToken;
use App\Models\Plan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SyncRemoteSubscriptionPurchaseJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $planId,
        public readonly ?string $paymentReference = null,
    ) {}

    public function uniqueId(): string
    {
        return 'sync-remote-subscription-purchase:'.$this->planId;
    }

    public function handle(): void
    {
        Log::debug('Remote subscription purchase sync job started.', [
            'component' => 'subscription_sync',
            'plan_id' => $this->planId,
            'payment_reference' => $this->paymentReference,
        ]);

        $plan = Plan::query()
            ->with('user.bingwaDeviceRegistration')
            ->find($this->planId);

        if (! $plan instanceof Plan) {
            Log::warning('Remote subscription purchase sync skipped because the local plan was not found.', [
                'component' => 'subscription_sync',
                'plan_id' => $this->planId,
            ]);

            return;
        }

        if ($plan->remote_purchase_synced_at !== null || $plan->remote_subscription_id !== null) {
            Log::debug('Remote subscription purchase sync skipped because the local plan is already synced.', [
                'component' => 'subscription_sync',
                'plan_id' => $plan->getKey(),
                'remote_subscription_id' => $plan->remote_subscription_id,
            ]);

            return;
        }

        $deviceToken = $plan->user?->bingwaDeviceRegistration?->device_token;

        if (! is_string($deviceToken) || $deviceToken === '') {
            throw new RuntimeException("Cannot sync subscription purchase for local plan {$plan->getKey()}: device token is missing.");
        }

        $baseUrl = rtrim((string) config('services.autoreach.backend_url'), '/');
        $payload = [
            'plan_code' => $plan->code,
            'promo_code' => null,
            'payment_reference' => $this->paymentReference,
        ];

        Log::info('Syncing successful subscription purchase to backend.', [
            'component' => 'subscription_sync',
            'plan_id' => $plan->getKey(),
            'plan_code' => $plan->code,
            'payment_reference' => $this->paymentReference,
        ]);

        $response = $this->postPurchase($baseUrl, $deviceToken, $payload);

        if ($response->status() === 401) {
            Log::warning('Remote subscription purchase sync returned unauthorized; attempting token recovery.', [
                'component' => 'subscription_sync',
                'plan_id' => $plan->getKey(),
                'plan_code' => $plan->code,
                'payment_reference' => $this->paymentReference,
            ]);

            $recoveredRegistration = app(RecoverBingwaDeviceToken::class)->recover($plan->user);
            $recoveredToken = $recoveredRegistration->device_token;

            if (! is_string($recoveredToken) || $recoveredToken === '') {
                throw new RuntimeException("Failed to recover device token for subscription purchase sync on plan {$plan->getKey()}: recovered token was empty.");
            }

            $response = $this->postPurchase($baseUrl, $recoveredToken, $payload);
        }

        if ($response->status() !== 201 || $response->json('status') !== 'accepted') {
            throw new RuntimeException('Failed to sync subscription purchase. API returned: '.$response->status().' '.$response->body());
        }

        $remoteSubscriptionId = $response->json('subscription_id');

        $plan->update([
            'remote_subscription_id' => is_numeric($remoteSubscriptionId) ? (int) $remoteSubscriptionId : null,
            'remote_purchase_synced_at' => now(),
            'remote_purchase_response' => $response->json(),
        ]);

        Log::info('Successful subscription purchase synced to backend.', [
            'component' => 'subscription_sync',
            'plan_id' => $plan->getKey(),
            'plan_code' => $plan->code,
            'remote_subscription_id' => $plan->remote_subscription_id,
        ]);

        Log::debug('Remote subscription purchase sync job finished.', [
            'component' => 'subscription_sync',
            'plan_id' => $plan->getKey(),
            'payment_reference' => $this->paymentReference,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Remote subscription purchase sync failed.', [
            'component' => 'subscription_sync',
            'plan_id' => $this->planId,
            'payment_reference' => $this->paymentReference,
            'message' => $exception?->getMessage(),
        ]);
    }

    /**
     * Send the remote subscription purchase request.
     */
    private function postPurchase(string $baseUrl, string $deviceToken, array $payload): Response
    {
        return Http::baseUrl($baseUrl)
            ->timeout(30)
            ->connectTimeout(10)
            ->acceptJson()
            ->asJson()
            ->retry(3, 100, function (\Throwable $exception): bool {
                return $exception instanceof ConnectionException;
            }, throw: false)
            ->withToken($deviceToken)
            ->post('/api/v1/subscription/purchase', $payload);
    }
}
