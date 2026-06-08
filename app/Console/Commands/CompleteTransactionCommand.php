<?php

namespace App\Console\Commands;

use App\Actions\Autoreach\CompleteBingwaTransaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

#[Signature('bingwa:complete-transaction {id? : The local transaction ID} {status? : completed or failed} {--transaction-id= : The local transaction ID} {--result= : completed or failed} {--message= : Optional status description} {--message-base64= : Optional base64-encoded status description} {--callback-token= : Unique callback delivery token}')]
#[Description('Mark a transaction as completed or failed after a USSD execution attempt.')]
class CompleteTransactionCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $id = (int) ($this->option('transaction-id') ?? $this->argument('id'));
        $status = (string) ($this->option('result') ?? $this->argument('status'));

        Log::debug('CompleteTransactionCommand started.', [
            'component' => 'transaction_completion',
            'transaction_id' => $id,
            'status' => $status,
            'callback_token' => $this->option('callback-token'),
        ]);

        if ($id <= 0) {
            $this->error('A transaction ID must be provided.');

            return self::FAILURE;
        }

        if (! in_array($status, ['completed', 'failed'], true)) {
            $this->error("Invalid status '{$status}'. Must be 'completed' or 'failed'.");

            return self::FAILURE;
        }

        try {
            $message = $this->resolveMessageOption();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $completed = app(CompleteBingwaTransaction::class)->complete(
            transactionId: $id,
            status: $status,
            message: $message,
            callbackToken: $this->option('callback-token'),
        );

        if (! $completed) {
            $this->warn("Transaction #{$id} not found.");
        }

        Log::debug('CompleteTransactionCommand finished.', [
            'component' => 'transaction_completion',
            'transaction_id' => $id,
            'status' => $status,
            'completed' => $completed,
        ]);

        return self::SUCCESS;
    }

    private function resolveMessageOption(): ?string
    {
        $encodedMessage = $this->option('message-base64');

        if (is_string($encodedMessage)) {
            $decodedMessage = base64_decode($encodedMessage, true);

            if ($decodedMessage === false) {
                throw new InvalidArgumentException('The --message-base64 option must contain valid base64.');
            }

            return $decodedMessage;
        }

        $message = $this->option('message');

        return is_string($message) ? $message : null;
    }
}
