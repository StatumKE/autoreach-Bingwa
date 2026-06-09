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

it('calls nativephp_call to wake the worker immediately when a job is queued', function (): void {
    // We do NOT fake the Bus here because we want the JobQueued event to fire.
    // Bus::fake() intercepts the job before it hits the queue, preventing the event.

    $user = User::factory()->create();

    DeviceSetting::factory()->for($user)->create([
        'transaction_processing_enabled' => true,
    ]);

    // The JobQueued event listener in AppServiceProvider calls nativephp_call('WakeQueueWorker').
    $GLOBALS['last_nativephp_call'] = null;

    app(DispatchBingwaQueuedTransactionsJob::class)->dispatch($user->id);

    expect($GLOBALS['last_nativephp_call'])->not->toBeNull();
    expect($GLOBALS['last_nativephp_call']['function'])->toBe('WakeQueueWorker');
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
