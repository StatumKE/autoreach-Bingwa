<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bingwa:claim-ussd-job {--id= : The local transaction ID}')]
#[Description('Atomically claim a queued transaction for USSD execution.')]
class ClaimUssdJobCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $id = (int) $this->option('id');

        if ($id <= 0) {
            $this->error('Missing or invalid --id option.');

            return self::FAILURE;
        }

        $claimed = Transaction::query()
            ->whereKey($id)
            ->where('status', 'queued')
            ->update([
                'status' => 'processing',
                'status_desc' => __('USSD call in progress.'),
            ]);

        $this->line(json_encode([
            'claimed' => $claimed > 0,
            'id' => $id,
        ]));

        return self::SUCCESS;
    }
}
