<?php

use App\Jobs\SyncBingwaTransactionsJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

it('calls the sync transactions command', function (): void {
    Queue::fake();

    Artisan::shouldReceive('call')
        ->once()
        ->with('bingwa:sync-transactions', ['--user-id' => 123])
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('');

    (new SyncBingwaTransactionsJob(123))->handle();
});
