<?php

use App\Models\Transaction;
use App\Support\AppTimezone;
use Carbon\Carbon;

test('the application uses africa nairobi timezone by default', function () {
    expect(config('app.timezone'))->toBe('Africa/Nairobi');
    expect(now()->timezoneName)->toBe('Africa/Nairobi');
});

test('date casted models inherit the application timezone', function () {
    $transaction = new Transaction;
    $transaction->occurred_at = '2026-04-21 10:00:00';

    expect($transaction->occurred_at?->timezoneName)->toBe('Africa/Nairobi');
});

test('the shared timezone formatter normalizes timestamps to africa nairobi', function () {
    $value = Carbon::parse('2026-04-21 07:00:00', 'UTC');

    expect(AppTimezone::format($value))->toBe('2026-04-21 10:00:00');
    expect(AppTimezone::format($value, 'H:i, M j'))->toBe('10:00, Apr 21');
});
