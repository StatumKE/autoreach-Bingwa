<?php

use App\Actions\Autoreach\DispatchBingwaQueuedTransactionsJob;
use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Models\DeviceSetting;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

it('dispatches the queued transaction job when processing is enabled', function (): void {
    Bus::fake();

    $user = User::factory()->create();

    DeviceSetting::factory()->for($user)->create([
        'transaction_processing_enabled' => true,
    ]);

    $dispatched = app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($user->id);

    expect($dispatched)->toBeTrue();
    Bus::assertDispatched(ProcessBingwaQueuedTransactionsJob::class);
});

it('does not dispatch the job when transaction processing is disabled', function (): void {
    Bus::fake();

    $user = User::factory()->create();

    DeviceSetting::factory()->for($user)->create([
        'transaction_processing_enabled' => false,
    ]);

    $dispatched = app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($user->id);

    expect($dispatched)->toBeFalse();
    Bus::assertNotDispatched(ProcessBingwaQueuedTransactionsJob::class);
});

it('does not call nativephp_call for waking the worker (wake is handled in Kotlin)', function (): void {
    Bus::fake();

    $user = User::factory()->create();

    DeviceSetting::factory()->for($user)->create([
        'transaction_processing_enabled' => true,
    ]);

    // The PHP layer no longer calls nativephp_call('WakeQueueWorker').
    // The WAKE_WORKER intent is sent in Kotlin's postCallback() directly to PHPQueueService,
    // which correctly targets the :queue process without going through the PHP bridge.
    $GLOBALS['last_nativephp_call'] = null;

    app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($user->id);

    expect($GLOBALS['last_nativephp_call'])->toBeNull();
});

if (! function_exists('nativephp_call')) {
    function nativephp_call(string $function, ?string $payload = null): string
    {
        $GLOBALS['last_nativephp_call'] = [
            'function' => $function,
            'payload' => $payload,
        ];

        return '{"success":true}';
    }
}
