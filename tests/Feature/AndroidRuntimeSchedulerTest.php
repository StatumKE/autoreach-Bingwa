<?php

use App\Actions\Autoreach\SendBingwaHeartbeat;
use App\Models\BingwaDeviceRegistration;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AndroidRuntimeScheduler;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    Cache::forever(AndroidRuntimeScheduler::transactionSyncKey($user->getKey()), now()->toIso8601String());

    $result = app(AndroidRuntimeScheduler::class)->runDueTasks(now());

    expect($result)
        ->toMatchArray([
            'ran' => true,
            'heartbeat' => true,
            'transaction_sync' => false,
        ]);
});

it('skips heartbeat when heartbeat is not due', function (): void {
    $user = createRuntimeRegisteredUser(now());

    $this->mock(SendBingwaHeartbeat::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    Cache::forever(AndroidRuntimeScheduler::transactionSyncKey($user->getKey()), now()->toIso8601String());

    $result = app(AndroidRuntimeScheduler::class)->runDueTasks(now());

    expect($result['heartbeat'])->toBeFalse();
});

it('runs transaction sync when transaction sync is due', function (): void {
    $user = createRuntimeRegisteredUser(now());

    Log::spy();

    $this->mock(SendBingwaHeartbeat::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    Artisan::shouldReceive('call')
        ->once()
        ->with('bingwa:sync-transactions')
        ->andReturn(0);
    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('');
    Artisan::shouldReceive('call')
        ->once()
        ->with('bingwa:process-auto-renewals')
        ->andReturn(0);

    $result = app(AndroidRuntimeScheduler::class)->runDueTasks(now());

    expect($result['transaction_sync'])->toBeTrue();

    Log::shouldHaveReceived('debug')
        ->with(
            'Bingwa runtime tick started.',
            Mockery::on(fn (array $context): bool => $context['lock_key'] === AndroidRuntimeScheduler::RUN_LOCK_KEY)
        )
        ->once();

    Log::shouldHaveReceived('info')
        ->with(
            'Bingwa runtime tick completed.',
            Mockery::on(fn (array $context): bool => $context['user_id'] === $user->getKey() && $context['transaction_sync'] === true)
        )
        ->once();
});

it('stores the transaction sync throttle per registered user', function (): void {
    $firstUser = createRuntimeRegisteredUser(now());
    $secondUser = createRuntimeRegisteredUser(now());

    Cache::forever(AndroidRuntimeScheduler::transactionSyncKey($firstUser->getKey()), now()->toIso8601String());

    expect(Cache::has(AndroidRuntimeScheduler::transactionSyncKey($firstUser->getKey())))->toBeTrue();
    expect(Cache::has(AndroidRuntimeScheduler::transactionSyncKey($secondUser->getKey())))->toBeFalse();
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
        ->with('bingwa:sync-transactions')
        ->andReturn(0);
    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('');
    Artisan::shouldReceive('call')
        ->once()
        ->with('bingwa:process-auto-renewals')
        ->andReturn(0);

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

it('skips heartbeat when it was seen recently (within 5 minutes) based on injected now', function (): void {
    $now = now();
    $user = createRuntimeRegisteredUser($now->copy()->subMinutes(4));

    $this->mock(SendBingwaHeartbeat::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    Cache::forever(AndroidRuntimeScheduler::transactionSyncKey($user->getKey()), $now->toIso8601String());

    $result = app(AndroidRuntimeScheduler::class)->runDueTasks($now);

    expect($result['heartbeat'])->toBeFalse();
});

it('sends heartbeat when it was seen more than 5 minutes ago based on injected now', function (): void {
    $now = now();
    $user = createRuntimeRegisteredUser($now->copy()->subMinutes(6));

    $this->mock(SendBingwaHeartbeat::class, function (MockInterface $mock) use ($user): void {
        $mock->shouldReceive('send')
            ->once()
            ->with(Mockery::on(fn (User $sentUser): bool => $sentUser->is($user)))
            ->andReturn(true);
    });

    Cache::forever(AndroidRuntimeScheduler::transactionSyncKey($user->getKey()), $now->toIso8601String());

    $result = app(AndroidRuntimeScheduler::class)->runDueTasks($now);

    expect($result['heartbeat'])->toBeTrue();
});

it('skips transaction sync when it was run recently (within 5 minutes) based on injected now', function (): void {
    $now = now();
    $user = createRuntimeRegisteredUser($now);

    $this->mock(SendBingwaHeartbeat::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    Cache::forever(AndroidRuntimeScheduler::transactionSyncKey($user->getKey()), $now->copy()->subMinutes(4)->toIso8601String());

    Artisan::shouldReceive('call')
        ->once()
        ->with('bingwa:process-auto-renewals')
        ->andReturn(0);

    $result = app(AndroidRuntimeScheduler::class)->runDueTasks($now);

    expect($result['transaction_sync'])->toBeFalse();
});

it('runs transaction sync when it was run more than 5 minutes ago based on injected now', function (): void {
    $now = now();
    $user = createRuntimeRegisteredUser($now);

    $this->mock(SendBingwaHeartbeat::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    Cache::forever(AndroidRuntimeScheduler::transactionSyncKey($user->getKey()), $now->copy()->subMinutes(6)->toIso8601String());

    Artisan::shouldReceive('call')
        ->once()
        ->with('bingwa:sync-transactions')
        ->andReturn(0);
    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('');
    Artisan::shouldReceive('call')
        ->once()
        ->with('bingwa:process-auto-renewals')
        ->andReturn(0);

    $result = app(AndroidRuntimeScheduler::class)->runDueTasks($now);

    expect($result['transaction_sync'])->toBeTrue();
});

it('returns a fast next tick when a queued transaction is due at the injected now', function (): void {
    $now = now();
    $user = createRuntimeRegisteredUser($now);

    Transaction::factory()->for($user)->create([
        'status' => 'queued',
        'occurred_at' => $now->copy()->addMinutes(2),
        'next_attempt_at' => $now->copy()->addMinutes(2),
    ]);

    $this->mock(SendBingwaHeartbeat::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    Cache::forever(AndroidRuntimeScheduler::transactionSyncKey($user->getKey()), $now->toIso8601String());

    $result1 = app(AndroidRuntimeScheduler::class)->runDueTasks($now);
    expect($result1['next_tick_seconds'])->toBe(900);

    $result2 = app(AndroidRuntimeScheduler::class)->runDueTasks($now->copy()->addMinutes(3));
    expect($result2['next_tick_seconds'])->toBe(15);
});
