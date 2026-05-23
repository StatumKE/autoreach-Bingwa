<?php

namespace App\Actions\Autoreach;

use App\Jobs\UpdateRemoteTransactionStatusJob;
use App\Models\Transaction;
use App\Support\AppTimezone;
use App\Support\BingwaTransactionFailureCode;

class QueueRemoteTransactionStatusUpdate
{
    public function queue(
        Transaction $transaction,
        string $status,
        ?string $message = null,
        ?float $airtimeUsed = null,
        ?int $executionTimeMs = null,
        ?string $executedAt = null,
        ?string $failureCode = null,
    ): void {
        $transaction->loadMissing('user.bingwaDeviceRegistration');

        $remoteTransactionId = $transaction->transaction_id;
        $deviceToken = $transaction->user?->bingwaDeviceRegistration?->device_token;
        $localOnlyTransactionSources = ['quick_dial', 'auto_renewal'];
        $isLocalOnlyTransaction = in_array($transaction->matched_offer['source'] ?? null, $localOnlyTransactionSources, true);

        if ($isLocalOnlyTransaction || blank($remoteTransactionId) || blank($deviceToken)) {
            return;
        }

        UpdateRemoteTransactionStatusJob::dispatch(
            (int) $transaction->user_id,
            $remoteTransactionId,
            $deviceToken,
            $status,
            $message,
            $airtimeUsed,
            $executionTimeMs,
            $executedAt ?? AppTimezone::now()->toIso8601String(),
            $failureCode ?? BingwaTransactionFailureCode::fromStatusAndMessage($status, $message),
        )->afterCommit();
    }
}
