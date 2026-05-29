<?php

use App\Actions\Autoreach\FetchNextBingwaJobs;
use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Jobs\SyncBingwaTransactionsJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

it('fetches jobs and dispatches the queued processor when synced transactions exist', function (): void {
    Queue::fake();
    Bus::fake();
    Log::spy();

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->mock(FetchNextBingwaJobs::class, function (MockInterface $mock) use ($user): void {
        $mock->shouldReceive('sync')
            ->once()
            ->withArgs(fn (User $arg, int $limit, ?string $onlyService): bool => $arg->id === $user->id && $limit === 10 && $onlyService === null)
            ->andReturn(['synced' => 1, 'skipped' => 0, 'failed' => 0]);
    });

    app()->call([(new SyncBingwaTransactionsJob), 'handle']);

    Bus::assertDispatchedSync(ProcessBingwaQueuedTransactionsJob::class);

    Log::shouldHaveReceived('debug')
        ->with(
            'Bingwa transaction sync job started.',
            Mockery::on(fn (array $ctx): bool => $ctx['user_id'] === $user->id)
        )
        ->once();
});

it('skips dispatch when there are no synced or queued transactions', function (): void {
    Queue::fake();
    Bus::fake();

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->mock(FetchNextBingwaJobs::class, function (MockInterface $mock) use ($user): void {
        $mock->shouldReceive('sync')
            ->once()
            ->withArgs(fn (User $arg, int $limit, ?string $onlyService): bool => $arg->id === $user->id && $limit === 10 && $onlyService === null)
            ->andReturn(['synced' => 0, 'skipped' => 0, 'failed' => 0]);
    });

    expect(Transaction::query()->where('status', 'queued')->exists())->toBeFalse();

    app()->call([(new SyncBingwaTransactionsJob), 'handle']);

    Bus::assertNotDispatchedSync(ProcessBingwaQueuedTransactionsJob::class);
});

it('skips the sync when user has no bingwa device registration', function (): void {
    Queue::fake();
    Log::spy();

    $user = User::factory()->create();

    $this->mock(FetchNextBingwaJobs::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('sync');
    });

    app()->call([(new SyncBingwaTransactionsJob), 'handle']);
});

it('skips the sync when user does not exist', function (): void {
    Queue::fake();

    $this->mock(FetchNextBingwaJobs::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('sync');
    });

    app()->call([(new SyncBingwaTransactionsJob), 'handle']);
});
