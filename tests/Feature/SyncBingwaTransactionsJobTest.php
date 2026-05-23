<?php

use App\Jobs\SyncBingwaTransactionsJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

it('calls the sync transactions command', function (): void {
    Queue::fake();
    Log::spy();

    Artisan::shouldReceive('call')
        ->once()
        ->with('bingwa:sync-transactions', ['--user-id' => 123])
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('');

    (new SyncBingwaTransactionsJob(123))->handle();

    Log::shouldHaveReceived('debug')
        ->with(
            'Bingwa transaction sync job started.',
            Mockery::on(fn (array $context): bool => $context['user_id'] === 123)
        )
        ->once();

    Log::shouldHaveReceived('debug')
        ->with(
            'Bingwa transaction sync job invoking artisan command.',
            Mockery::on(fn (array $context): bool => $context['command'] === 'bingwa:sync-transactions')
        )
        ->once();

    Log::shouldHaveReceived('debug')
        ->with(
            'Bingwa transaction sync job finished.',
            Mockery::on(fn (array $context): bool => $context['artisan_exit_code'] === 0)
        )
        ->once();
});
