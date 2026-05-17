<?php

namespace App\Console\Commands;

use App\Actions\Autoreach\DispatchBingwaQueuedTransactionsJob;
use App\Actions\Autoreach\ProcessDueAutoRenewals;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:process-auto-renewals {--user-id= : Restrict processing to a specific user ID}')]
#[Description('Convert due auto-renewal schedules into queued Bingwa transactions.')]
class ProcessDueAutoRenewalsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ProcessDueAutoRenewals $processDueAutoRenewals): int
    {
        $result = $processDueAutoRenewals->process(
            $this->option('user-id') ? (int) $this->option('user-id') : null,
        );

        foreach ($result['users'] as $userId) {
            app(DispatchBingwaQueuedTransactionsJob::class)->dispatch((int) $userId);
        }

        Log::info('Auto-renewal processing command finished.', $result);

        $this->output->write(json_encode([
            'queued' => $result['queued'],
            'rescheduled' => $result['rescheduled'],
            'failed' => $result['failed'],
        ]));

        return self::SUCCESS;
    }
}
