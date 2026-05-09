<?php

use App\Actions\Autoreach\SendBingwaHeartbeat;
use App\Models\BingwaDeviceRegistration;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AndroidRuntimeScheduler;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

beforeEach(function (): void {
    Cache::flush();
});

function createRuntimeRegisteredUser(?CarbonInterface $lastSeenAt = null): User
{
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->getKey(),
        'hardware_id' => 'HW-'.fake()->unique()->numerify('######'),
        'device_token' => 'raw-device-token',
        'backend_device_id' => 42,
        'last_seen_at' => $lastSeenAt,
        'status' => null,
    ]);

    return $user;
}

it('dispatches heartbeat when heartbeat is due', function (): void {
    $user = createRuntimeRegisteredUser(now()->subMinutes(10));

    $this->mock(SendBingwaHeartbeat::class, function (MockInterface $mock) use ($user): void {
        $mock->shouldReceive('send')
            ->once()
            ->with(Mockery::on(fn (User $sentUser): bool => $sentUser->is($user)))
            ->andReturn(true);
    });

    Cache::forever(AndroidRuntimeScheduler::TRANSACTION_SYNC_KEY, now()->toIso8601String());

    $result = app(AndroidRuntimeScheduler::class)->runDueTasks(now());

    expect($result)
        ->toMatchArray([
            'ran' => true,
            'heartbeat' => true,
            'transaction_sync' => false,
        ]);
});

it('skips heartbeat when heartbeat is not due', function (): void {
    createRuntimeRegisteredUser(now());

    $this->mock(SendBingwaHeartbeat::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    Cache::forever(AndroidRuntimeScheduler::TRANSACTION_SYNC_KEY, now()->toIso8601String());

    $result = app(AndroidRuntimeScheduler::class)->runDueTasks(now());

    expect($result['heartbeat'])->toBeFalse();
});

it('runs transaction sync when transaction sync is due', function (): void {
    $user = createRuntimeRegisteredUser(now());

    $this->mock(SendBingwaHeartbeat::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    Artisan::shouldReceive('call')
        ->once()
        ->with('bingwa:sync-transactions', ['--user-id' => $user->getKey()])
        ->andReturn(0);
    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('{"synced":0,"skipped":0,"failed":0}');

    $result = app(AndroidRuntimeScheduler::class)->runDueTasks(now());

    expect($result['transaction_sync'])->toBeTrue();
});

it('returns a fast next tick while queued transactions exist', function (): void {
    $user = createRuntimeRegisteredUser(now());

    Transaction::factory()->for($user)->create([
        'status' => 'queued',
    ]);

    $this->mock(SendBingwaHeartbeat::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    Artisan::shouldReceive('call')
        ->once()
        ->with('bingwa:sync-transactions', ['--user-id' => $user->getKey()])
        ->andReturn(0);
    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('{"synced":0,"skipped":0,"failed":0}');

    $result = app(AndroidRuntimeScheduler::class)->runDueTasks(now());

    expect($result['next_tick_seconds'])->toBe(15);
});

it('does not overlap runtime scheduler executions', function (): void {
    $lock = Cache::lock(AndroidRuntimeScheduler::RUN_LOCK_KEY, 10);
    $lock->get();

    try {
        $result = app(AndroidRuntimeScheduler::class)->runDueTasks(now());

        expect($result)
            ->toMatchArray([
                'ran' => false,
                'heartbeat' => false,
                'transaction_sync' => false,
                'next_tick_seconds' => 60,
            ]);
    } finally {
        $lock->release();
    }
});
