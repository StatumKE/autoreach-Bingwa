<?php

use App\Actions\Autoreach\UssdModemLock;
use App\Exceptions\UssdModemBusyException;
use Illuminate\Support\Facades\Cache;

it('serializes ussd work behind the shared modem lock', function (): void {
    $lock = Cache::lock(UssdModemLock::KEY, 10);

    expect($lock->get())->toBeTrue();

    try {
        app(UssdModemLock::class)->run(
            callback: fn (): string => 'should-not-run',
            operation: 'test-contention',
        );
    } finally {
        $lock->release();
    }
})->throws(UssdModemBusyException::class, 'Another USSD session is already in progress');

it('releases the shared modem lock after ussd work throws', function (): void {
    try {
        app(UssdModemLock::class)->run(
            callback: fn (): never => throw new RuntimeException('bridge failed'),
            operation: 'test-exception',
        );
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('bridge failed');
    }

    $result = app(UssdModemLock::class)->run(
        callback: fn (): string => 'next-request',
        operation: 'test-release',
    );

    expect($result)->toBe('next-request');
});
