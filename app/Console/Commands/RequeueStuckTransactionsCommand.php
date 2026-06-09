<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:requeue-stuck-transactions {--all : Requeue all processing transactions immediately.}')]
#[Description('Requeue stuck Bingwa transactions so the Android USSD worker can pick them up again.')]
class RequeueStuckTransactionsCommand extends Command
{
    private const STALE_THRESHOLD_MINUTES = 2;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Log::debug('RequeueStuckTransactionsCommand started.', [
            'component' => 'transaction_requeue',
            'requeue_all' => (bool) $this->option('all'),
        ]);

        $query = Transaction::query()->where('status', 'processing');

        if (! $this->option('all')) {
            $query->where('updated_at', '<=', now()->subMinutes(self::STALE_THRESHOLD_MINUTES));
        }

        $count = $query->update([
            'status' => 'queued',
            'status_desc' => __('Recovered: requeued for Android USSD processing.'),
            'processed_at' => null,
        ]);

        if ($count > 0) {
            Log::warning("♻️ Requeued {$count} stuck transactions.");
        }

        Log::debug('RequeueStuckTransactionsCommand completed.', [
            'component' => 'transaction_requeue',
            'requeued' => $count,
            'requeue_all' => (bool) $this->option('all'),
        ]);

        $this->info("Requeued {$count} transaction(s).");

        return self::SUCCESS;
    }
}
