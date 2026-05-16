<?php

namespace App\Jobs;

use App\Actions\Autoreach\ExecuteBingwaUssd;
use App\Actions\Autoreach\GetNextBingwaQueuedTransaction;
use App\Models\Transaction;
use App\Support\BingwaUssdResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessBingwaQueuedTransactionsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public ?string $flowId = null,
    ) {}

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function uniqueId(): string
    {
        return 'bingwa-process-queued-transactions:'.$this->userId;
    }

    public function handle(
        ExecuteBingwaUssd $executeBingwaUssd,
        GetNextBingwaQueuedTransaction $getNextBingwaQueuedTransaction,
    ): void {
        $flowId = $this->flowId ??= (string) Str::uuid();

        Log::debug('Bingwa USSD processor job started.', [
            'user_id' => $this->userId,
            'flow_id' => $flowId,
            'attempt' => $this->attempts(),
            'tries' => $this->tries,
        ]);

        $processed = 0;
        $deadline = now()->addSeconds(45);

        while (now()->lessThan($deadline)) {
            $queuedJob = $getNextBingwaQueuedTransaction->next($this->userId);

            if ($queuedJob === null) {
                Log::debug('Bingwa USSD processor found no queued transaction.', [
                    'user_id' => $this->userId,
                    'flow_id' => $flowId,
                ]);

                break;
            }

            if (isset($queuedJob['skip'])) {
                Log::debug('Bingwa USSD processor skipped a transaction while loading the next payload.', [
                    'user_id' => $this->userId,
                    'flow_id' => $flowId,
                    'transaction_id' => $queuedJob['id'] ?? null,
                ]);

                continue;
            }

            $transactionId = (int) ($queuedJob['id'] ?? 0);

            if ($transactionId <= 0) {
                Log::warning('Bingwa USSD processor received an invalid queued job payload.', [
                    'user_id' => $this->userId,
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
                    'user_id' => $this->userId,
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

            $this->completeQueuedTransaction($transactionId, $result, $flowId);
            $processed++;
        }

        Log::debug('Bingwa USSD processor job finished.', [
            'user_id' => $this->userId,
            'flow_id' => $flowId,
            'processed' => $processed,
        ]);
    }

    private function claimQueuedTransaction(int $transactionId, string $flowId): bool
    {
        $claimed = Transaction::query()
            ->whereKey($transactionId)
            ->where('status', 'queued')
            ->update([
                'status' => 'processing',
                'status_desc' => __('USSD call in progress.'),
            ]);

        Log::debug('Bingwa USSD processor claim result.', [
            'user_id' => $this->userId,
            'flow_id' => $flowId,
            'transaction_id' => $transactionId,
            'claimed' => $claimed > 0,
        ]);

        return $claimed > 0;
    }

    /**
     * @param  array{success?: bool, message?: string}  $result
     */
    private function completeQueuedTransaction(int $transactionId, array $result, string $flowId): void
    {
        $success = (bool) ($result['success'] ?? false);
        $message = trim((string) ($result['message'] ?? ''));

        if (! $success && BingwaUssdResponse::messageIndicatesSuccess($message)) {
            $success = true;
        }

        Artisan::call('bingwa:complete-transaction', [
            '--transaction-id' => $transactionId,
            '--result' => $success ? 'completed' : 'failed',
            '--message-base64' => base64_encode($message !== '' ? $message : ($success ? __('USSD call completed successfully.') : __('USSD call failed.'))),
            '--finalize-once' => true,
        ]);

        Log::debug('Bingwa USSD processor completed transaction.', [
            'user_id' => $this->userId,
            'flow_id' => $flowId,
            'transaction_id' => $transactionId,
            'success' => $success,
            'message' => $message,
            'artisan_output' => Artisan::output(),
        ]);
    }
}
