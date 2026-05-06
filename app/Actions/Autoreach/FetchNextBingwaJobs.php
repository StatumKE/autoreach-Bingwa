<?php

namespace App\Actions\Autoreach;

use App\Models\User;
use GuzzleHttp\Promise\Utils;
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
        $skipped = 0;
        $failed = 0;

        $endpoints = [
            'data_bundles' => '/api/v1/jobs/next/data_bundles',
            'sms' => '/api/v1/jobs/next/sms',
            'airtime' => '/api/v1/jobs/next/airtime',
        ];

        $promises = [];
        $allJobs = [];

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
        DB::transaction(function () use ($allJobs, $user, &$synced, &$skipped): void {
            foreach ($allJobs as $jobGroup) {
                $type = $jobGroup['type'];
                $jobs = $jobGroup['jobs'];

                foreach ($jobs as $job) {
                    $result = app(PersistBingwaTransaction::class)->persist($user, $job, null, null, $type);

                    if ($result['skipped']) {
                        $skipped++;

                        continue;
                    }

                    if ($result['transaction'] !== null) {
                        $synced++;
                    }
                }
            }
        });

        return [
            'synced' => $synced,
            'skipped' => $skipped,
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
