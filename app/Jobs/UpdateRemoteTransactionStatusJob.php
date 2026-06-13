<?php

namespace App\Jobs;

use App\Actions\Autoreach\RecoverBingwaDeviceToken;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UpdateRemoteTransactionStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public array $backoff = [10, 30, 60, 120, 240];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $remoteTransactionId,
        public readonly string $status,
        public readonly ?string $ussdResponse,
        public readonly ?float $airtimeUsed,
        public readonly ?int $executionTimeMs,
        public readonly string $executedAt,
        public readonly int $userId,
        public readonly ?string $failureCode = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $baseUrl = rtrim((string) config('services.autoreach.backend_url'), '/');

        Log::debug('Remote transaction status update job started.', [
            'remote_transaction_id' => $this->remoteTransactionId,
            'status' => $this->status,
            'user_id' => $this->userId,
            'executed_at' => $this->executedAt,
        ]);

        $user = User::query()
            ->with('bingwaDeviceRegistration')
            ->find($this->userId);

        if (! $user instanceof User) {
            Log::info("Skipping remote status update for transaction {$this->remoteTransactionId}: user #{$this->userId} no longer exists.");

            return;
        }

        $registration = $user->bingwaDeviceRegistration;
        $deviceToken = $registration?->device_token;

        if (blank($deviceToken)) {
            Log::warning("Cannot update remote status for transaction {$this->remoteTransactionId}: device token is missing.");

            return;
        }

        $payload = [
            'status' => $this->status,
            'executed_at' => $this->executedAt,
        ];

        if ($this->ussdResponse !== null) {
            $payload['ussd_response'] = $this->ussdResponse;
        }

        if ($this->airtimeUsed !== null) {
            $payload['airtime_used'] = $this->airtimeUsed;
        }

        if ($this->executionTimeMs !== null) {
            $payload['execution_time_ms'] = $this->executionTimeMs;
        }

        if ($this->status === 'failed' && $this->failureCode !== null) {
            $payload['failure_code'] = $this->failureCode;
        }

        $response = $this->sendRemoteStatusUpdate($baseUrl, $deviceToken, $payload);

        Log::debug('Remote transaction status update response received.', [
            'remote_transaction_id' => $this->remoteTransactionId,
            'status' => $response->status(),
            'body' => $response->body(),
            'payload' => $payload,
        ]);

        if ($response->status() === 401) {
            Log::warning("Autoreach backend returned 401 Unauthorized for transaction {$this->remoteTransactionId}; attempting token recovery and retry.");

            $recoveredRegistration = app(RecoverBingwaDeviceToken::class)->recover($user);
            $recoveredToken = $recoveredRegistration->device_token;

            if (! is_string($recoveredToken) || $recoveredToken === '') {
                throw new RuntimeException("Failed to recover device token for transaction {$this->remoteTransactionId}: recovered token was empty.");
            }

            $deviceToken = $recoveredToken;
            $response = $this->sendRemoteStatusUpdate($baseUrl, $deviceToken, $payload);
        }

        if ($response->status() === 409) {
            Log::warning("Autoreach backend returned 409 Conflict for transaction {$this->remoteTransactionId}. Transaction might not be in dispatched/executing state.", [
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            return;
        }

        if ($response->status() === 404) {
            Log::warning("Autoreach backend returned 404 Not Found for transaction {$this->remoteTransactionId}. Device might not own it.", [
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            return;
        }

        if ($response->status() === 422) {
            Log::error("Autoreach backend returned 422 Unprocessable Content for transaction {$this->remoteTransactionId}. Permanent failure.", [
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            $exception = new RuntimeException('Failed to update remote status due to validation error: '.$response->body());
            $this->fail($exception);
            throw $exception;
        }

        if (! $response->successful()) {
            Log::warning('Autoreach backend rejected the remote transaction status update.', [
                'remote_transaction_id' => $this->remoteTransactionId,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            throw new RuntimeException('Failed to update remote transaction status. API returned: '.$response->status().' '.$response->body());
        }

        Log::info("Successfully updated remote status for transaction {$this->remoteTransactionId} to {$this->status}");
    }

    /**
     * Send the remote transaction status update request.
     */
    private function sendRemoteStatusUpdate(string $baseUrl, string $deviceToken, array $payload): Response
    {
        return Http::timeout(30)
            ->acceptJson()
            ->retry(3, 100, function (\Throwable $exception): bool {
                return $exception instanceof ConnectionException;
            }, throw: false)
            ->withToken($deviceToken)
            ->patch("{$baseUrl}/api/v1/transactions/{$this->remoteTransactionId}/status", $payload);
    }
}
