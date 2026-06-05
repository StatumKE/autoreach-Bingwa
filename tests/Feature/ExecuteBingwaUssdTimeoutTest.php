<?php

use App\Actions\Autoreach\ExecuteBingwaUssd;

it('forwards configured timeout seconds to the native ussd bridge payload', function (): void {
    $payload = [
        'id' => 123,
        'backend_transaction_id' => 'TX-TIMEOUT',
        'code' => '*180#',
        'mode' => 'express',
        'sim_slot' => 1,
        'timeout' => 47,
    ];

    $result = app(ExecuteBingwaUssd::class)->execute($payload);

    expect($result['success'])->toBeTrue();
    expect($result['async'])->toBeTrue();
    expect($GLOBALS['last_nativephp_call']['function'])->toBe('ExecuteUssd');
    expect(json_decode($GLOBALS['last_nativephp_call']['payload'], true))->toMatchArray([
        'id' => 123,
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
