<?php

namespace App\Jobs;

use App\Models\Plan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
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
        $plan = Plan::query()
            ->with('user.bingwaDeviceRegistration')
            ->find($this->planId);

        if (! $plan instanceof Plan) {
            Log::warning('Remote subscription purchase sync skipped because the local plan was not found.', [
                'plan_id' => $this->planId,
            ]);

            return;
        }

        if ($plan->remote_purchase_synced_at !== null || $plan->remote_subscription_id !== null) {
            Log::debug('Remote subscription purchase sync skipped because the local plan is already synced.', [
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
            'plan_id' => $plan->getKey(),
            'plan_code' => $plan->code,
            'payment_reference' => $this->paymentReference,
        ]);

        $response = Http::baseUrl($baseUrl)
            ->timeout(30)
            ->connectTimeout(10)
            ->acceptJson()
            ->asJson()
            ->withToken($deviceToken)
            ->post('/api/v1/subscription/purchase', $payload);

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
            'plan_id' => $plan->getKey(),
            'plan_code' => $plan->code,
            'remote_subscription_id' => $plan->remote_subscription_id,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Remote subscription purchase sync failed.', [
            'plan_id' => $this->planId,
            'payment_reference' => $this->paymentReference,
            'message' => $exception?->getMessage(),
        ]);
    }
}
