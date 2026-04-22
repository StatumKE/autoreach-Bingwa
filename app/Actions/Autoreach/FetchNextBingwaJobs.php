<?php

namespace App\Actions\Autoreach;

use App\Models\Transaction;
use App\Models\User;
use GuzzleHttp\Promise\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchNextBingwaJobs
{
    /**
     * Pull the next queued jobs for a Bingwa device and persist them locally.
     *
     * @return array{synced:int, skipped:int, failed:int}
     */
    public function sync(User $user, int $limit = 10): array
    {
        $limit = $this->normalizeLimit($limit);
        $registration = $user->bingwaDeviceRegistration;

        if ($registration === null || blank($registration->device_token)) {
            return [
                'synced' => 0,
                'skipped' => 1,
                'failed' => 0,
            ];
        }

        $baseUrl = rtrim((string) config('services.autoreach.backend_url'), '/');
        $token = $registration->device_token;

        $synced = 0;
        $failed = 0;

        $endpoints = [
            'data_bundles' => '/api/v1/jobs/next/data_bundles',
            'sms' => '/api/v1/jobs/next/sms',
            'airtime' => '/api/v1/jobs/next/airtime',
        ];

        $promises = [];
        $activeOffers = $user->offers()->where('is_active', true)->get();
        $allJobs = [];

        // Fetch and validate the active subscription plan
        $activePlan = $user->plans()->where('is_active', true)->first();
        if ($activePlan) {
            $shouldDeactivate = false;
            if ($activePlan->type === 'time_unlimited' && $activePlan->expires_at && now()->isAfter($activePlan->expires_at)) {
                $shouldDeactivate = true;
            } elseif ($activePlan->type === 'usage_pack' && $activePlan->ussd_requests_included !== null && $activePlan->ussd_counter >= $activePlan->ussd_requests_included) {
                $shouldDeactivate = true;
            }
            if ($shouldDeactivate) {
                $activePlan->update(['is_active' => false]);
                $activePlan = null;
            }
        }

        foreach ($endpoints as $type => $endpoint) {
            $promises[$type] = Http::async()
                ->retry(3, 100)
                ->timeout(30)
                ->acceptJson()
                ->withToken($token)
                ->get("{$baseUrl}{$endpoint}", ['limit' => $limit])
                ->then(function (mixed $response) use ($type, $user, &$allJobs, &$failed): void {
                    if ($response instanceof \Throwable) {
                        $failed++;
                        report(new \RuntimeException("Failed to fetch {$type} jobs due to network error: ".$response->getMessage(), 0, $response));
                        return;
                    }

                    if ($response->noContent()) {
                        return;
                    }

                    if ($response->status() === 403) {
                        $failed++;

                        Log::warning('Autoreach backend stopped job polling for a device.', [
                            'user_id' => $user->id,
                            'endpoint' => $type,
                            'status' => $response->status(),
                        ]);
                        report(new \RuntimeException("Autoreach backend reported the device as stopped while fetching {$type} jobs."));

                        return;
                    }

                    if (! $response->successful()) {
                        if ($response->status() === 401) {
                            try {
                                app(RecoverBingwaDeviceToken::class)->recover($user);
                            } catch (\Throwable $e) {
                                report(new \RuntimeException('Failed to recover token during sync: '.$e->getMessage(), 0, $e));
                            }
                        }

                        $failed++;
                        report(new \RuntimeException("Unable to fetch {$type} jobs from the Autoreach backend."));

                        return;
                    }

                    $payload = $response->json();

                    if (! is_array($payload)) {
                        $failed++;
                        report(new \RuntimeException("Autoreach backend returned an invalid {$type} jobs payload."));

                        return;
                    }

                    $jobs = $this->extractJobs($payload);

                    if ($jobs === null) {
                        $failed++;
                        report(new \RuntimeException("Autoreach backend returned an unexpected {$type} jobs payload shape."));

                        return;
                    }

                    if ($jobs !== []) {
                        $allJobs[] = [
                            'type' => $type,
                            'jobs' => $jobs,
                        ];
                    }
                })->otherwise(function (\Throwable $e) use ($type, &$failed): void {
                    $failed++;
                    report(new \RuntimeException("Failed to fetch {$type} jobs due to exception: ".$e->getMessage(), 0, $e));
                });
        }

        // Wait for all HTTP requests to complete
        Utils::settle($promises)->wait();

        // Process all gathered jobs in a single database transaction for performance
        DB::transaction(function () use ($allJobs, $user, $activeOffers, $activePlan, &$synced): void {
            foreach ($allJobs as $jobGroup) {
                $type = $jobGroup['type'];
                $jobs = $jobGroup['jobs'];

                foreach ($jobs as $job) {
                    if (blank($job['transaction_id'] ?? null)) {
                        continue;
                    }

                    $amount = (float) ($job['amount'] ?? 0);

                    // Find a matching offer by price only
                    $matchedOffer = $activeOffers->firstWhere('price', (int) $amount);

                    $status = 'queued';
                    $statusDesc = __('Pulled from backend job queue.');
                    $offerId = $matchedOffer?->getKey();

                    if (! $activePlan) {
                        $status = 'failed';
                        $statusDesc = __('No active subscription plan found.');
                    } elseif (! $matchedOffer) {
                        $status = 'failed';
                        $statusDesc = __("Price mismatch: No active offer found for amount {$amount}.");
                    }

                    Transaction::query()->updateOrCreate(
                        ['transaction_id' => (string) $job['transaction_id']],
                        [
                            'user_id' => $user->id,
                            'offer_id' => $offerId,
                            'mpesa_code' => $job['mpesa_code'] ?? null,
                            'sender_phone' => $job['sender_phone'] ?? '',
                            'sender_name' => $job['sender_name'] ?? null,
                            'amount' => $amount,
                            'offer_name' => $job['offer_name'] ?? __('Unknown offer'),
                            'offer_type' => $job['offer_type'] ?? $type,
                            'matched_offer' => $job['matched_offer'] ?? null,
                            'balance' => null,
                            'occurred_at' => Carbon::parse($job['occurred_at'] ?? now())
                                ->setTimezone((string) config('app.timezone')),
                            'status' => $status,
                            'status_desc' => $statusDesc,
                        ]
                    );

                    $synced++;
                }
            }
        });

        return [
            'synced' => $synced,
            'skipped' => 0,
            'failed' => $failed,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>|null
     */
    private function extractJobs(array $payload): ?array
    {
        if (isset($payload['jobs']) && is_array($payload['jobs'])) {
            return array_values(array_filter($payload['jobs'], fn (mixed $job): bool => is_array($job)));
        }

        if (blank($payload['transaction_id'] ?? null)) {
            return null;
        }

        return [$payload];
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min($limit, 10));
    }
}
