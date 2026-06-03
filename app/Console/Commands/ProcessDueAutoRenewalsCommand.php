<?php

namespace App\Console\Commands;

use App\Actions\Autoreach\DispatchBingwaQueuedTransactionsJob;
use App\Actions\Autoreach\ProcessDueAutoRenewals;
use App\Services\BingwaDeviceContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:process-auto-renewals')]
#[Description('Convert due auto-renewal schedules into queued Bingwa transactions.')]
class ProcessDueAutoRenewalsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        BingwaDeviceContext $deviceContext,
        ProcessDueAutoRenewals $processDueAutoRenewals,
    ): int {
        $registration = $deviceContext->registration();

        if ($registration === null || $registration->user === null) {
            Log::warning('Auto-renewal processing command skipped because no active device registration was found.');
            $this->warn('No active device registration found.');

            return self::SUCCESS;
        }

        $userId = $registration->user->getKey();

        $result = $processDueAutoRenewals->process($userId);

        app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($userId);

        Log::info('Auto-renewal processing command finished.', $result);

        $this->output->write(json_encode([
            'queued' => $result['queued'],
            'rescheduled' => $result['rescheduled'],
            'failed' => $result['failed'],
        ]));

        return self::SUCCESS;
    }
}
