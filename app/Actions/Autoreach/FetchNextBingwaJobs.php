<?php

namespace App\Actions\Autoreach;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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

        $allJobs = [];

        foreach ($endpoints as $type => $endpoint) {
            $url = "{$baseUrl}{$endpoint}";

            try {
                $response = $this->executeJobRequest($baseUrl, $endpoint, $token, $limit);
            } catch (Throwable $e) {
                $failed++;
                $this->logRequestFailure(
                    user: $user,
                    endpoint: $type,
                    url: $url,
                    reason: 'network_error',
                    context: [
                        'exception_class' => get_class($e),
                        'exception_message' => $e->getMessage(),
                    ],
                );
                report(new \RuntimeException("Failed to fetch {$type} jobs due to network error: ".$e->getMessage(), 0, $e));

                continue;
            }

            if ($response->noContent()) {
                continue;
            }

            if ($response->status() === 403) {
                $failed++;

                $this->logRequestFailure(
                    user: $user,
                    endpoint: $type,
                    url: $url,
                    reason: 'backend_stopped',
                    context: $this->responseContext($response),
                );
                report(new \RuntimeException("Autoreach backend reported the device as stopped while fetching {$type} jobs."));

                continue;
            }

            $recoveryContext = [];

            if ($response->status() === 401) {
                try {
                    $recoveredRegistration = app(RecoverBingwaDeviceToken::class)->recover($user);
                    $recoveredToken = $recoveredRegistration->device_token;

                    if (! is_string($recoveredToken) || $recoveredToken === '') {
                        throw new \RuntimeException('The recovered device token was empty.');
                    }

                    $recoveryContext['token_recovery'] = 'attempted';
                    $recoveryContext['token_recovery_status'] = 'recovered';
                    $token = $recoveredToken;
                    $response = $this->executeJobRequest($baseUrl, $endpoint, $recoveredToken, $limit);
                } catch (Throwable $e) {
                    $recoveryContext['token_recovery'] = 'failed';
                    $recoveryContext['token_recovery_exception'] = get_class($e);
                    $recoveryContext['token_recovery_error'] = $e->getMessage();

                    $this->logRequestFailure(
                        user: $user,
                        endpoint: $type,
                        url: $url,
                        reason: 'token_recovery_failed',
                        context: array_merge(
                            $this->responseContext($response),
                            $recoveryContext,
                        ),
                    );
                    report(new \RuntimeException('Failed to recover token during sync: '.$e->getMessage(), 0, $e));

                    continue;
                }
            }

            if ($response->status() === 403) {
                $failed++;

                $this->logRequestFailure(
                    user: $user,
                    endpoint: $type,
                    url: $url,
                    reason: 'backend_stopped',
                    context: array_merge(
                        $this->responseContext($response),
                        $recoveryContext,
                    ),
                );
                report(new \RuntimeException("Autoreach backend reported the device as stopped while fetching {$type} jobs."));

                continue;
            }

            if (! $response->successful()) {
                $failed++;
                $this->logRequestFailure(
                    user: $user,
                    endpoint: $type,
                    url: $url,
                    reason: 'backend_rejected',
                    context: array_merge(
                        $this->responseContext($response),
                        $recoveryContext,
                    ),
                );
                report(new \RuntimeException("Unable to fetch {$type} jobs from the Autoreach backend."));

                continue;
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                $failed++;
                $this->logRequestFailure(
                    user: $user,
                    endpoint: $type,
                    url: $url,
                    reason: 'invalid_payload',
                    context: $this->responseContext($response),
                );
                report(new \RuntimeException("Autoreach backend returned an invalid {$type} jobs payload."));

                continue;
            }

            $jobs = $this->extractJobs($payload);

            if ($jobs === null) {
                $failed++;
                $this->logRequestFailure(
                    user: $user,
                    endpoint: $type,
                    url: $url,
                    reason: 'unexpected_payload_shape',
                    context: $this->responseContext($response),
                );
                report(new \RuntimeException("Autoreach backend returned an unexpected {$type} jobs payload shape."));

                continue;
            }

            if ($jobs !== []) {
                $allJobs[] = [
                    'type' => $type,
                    'jobs' => $jobs,
                ];
            }
        }

        // Process all gathered jobs in a single database transaction for performance
        DB::transaction(function () use ($allJobs, $user, &$synced, &$skipped, &$failed): void {
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
                        if ($result['transaction']->status === 'failed') {
                            $failed++;

                            continue;
                        }

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
     * Execute a queued jobs request using the given device token.
     */
    private function executeJobRequest(string $baseUrl, string $endpoint, string $token, int $limit): Response
    {
        return Http::baseUrl($baseUrl)
            ->retry(3, 100, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException;
            }, throw: false)
            ->timeout(30)
            ->acceptJson()
            ->withToken($token)
            ->get($endpoint, ['limit' => $limit]);
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

    /**
     * @return array{status:int, response_body:string, response_json:array<string, mixed>|null}
     */
    private function responseContext(Response $response): array
    {
        $body = trim($response->body());
        $decoded = json_decode($body, true);

        return [
            'status' => $response->status(),
            'response_body' => Str::limit($body, 1000, ''),
            'response_json' => is_array($decoded) ? $decoded : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logRequestFailure(
        User $user,
        string $endpoint,
        string $url,
        string $reason,
        array $context = [],
    ): void {
        Log::warning('Bingwa transaction sync request failed.', array_merge([
            'user_id' => $user->getKey(),
            'endpoint' => $endpoint,
            'url' => $url,
            'reason' => $reason,
        ], $context));
    }
}
