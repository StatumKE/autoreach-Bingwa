<?php

use App\Services\AndroidRuntimeScheduler;

it('accepts loopback android runtime ticks and returns scheduled task state', function (): void {
    $scheduler = Mockery::mock(AndroidRuntimeScheduler::class);
    $scheduler->shouldReceive('runDueTasks')->once()->andReturn([
        'ran' => true,
        'heartbeat' => true,
        'transaction_sync' => true,
        'next_tick_seconds' => 60,
    ]);

    app()->instance(AndroidRuntimeScheduler::class, $scheduler);

    $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => '127.0.0.1',
    ])->postJson('/api/v1/native/runtime/tick', [], [
        'X-Bingwa-Runtime' => 'android',
    ])->assertSuccessful()->assertJson([
        'status' => 'ok',
        'tasks' => [
            'ran' => true,
            'heartbeat' => true,
            'transaction_sync' => true,
            'next_tick_seconds' => 60,
        ],
    ]);
});

it('rejects non-loopback runtime tick requests', function (): void {
    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.2.15',
        'HTTP_HOST' => 'autoreachbingwaapp.test',
    ])->postJson('/api/v1/native/runtime/tick', [], [
        'X-Bingwa-Runtime' => 'android',
    ])->assertForbidden()->assertJson([
        'status' => 'forbidden',
    ]);
});

it('rejects runtime tick requests without the android runtime header', function (): void {
    $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => '127.0.0.1',
    ])->postJson('/api/v1/native/runtime/tick')->assertForbidden()->assertJson([
        'status' => 'forbidden',
    ]);
});
