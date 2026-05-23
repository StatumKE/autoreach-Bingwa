<?php

use App\Actions\Autoreach\ExecuteBingwaUssd;
use App\Actions\Autoreach\UssdModemLock;
use Mockery\MockInterface;

it('forwards configured timeout seconds to the native ussd bridge payload', function (): void {
    $payload = [
        'id' => 123,
        'backend_transaction_id' => 'TX-TIMEOUT',
        'code' => '*180#',
        'mode' => 'express',
        'sim_slot' => 1,
        'timeout' => 47,
    ];

    $this->mock(UssdModemLock::class, function (MockInterface $mock): void {
        $mock->shouldReceive('run')
            ->once()
            ->withArgs(function (Closure $callback, string $operation, int $waitSeconds, int $leaseSeconds): bool {
                $rawResponse = $callback();

                return $operation === 'queued-transaction'
                    && $waitSeconds === 2
                    && $leaseSeconds === 62
                    && is_string($rawResponse);
            })
            ->andReturn('{"success":true,"message":"Recommendation submitted successfully."}');
    });

    $result = app(ExecuteBingwaUssd::class)->execute($payload);

    expect($result['success'])->toBeTrue();
    expect($GLOBALS['last_nativephp_call']['function'])->toBe('ExecuteUssd');
    expect(json_decode($GLOBALS['last_nativephp_call']['payload'], true))->toMatchArray([
        'code' => '*180#',
        'mode' => 'express',
        'simSlot' => 1,
        'timeoutSeconds' => 47,
    ]);
});

if (! function_exists('nativephp_call')) {
    function nativephp_call(string $function, ?string $payload = null): string
    {
        $GLOBALS['last_nativephp_call'] = [
            'function' => $function,
            'payload' => $payload,
        ];

        return '{"success":true,"message":"Recommendation submitted successfully."}';
    }
}
