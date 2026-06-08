<?php

use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Jobs\SyncBingwaTransactionsJob;

it('scopes sync and processor unique ids to the user', function (): void {
    $syncA = new SyncBingwaTransactionsJob(11);
    $syncB = new SyncBingwaTransactionsJob(42);
    $processorA = new ProcessBingwaQueuedTransactionsJob(11);
    $processorB = new ProcessBingwaQueuedTransactionsJob(42);

    expect($syncA->uniqueId())->toBe('bingwa-sync-transactions:11')
        ->and($syncB->uniqueId())->toBe('bingwa-sync-transactions:42')
        ->and($processorA->uniqueId())->toBe('bingwa-process-queued-transactions:11')
        ->and($processorB->uniqueId())->toBe('bingwa-process-queued-transactions:42');
});
