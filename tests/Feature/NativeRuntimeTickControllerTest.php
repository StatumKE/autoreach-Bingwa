<?php

use App\Services\AndroidRuntimeScheduler;
use Illuminate\Support\Facades\Log;

it('accepts loopback android runtime ticks and returns scheduled task state', function (): void {
    Log::spy();

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

    Log::shouldHaveReceived('debug')
        ->with(
            'Bingwa runtime tick request received.',
            Mockery::on(fn (array $context): bool => $context['loopback'] === true && $context['runtime_header'] === 'android')
        )
        ->once();

    Log::shouldHaveReceived('debug')
        ->with(
            'Bingwa runtime tick request accepted.',
            Mockery::on(fn (array $context): bool => $context['runtime_header'] === 'android' && $context['host'] === '127.0.0.1')
        )
        ->once();
});

it('rejects non-loopback runtime tick requests', function (): void {
    Log::spy();

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.2.15',
        'HTTP_HOST' => 'autoreachbingwaapp.test',
    ])->postJson('http://autoreachbingwaapp.test/api/v1/native/runtime/tick', [], [
        'X-Bingwa-Runtime' => 'android',
    ])->assertForbidden()->assertJson([
        'status' => 'forbidden',
    ]);

    Log::shouldHaveReceived('debug')
        ->with(
            'Bingwa runtime tick request rejected.',
            Mockery::on(fn (array $context): bool => $context['loopback'] === false && $context['runtime_header'] === 'android')
        )
        ->once();
});

it('rejects runtime tick requests without the android runtime header', function (): void {
    Log::spy();

    $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => '127.0.0.1',
    ])->postJson('/api/v1/native/runtime/tick')->assertForbidden()->assertJson([
        'status' => 'forbidden',
    ]);

    Log::shouldHaveReceived('debug')
        ->with(
            'Bingwa runtime tick request rejected.',
            Mockery::on(fn (array $context): bool => $context['loopback'] === true && $context['runtime_header'] === null)
        )
        ->once();
});
