<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UpdateRemoteTransactionStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $remoteTransactionId,
        public readonly string $deviceToken,
        public readonly string $status,
        public readonly ?string $ussdResponse,
        public readonly ?float $airtimeUsed,
        public readonly ?int $executionTimeMs,
        public readonly string $executedAt,
        public readonly ?string $failureCode = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $baseUrl = rtrim((string) config('services.autoreach.backend_url'), '/');

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

        $response = Http::timeout(30)
            ->acceptJson()
            ->withToken($this->deviceToken)
            ->patch("{$baseUrl}/api/v1/transactions/{$this->remoteTransactionId}/status", $payload);

        if ($response->status() === 409) {
            Log::warning("Autoreach backend returned 409 Conflict for transaction {$this->remoteTransactionId}. Transaction might not be in dispatched/executing state.");

            return;
        }

        if ($response->status() === 404) {
            Log::warning("Autoreach backend returned 404 Not Found for transaction {$this->remoteTransactionId}. Device might not own it.");

            return;
        }

        if (! $response->successful()) {
            throw new RuntimeException('Failed to update remote transaction status. API returned: '.$response->status().' '.$response->body());
        }

        Log::info("Successfully updated remote status for transaction {$this->remoteTransactionId} to {$this->status}");
    }
}
