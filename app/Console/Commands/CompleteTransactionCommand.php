<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bingwa:complete-transaction {id : The local transaction ID} {status : completed or failed} {--message= : Optional status description}')]
#[Description('Mark a transaction as completed or failed after a USSD execution attempt.')]
class CompleteTransactionCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $status = (string) $this->argument('status');

        if (! in_array($status, ['completed', 'failed'], true)) {
            $this->error("Invalid status '{$status}'. Must be 'completed' or 'failed'.");

            return self::FAILURE;
        }

        $transaction = Transaction::query()->find($id);

        if (! $transaction) {
            $this->warn("Transaction #{$id} not found.");

            return self::SUCCESS;
        }

        $statusDesc = match ($status) {
            'completed' => $this->option('message') ?? __('USSD call completed successfully.'),
            'failed' => $this->option('message') ?? __('USSD call failed.'),
        };

        $transaction->update([
            'status' => $status,
            'status_desc' => $statusDesc,
            'processed_at' => now(),
        ]);

        $user = $transaction->user;
        if ($user) {
            $activePlan = $user->plans()->where('is_active', true)->first();
            if ($activePlan) {
                $activePlan->increment('ussd_counter');

                // Refresh to get the new counter value
                $activePlan->refresh();

                if ($activePlan->type === 'usage_pack' && $activePlan->ussd_requests_included !== null) {
                    if ($activePlan->ussd_counter >= $activePlan->ussd_requests_included) {
                        $activePlan->update(['is_active' => false]);
                        $this->info("Plan '{$activePlan->name}' has been exhausted and deactivated.");
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
