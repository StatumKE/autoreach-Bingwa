<?php

namespace App\Jobs;

use App\Actions\Autoreach\CompleteBingwaTransaction;
use App\Actions\Autoreach\ExecuteBingwaUssd;
use App\Actions\Autoreach\GetNextBingwaQueuedTransaction;
use App\Models\DeviceSetting;
use App\Models\Transaction;
use App\Support\BingwaUssdResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessBingwaQueuedTransactionsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ?int $userId = null,
        public ?string $flowId = null,
    ) {}

    public int $tries = 3;

    public int $timeout = 360;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function uniqueId(): string
    {
        return 'bingwa-process-queued-transactions';
    }

    public function handle(
        CompleteBingwaTransaction $completeBingwaTransaction,
        ExecuteBingwaUssd $executeBingwaUssd,
        GetNextBingwaQueuedTransaction $getNextBingwaQueuedTransaction,
    ): void {
        $flowId = $this->flowId ??= (string) Str::uuid();
        $userId = $this->userId();

        if ($userId <= 0) {
            Log::warning('Bingwa USSD processor job skipped because no user id was provided.', [
                'flow_id' => $flowId,
            ]);

            return;
        }

        Log::debug('Bingwa USSD processor job started.', [
            'user_id' => $userId,
            'flow_id' => $flowId,
            'attempt' => $this->attempts(),
            'tries' => $this->tries,
        ]);

        // Resolve the processing-enabled flag exactly once for the entire job run.
        // This single boolean is then forwarded to GetNextBingwaQueuedTransaction so
        // it does not re-query the cache on every loop iteration.
        $isProcessingEnabled = DeviceSetting::isTransactionProcessingEnabledForUser($userId);

        if (! $isProcessingEnabled) {
            Log::info('Bingwa USSD processor paused by device setting.', [
                'user_id' => $userId,
                'flow_id' => $flowId,
            ]);

            return;
        }

        $processed = 0;
        $deadline = now()->addSeconds(45);

        while (now()->lessThan($deadline)) {
            // Re-check the flag on each iteration to honour mid-run pauses, but
            // use the cached result already held in DeviceSetting (TTL 300s).
            if (! DeviceSetting::isTransactionProcessingEnabledForUser($userId)) {
                Log::info('Bingwa USSD processor stopped because device processing was paused mid-run.', [
                    'user_id' => $userId,
                    'flow_id' => $flowId,
                    'processed' => $processed,
                ]);

                break;
            }

            // Forward the pre-resolved flag so next() skips its own cache lookup.
            $queuedJob = $getNextBingwaQueuedTransaction->next($userId, $isProcessingEnabled);

            if ($queuedJob === null) {
                Log::debug('Bingwa USSD processor found no queued transaction.', [
                    'user_id' => $userId,
                    'flow_id' => $flowId,
                ]);

                break;
            }

            if (isset($queuedJob['skip'])) {
                Log::debug('Bingwa USSD processor skipped a transaction while loading the next payload.', [
                    'user_id' => $userId,
                    'flow_id' => $flowId,
                    'transaction_id' => $queuedJob['id'] ?? null,
                ]);

                continue;
            }

            $transactionId = (int) ($queuedJob['id'] ?? 0);

            if ($transactionId <= 0) {
                Log::warning('Bingwa USSD processor received an invalid queued job payload.', [
                    'user_id' => $userId,
                    'flow_id' => $flowId,
                    'payload' => $queuedJob,
                ]);

                break;
            }

            if (! $this->claimQueuedTransaction($transactionId, $flowId)) {
                continue;
            }

            try {
                $result = $executeBingwaUssd->execute($queuedJob, $flowId);
            } catch (\Throwable $throwable) {
                Log::warning('Bingwa USSD processor bridge execution failed.', [
                    'user_id' => $userId,
                    'flow_id' => $flowId,
                    'transaction_id' => $transactionId,
                    'message' => $throwable->getMessage(),
                ]);

                report($throwable);

                $result = [
                    'success' => false,
                    'message' => $throwable->getMessage(),
                ];
            }

            $this->completeQueuedTransaction($completeBingwaTransaction, $transactionId, $result, $flowId);
            $processed++;

            $message = $result['message'];
            $isModemBusy = $message !== '' && (str_contains(strtolower($message), 'another ussd session is already in progress') || str_contains(strtolower($message), 'modem is busy'));

            if ($isModemBusy) {
                Log::info('Bingwa USSD processor loop aborted due to modem lock contention.', [
                    'user_id' => $userId,
                    'flow_id' => $flowId,
                    'transaction_id' => $transactionId,
                ]);

                break;
            }
        }

        Log::debug('Bingwa USSD processor job finished.', [
            'user_id' => $userId,
            'flow_id' => $flowId,
            'processed' => $processed,
        ]);
    }

    private function claimQueuedTransaction(int $transactionId, string $flowId): bool
    {
        $claimed = Transaction::query()
            ->whereKey($transactionId)
            ->whereIn('status', ['queued', 'failed'])
            ->update([
                'status' => 'processing',
                'status_desc' => __('USSD call in progress.'),
            ]);

        Log::debug('Bingwa USSD processor claim result.', [
            'user_id' => $this->userId(),
            'flow_id' => $flowId,
            'transaction_id' => $transactionId,
            'claimed' => $claimed > 0,
        ]);

        return $claimed > 0;
    }

    /**
     * @param  array{success?: bool, message?: string}  $result
     */
    private function completeQueuedTransaction(
        CompleteBingwaTransaction $completeBingwaTransaction,
        int $transactionId,
        array $result,
        string $flowId,
    ): void {
        $success = (bool) ($result['success'] ?? false);
        $message = trim((string) ($result['message'] ?? ''));

        if (! $success && BingwaUssdResponse::messageIndicatesSuccess($message)) {
            $success = true;
        }

        $statusMessage = $message !== ''
            ? $message
            : ($success ? __('USSD call completed successfully.') : __('USSD call failed.'));

        $completeBingwaTransaction->complete(
            transactionId: $transactionId,
            status: $success ? 'completed' : 'failed',
            message: $statusMessage,
        );

        Log::debug('Bingwa USSD processor completed transaction.', [
            'user_id' => $this->userId(),
            'flow_id' => $flowId,
            'transaction_id' => $transactionId,
            'success' => $success,
            'message' => $statusMessage,
        ]);
    }

    private function userId(): int
    {
        return $this->userId ?? 0;
    }
}
