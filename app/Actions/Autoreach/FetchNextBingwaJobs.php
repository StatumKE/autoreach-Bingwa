<?php

namespace App\Actions\Autoreach;

use App\Models\Transaction;
use App\Models\User;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class FetchNextBingwaJobs
{
    /**
     * Pull the next queued jobs for a Bingwa device and persist them locally.
     *
     * @return array{synced:int, skipped:int, failed:int}
     */
    public function sync(User $user, int $limit = 10): array
    {
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

        foreach ($endpoints as $type => $endpoint) {
            $promises[$type] = Http::async()
                ->acceptJson()
                ->withToken($token)
                ->get("{$baseUrl}{$endpoint}", ['limit' => $limit])
                ->then(function ($response) use ($type, $user, &$synced, &$failed): void {
                    if ($response->status() === 204) {
                        return;
                    }

                    if (! $response->successful()) {
                        $failed++;
                        report(new \RuntimeException("Unable to fetch {$type} jobs from the Autoreach backend."));

                        return;
                    }

                    $payload = $response->json();

                    if (! is_array($payload)) {
                        return;
                    }

                    $jobs = $this->extractJobs($payload);
                    $balance = $payload['balance'] ?? null;

                    foreach ($jobs as $job) {
                        if (! is_array($job) || blank($job['transaction_id'] ?? null)) {
                            continue;
                        }

                        Transaction::query()->updateOrCreate(
                            ['transaction_id' => (string) $job['transaction_id']],
                            [
                                'user_id' => $user->id,
                                'mpesa_code' => $job['mpesa_code'] ?? null,
                                'sender_phone' => $job['sender_phone'] ?? '',
                                'sender_name' => $job['sender_name'] ?? null,
                                'amount' => (float) ($job['amount'] ?? 0),
                                'offer_name' => $job['offer_name'] ?? __('Unknown offer'),
                                'offer_type' => $job['offer_type'] ?? $type,
                                'matched_offer' => $job['matched_offer'] ?? null,
                                'balance' => $balance,
                                'occurred_at' => Carbon::parse($job['occurred_at'] ?? now()),
                                'status' => 'queued',
                                'status_desc' => __('Pulled from backend job queue.'),
                            ]
                        );

                        $synced++;
                    }
                })->otherwise(function (\Throwable $e) use ($type, &$failed): void {
                    $failed++;
                    report(new \RuntimeException("Failed to fetch {$type} jobs due to exception: ".$e->getMessage(), 0, $e));
                });
        }

        Utils::settle($promises)->wait();

        return [
            'synced' => $synced,
            'skipped' => 0,
            'failed' => $failed,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractJobs(array $payload): array
    {
        if (isset($payload['jobs']) && is_array($payload['jobs'])) {
            return $payload['jobs'];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        return [array_filter($payload, fn ($value, string $key): bool => ! in_array($key, ['balance', 'count'], true), ARRAY_FILTER_USE_BOTH)];
    }
}
