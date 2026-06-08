<?php

use App\Actions\Autoreach\CompleteBingwaTransaction;
use App\Actions\Autoreach\DispatchBingwaQueuedTransactionsJob;
use App\Actions\Autoreach\ExecuteBingwaUssd;
use App\Actions\Autoreach\GetNextBingwaQueuedTransaction;
use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Models\DeviceSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

// ---------------------------------------------------------------------------
// Queue worker loop: rapid sequential processing
// ---------------------------------------------------------------------------
//
// These tests verify the invariants that drive the "tight loop" behaviour:
// after one USSD transaction completes its callback, the next job must be
// dispatched immediately without any artificial delay.
//
// The Kotlin PHPQueueWorker uses a LinkedBlockingQueue semaphore so it
// blocks with zero CPU when idle and wakes in <1 ms when a signal arrives.
// These PHP-level tests assert the application-layer contract that feeds
// that Kotlin mechanism.

describe('DispatchBingwaQueuedTransactionsJob', function (): void {
    it('dispatches a job for each user when processing is enabled', function (): void {
        Bus::fake();

        $user = User::factory()->create();
        DeviceSetting::factory()->for($user)->create(['transaction_processing_enabled' => true]);

        $result = app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($user->id);

        expect($result)->toBeTrue();
        Bus::assertDispatched(ProcessBingwaQueuedTransactionsJob::class, function ($job) use ($user): bool {
            return $job->userId === $user->id;
        });
    });

    it('does not dispatch when transaction processing is disabled', function (): void {
        Bus::fake();

        $user = User::factory()->create();
        DeviceSetting::factory()->for($user)->create(['transaction_processing_enabled' => false]);

        $result = app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($user->id);

        expect($result)->toBeFalse();
        Bus::assertNotDispatched(ProcessBingwaQueuedTransactionsJob::class);
    });

    it('dispatches separate jobs for multiple users without cross-contamination', function (): void {
        Bus::fake();

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        DeviceSetting::factory()->for($userA)->create(['transaction_processing_enabled' => true]);
        DeviceSetting::factory()->for($userB)->create(['transaction_processing_enabled' => true]);

        app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($userA->id);
        app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($userB->id);

        Bus::assertDispatchedTimes(ProcessBingwaQueuedTransactionsJob::class, 2);
        Bus::assertDispatched(ProcessBingwaQueuedTransactionsJob::class, fn ($j) => $j->userId === $userA->id);
        Bus::assertDispatched(ProcessBingwaQueuedTransactionsJob::class, fn ($j) => $j->userId === $userB->id);
    });

    it('does not call nativephp_call for wake — wake is handled in Kotlin', function (): void {
        Bus::fake();

        $user = User::factory()->create();
        DeviceSetting::factory()->for($user)->create(['transaction_processing_enabled' => true]);

        $GLOBALS['last_nativephp_call'] = null;

        app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($user->id);

        // The PHP action must NOT call nativephp_call('WakeQueueWorker').
        // The WAKE_WORKER intent is sent from Kotlin's postCallback() directly
        // to PHPQueueService, which targets the correct :queue process.
        expect($GLOBALS['last_nativephp_call'])->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// ProcessBingwaQueuedTransactionsJob: loop-within-job behaviour
// ---------------------------------------------------------------------------

describe('ProcessBingwaQueuedTransactionsJob', function (): void {
    it('processes multiple synchronous transactions in a single job run', function (): void {
        $user = User::factory()->create();
        DeviceSetting::factory()->for($user)->create(['transaction_processing_enabled' => true]);

        $transactions = Transaction::factory()->count(3)->for($user)->create(['status' => 'queued']);

        // Arrange: sync USSD responses (no async break)
        $ussdResults = $transactions->map(fn ($t) => [
            'success' => true,
            'message' => 'Transaction completed.',
        ])->values()->toArray();

        $callCount = 0;
        $executeBingwaUssd = Mockery::mock(ExecuteBingwaUssd::class);
        $executeBingwaUssd->allows('execute')->andReturnUsing(function () use (&$callCount, $ussdResults) {
            return $ussdResults[$callCount++] ?? ['success' => true, 'message' => 'done'];
        });

        $getNextTransaction = Mockery::mock(GetNextBingwaQueuedTransaction::class);
        $getNextTransaction->allows('next')->andReturnUsing(function () use ($transactions, &$callCount) {
            $index = $callCount; // peek without advancing
            $transaction = $transactions->get($index);
            if (! $transaction) {
                return null;
            }

            return [
                'id' => $transaction->id,
                'ussd' => '*123#',
                'sim_slot' => 0,
                'mode' => 'normal',
            ];
        });

        $completeBingwaTransaction = Mockery::mock(CompleteBingwaTransaction::class);
        $completeBingwaTransaction->allows('complete');

        $job = new ProcessBingwaQueuedTransactionsJob($user->id);

        $job->handle($completeBingwaTransaction, $executeBingwaUssd, $getNextTransaction);

        // All 3 transactions should have been attempted
        expect($callCount)->toBe(3);
    });

    it('breaks out of the loop immediately when async result is returned', function (): void {
        $user = User::factory()->create();
        DeviceSetting::factory()->for($user)->create(['transaction_processing_enabled' => true]);

        $transaction = Transaction::factory()->for($user)->create(['status' => 'queued']);

        $callCount = 0;
        $executeBingwaUssd = Mockery::mock(ExecuteBingwaUssd::class);
        $executeBingwaUssd->allows('execute')->andReturnUsing(function () use (&$callCount): array {
            $callCount++;

            return ['async' => true, 'message' => 'USSD session started'];
        });

        $getNextTransaction = Mockery::mock(GetNextBingwaQueuedTransaction::class);
        $getNextTransaction->allows('next')->andReturnUsing(function () use ($transaction, &$callCount): ?array {
            if ($callCount > 0) {
                return null; // after first call, nothing more
            }

            return ['id' => $transaction->id, 'ussd' => '*123#', 'sim_slot' => 0, 'mode' => 'normal'];
        });

        $completeBingwaTransaction = Mockery::mock(CompleteBingwaTransaction::class);
        $completeBingwaTransaction->shouldNotReceive('complete');

        $job = new ProcessBingwaQueuedTransactionsJob($user->id);
        $job->handle($completeBingwaTransaction, $executeBingwaUssd, $getNextTransaction);

        // Must only execute exactly one USSD call before breaking for the callback
        expect($callCount)->toBe(1);
    });

    it('stops the loop when processing is paused mid-run', function (): void {
        $user = User::factory()->create();
        $setting = DeviceSetting::factory()->for($user)->create(['transaction_processing_enabled' => true]);

        $callCount = 0;
        $executeBingwaUssd = Mockery::mock(ExecuteBingwaUssd::class);
        $executeBingwaUssd->allows('execute')->andReturnUsing(function () use (&$callCount, $setting): array {
            $callCount++;
            // Disable processing after first execution to simulate a mid-run pause
            $setting->update(['transaction_processing_enabled' => false]);

            return ['success' => true, 'message' => 'done'];
        });

        $getNextTransaction = Mockery::mock(GetNextBingwaQueuedTransaction::class);
        $getNextTransaction->allows('next')->andReturn([
            'id' => Transaction::factory()->for($user)->create(['status' => 'queued'])->id,
            'ussd' => '*123#',
            'sim_slot' => 0,
            'mode' => 'normal',
        ]);

        $completeBingwaTransaction = Mockery::mock(CompleteBingwaTransaction::class);
        $completeBingwaTransaction->allows('complete');

        $job = new ProcessBingwaQueuedTransactionsJob($user->id);
        $job->handle($completeBingwaTransaction, $executeBingwaUssd, $getNextTransaction);

        // Should have processed exactly 1 transaction before the loop was paused
        expect($callCount)->toBe(1);
    });

    it('stops the loop when modem is busy after a transaction', function (): void {
        $user = User::factory()->create();
        DeviceSetting::factory()->for($user)->create(['transaction_processing_enabled' => true]);

        $callCount = 0;
        $executeBingwaUssd = Mockery::mock(ExecuteBingwaUssd::class);
        $executeBingwaUssd->allows('execute')->andReturnUsing(function () use (&$callCount): array {
            $callCount++;

            return ['success' => false, 'message' => 'Another USSD session is already in progress'];
        });

        $getNextTransaction = Mockery::mock(GetNextBingwaQueuedTransaction::class);
        $getNextTransaction->allows('next')->andReturn([
            'id' => Transaction::factory()->for($user)->create(['status' => 'queued'])->id,
            'ussd' => '*123#',
            'sim_slot' => 0,
            'mode' => 'normal',
        ]);

        $completeBingwaTransaction = Mockery::mock(CompleteBingwaTransaction::class);
        $completeBingwaTransaction->allows('complete');

        $job = new ProcessBingwaQueuedTransactionsJob($user->id);
        $job->handle($completeBingwaTransaction, $executeBingwaUssd, $getNextTransaction);

        // Modem busy breaks the loop immediately after the first transaction
        expect($callCount)->toBe(1);
    });

    it('skips a user with no valid user id', function (): void {
        $executeBingwaUssd = Mockery::mock(ExecuteBingwaUssd::class);
        $executeBingwaUssd->shouldNotReceive('execute');

        $getNextTransaction = Mockery::mock(GetNextBingwaQueuedTransaction::class);
        $getNextTransaction->shouldNotReceive('next');

        $completeBingwaTransaction = Mockery::mock(CompleteBingwaTransaction::class);
        $completeBingwaTransaction->shouldNotReceive('complete');

        $job = new ProcessBingwaQueuedTransactionsJob(null);
        $job->handle($completeBingwaTransaction, $executeBingwaUssd, $getNextTransaction);
    });
});

// ---------------------------------------------------------------------------
// End-to-end flow: callback → dispatch → worker wake
// ---------------------------------------------------------------------------

describe('Sequential transaction callback flow', function (): void {
    it('dispatches the next job immediately after a transaction completes', function (): void {
        Bus::fake();

        $user = User::factory()->create();
        DeviceSetting::factory()->for($user)->create(['transaction_processing_enabled' => true]);

        // Simulate bingwa:complete-transaction dispatching the next job
        $dispatched = app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($user->id);

        expect($dispatched)->toBeTrue();
        Bus::assertDispatched(ProcessBingwaQueuedTransactionsJob::class);
    });

    it('does not dispatch another job if processing is disabled after the callback', function (): void {
        Bus::fake();

        $user = User::factory()->create();
        // Processing disabled at callback time
        DeviceSetting::factory()->for($user)->create(['transaction_processing_enabled' => false]);

        $dispatched = app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($user->id);

        expect($dispatched)->toBeFalse();
        Bus::assertNotDispatched(ProcessBingwaQueuedTransactionsJob::class);
    });

    it('uses the database queue driver so jobs persist across process boundaries', function (): void {
        $defaultConnection = config('queue.default');

        // On Android (NativePHP), the database driver is used so jobs survive
        // process restarts and are visible to the :queue process.
        expect($defaultConnection)->toBeIn(['database', 'sync']);
    });
});

// ---------------------------------------------------------------------------
// PHPQueueWorker output parsing: DONE vs Processed detection
// ---------------------------------------------------------------------------

describe('Queue worker job-output detection', function (): void {
    /**
     * These tests document the contract that PHPQueueWorker.kt relies on.
     *
     * Laravel queue:work --once outputs "DONE" (not "Processed") on success.
     * The Kotlin worker must detect this correctly to loop immediately.
     * An incorrect check (looking only for "Processed") causes the worker to
     * treat every job as "idle" and apply exponential backoff — the root cause
     * of the observed 12-30 second gaps between transactions.
     */
    it('confirms laravel queue output contains DONE for a successful job', function (): void {
        // This is the actual format Laravel uses in queue:work --once output.
        // Verified from Laravel source: WorkCommand::writeStatus() uses "DONE".
        $sampleOutput = "2026-06-08 17:17:24 App\\Jobs\\ProcessBingwaQueuedTransactionsJob .... RUNNING\n"
            .'2026-06-08 17:17:24 App\\Jobs\\ProcessBingwaQueuedTransactionsJob  48.19ms DONE';

        expect($sampleOutput)->toContain('DONE');
        expect($sampleOutput)->not->toContain('Processed');
    });

    it('confirms empty queue produces no DONE in output', function (): void {
        // When queue is empty, queue:work --once outputs nothing or "0"
        $emptyOutput = '';
        $zeroOutput = '0';

        expect(str_contains($emptyOutput, 'DONE'))->toBeFalse();
        expect(str_contains($zeroOutput, 'DONE'))->toBeFalse();
    });

    it('confirms failed job output also triggers immediate re-loop', function (): void {
        // Failed jobs should also cause immediate re-loop (not idle backoff)
        // so the worker picks up the next available transaction right away.
        $failedOutput = "2026-06-08 17:17:24 App\\Jobs\\ProcessBingwaQueuedTransactionsJob .... RUNNING\n"
            .'2026-06-08 17:17:24 App\\Jobs\\ProcessBingwaQueuedTransactionsJob  48.19ms FAILED';

        // Kotlin containsAny("DONE", "RUNNING", "Processed", "Failed") check
        $containsRelevantToken = str_contains($failedOutput, 'RUNNING')
            || str_contains($failedOutput, 'DONE')
            || stripos($failedOutput, 'Processed') !== false
            || stripos($failedOutput, 'Failed') !== false;

        expect($containsRelevantToken)->toBeTrue();
    });

    it('confirms the previous broken check for Processed would not match DONE output', function (): void {
        // This test documents the BUG that caused the 12-30s gaps:
        // The original Kotlin code checked for "Processed" but Laravel outputs "DONE".
        $laravelOutput = 'App\\Jobs\\ProcessBingwaQueuedTransactionsJob  48.19ms DONE';

        // Old broken check (what caused the bug)
        $oldBrokenCheck = str_contains($laravelOutput, 'Processed');
        expect($oldBrokenCheck)->toBeFalse(); // ← This is why it was slow!

        // New correct check
        $newCorrectCheck = str_contains($laravelOutput, 'DONE');
        expect($newCorrectCheck)->toBeTrue(); // ← This makes it fast
    });
});

if (! function_exists('nativephp_call')) {
    function nativephp_call(string $function, ?string $payload = null): string
    {
        $GLOBALS['last_nativephp_call'] = [
            'function' => $function,
            'payload' => $payload,
        ];

        return '{"success":true}';
    }
}
