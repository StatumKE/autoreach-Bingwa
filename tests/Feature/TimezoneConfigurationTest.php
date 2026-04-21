<?php

use App\Models\Transaction;

test('the application uses africa nairobi timezone by default', function () {
    expect(config('app.timezone'))->toBe('Africa/Nairobi');
    expect(now()->timezoneName)->toBe('Africa/Nairobi');
});

test('date casted models inherit the application timezone', function () {
    $transaction = new Transaction;
    $transaction->occurred_at = '2026-04-21 10:00:00';

    expect($transaction->occurred_at?->timezoneName)->toBe('Africa/Nairobi');
});
