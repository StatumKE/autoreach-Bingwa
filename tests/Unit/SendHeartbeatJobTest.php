<?php

use App\Jobs\SendHeartbeatJob;

test('heartbeat job uniqueness is scoped to the user id', function (): void {
    $firstJob = new SendHeartbeatJob(11);
    $secondJob = new SendHeartbeatJob(42);

    expect($firstJob->uniqueId())->toBe('bingwa-send-heartbeat:11')
        ->and($secondJob->uniqueId())->toBe('bingwa-send-heartbeat:42');
});
