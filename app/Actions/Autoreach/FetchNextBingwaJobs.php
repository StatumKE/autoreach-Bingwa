<?php

namespace App\Actions\Autoreach;

use App\Models\User;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FetchNextBingwaJobs
{
    /**
     * Offer-type label → endpoint suffix mapping.
     *
     * @var array<string, string>
     */
    private const ENDPOINTS = [
        'data_bundles' => '/api/v1/jobs/next/data_bundles',
        'sms' => '/api/v1/jobs/next/sms',
        'airtime' => '/api/v1/jobs/next/airtime',
    ];

    /**
     * Pull the next queued jobs for a Bingwa device and persist them locally.
     *
     * All three backend endpoints are fetched concurrently via Http::pool()
     * so the total latency is bounded by the slowest single response rather
     * than the sum of all three (~30 s vs up to ~90 s sequential).
     *
     * @return array{synced:int, skipped:int, failed:int}
     */
    public function sync(User $user, int $limit = 10, ?string $onlyService = null): array
    {
        $limit = $this->normalizeLimit($limit);
        $registration = $user->bingwaDeviceRegistration;

        if ($registration === null || blank($registration->device_token)) {
            return ['synced' => 0, 'skipped' => 1, 'failed' => 0];
        }

        $baseUrl = rtrim((string) config('services.autoreach.backend_url'), '/');
        $token = $registration->device_token;

        $synced = 0;
        $skipped = 0;
        $failed = 0;

        $endpointsToFetch = self::ENDPOINTS;
        if ($onlyService !== null && array_key_exists($onlyService, $endpointsToFetch)) {
            $endpointsToFetch = [$onlyService => $endpointsToFetch[$onlyService]];
        }

        // ── Concurrent fetch ────────────────────────────────────────────────
        // Http::pool() dispatches all requests simultaneously using Guzzle's
        // async promise API. The pool is named so responses can be retrieved
        // by their endpoint key without relying on array position.
        $poolResponses = Http::pool(function (Pool $pool) use ($baseUrl, $token, $limit, $endpointsToFetch): array {
            $requests = [];

            foreach ($endpointsToFetch as $type => $endpoint) {
                $requests[] = $pool
                    ->as($type)
                    ->acceptJson()
                    ->withToken($token)
                    ->timeout(30)
                    ->get("{$baseUrl}{$endpoint}", ['limit' => $limit]);
            }

            return $requests;
        });

        // ── Per-endpoint response processing ────────────────────────────────
        // Pool responses may be Throwable instances when a network-level error
        // occurs — always check the type before treating the value as a Response.
        $allJobs = [];

        foreach ($endpointsToFetch as $type => $endpoint) {
            $url = "{$baseUrl}{$endpoint}";
            $response = $poolResponses[$type] ?? null;

            // Network/connection-level failure (Throwable from the pool).
            if ($response instanceof Throwable) {
                $failed++;
                $this->logRequestFailure(
                    user: $user,
                    endpoint: $type,
                    url: $url,
                    reason: 'network_error',
                    context: [
                        'exception_class' => $response::class,
                        'exception_message' => $response->getMessage(),
                    ],
                );
                report(new \RuntimeException(
                    "Failed to fetch {$type} jobs due to network error: ".$response->getMessage(),
                    0,
                    $response,
                ));

                continue;
            }

            // Guard: should not happen, but be defensive.
            if (! $response instanceof Response) {
                $failed++;
                $this->logRequestFailure($user, $type, $url, 'unexpected_pool_result');

                continue;
            }


            // 403 — backend reports the device as stopped.
            if ($response->status() === 403) {
                $failed++;
                $this->logRequestFailure(
                    user: $user,
                    endpoint: $type,
                    url: $url,
                    reason: 'backend_stopped',
                    context: $this->responseContext($response),
                );
                report(new \RuntimeException(
                    "Autoreach backend reported the device as stopped while fetching {$type} jobs.",
                ));

                continue;
            }

            // 401 — expired token; attempt recovery and retry the single endpoint.
            if ($response->status() === 401) {
                [$response, $token] = $this->attemptTokenRecovery(
                    user: $user,
                    type: $type,
                    url: $url,
                    baseUrl: $baseUrl,
                    endpoint: $endpoint,
                    limit: $limit,
                    currentResponse: $response,
                    currentToken: $token,
                    failed: $failed,
                );

                // Recovery returned null — already logged and counted; skip.
                if ($response === null) {
                    continue;
                }
            }

            // 204 No Content — no jobs waiting (check this after potential token recovery)
            if ($response->status() === 204 || $response->noContent()) {
                continue;
            }

            if (! $response->successful()) {
                $failed++;
                $this->logRequestFailure(
                    user: $user,
                    endpoint: $type,
                    url: $url,
                    reason: 'backend_rejected',
                    context: $this->responseContext($response),
                );
                report(new \RuntimeException("Unable to fetch {$type} jobs from the Autoreach backend."));

                continue;
            }

            $responsePayload = $response->json();

            if (! is_array($responsePayload)) {
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

            $jobs = $this->extractJobs($responsePayload);

            if ($jobs === null) {
                $failed++;
                $this->logRequestFailure(
                    user: $user,
                    endpoint: $type,
                    url: $url,
                    reason: 'unexpected_payload_shape',
                    context: $this->responseContext($response),
                );
                report(new \RuntimeException(
                    "Autoreach backend returned an unexpected {$type} jobs payload shape.",
                ));

                continue;
            }

            if ($jobs !== []) {
                $allJobs[] = ['type' => $type, 'jobs' => $jobs];
            }
        }

        // ── Persist in a single DB transaction ──────────────────────────────
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

        return ['synced' => $synced, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Attempt to recover an expired device token and retry the request.
     *
     * Returns a [Response|null, string] tuple — null response means the recovery
     * failed and the caller should skip this endpoint.
     *
     * @return array{0: Response|null, 1: string}
     */
    private function attemptTokenRecovery(
        User $user,
        string $type,
        string $url,
        string $baseUrl,
        string $endpoint,
        int $limit,
        Response $currentResponse,
        string $currentToken,
        int &$failed,
    ): array {
        try {
            $recoveredRegistration = app(RecoverBingwaDeviceToken::class)->recover($user);
            $recoveredToken = $recoveredRegistration->device_token;

            if (! is_string($recoveredToken) || $recoveredToken === '') {
                throw new \RuntimeException('The recovered device token was empty.');
            }

            $retried = Http::acceptJson()
                ->withToken($recoveredToken)
                ->timeout(30)
                ->get("{$baseUrl}{$endpoint}", ['limit' => $limit]);

            // If the retried response is also 403, the device was stopped.
            if ($retried->status() === 403) {
                $failed++;
                $this->logRequestFailure(
                    user: $user,
                    endpoint: $type,
                    url: $url,
                    reason: 'backend_stopped',
                    context: array_merge(
                        $this->responseContext($retried),
                        ['token_recovery' => 'recovered'],
                    ),
                );
                report(new \RuntimeException(
                    "Autoreach backend reported the device as stopped after token recovery for {$type} jobs.",
                ));

                return [null, $recoveredToken];
            }

            return [$retried, $recoveredToken];
        } catch (Throwable $e) {
            $failed++;
            $this->logRequestFailure(
                user: $user,
                endpoint: $type,
                url: $url,
                reason: 'token_recovery_failed',
                context: array_merge(
                    $this->responseContext($currentResponse),
                    [
                        'token_recovery' => 'failed',
                        'token_recovery_exception' => $e::class,
                        'token_recovery_error' => $e->getMessage(),
                    ],
                ),
            );
            report(new \RuntimeException('Failed to recover token during sync: '.$e->getMessage(), 0, $e));

            return [null, $currentToken];
        }
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
