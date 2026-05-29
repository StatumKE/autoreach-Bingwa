<?php

use App\Actions\Autoreach\ProcessDueAutoRenewals;
use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Models\AutoRenewal;
use App\Models\Offer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('converts due auto renewals into queued transactions', function (): void {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create([
        'name' => 'Daily Data',
        'category' => 'data',
        'price' => 50,
        'ussd_code' => '*180*5*PN#',
        'is_active' => true,
    ]);

    $renewal = AutoRenewal::factory()->for($user)->for($offer)->create([
        'customer_phone' => '0712345678',
        'scheduled_for' => now()->subMinute(),
        'auto_renew' => true,
        'renew_days' => 3,
        'status' => 'scheduled',
        'processed_at' => null,
    ]);

    $result = app(ProcessDueAutoRenewals::class)->process($user->id);

    expect($result)
        ->queued->toBe(1)
        ->rescheduled->toBe(1)
        ->failed->toBe(0)
        ->users->toBe([$user->id]);

    $transaction = Transaction::query()->first();

    expect($transaction)->not->toBeNull();
    expect($transaction?->status)->toBe('queued');
    expect($transaction?->sender_phone)->toBe('0712345678');
    expect($transaction?->offer_id)->toBe($offer->id);
    expect($transaction?->matched_offer)->toMatchArray([
        'source' => 'auto_renewal',
        'auto_renewal_id' => $renewal->id,
        'offer_local_id' => (string) $offer->id,
    ]);

    expect($renewal->fresh()?->status)->toBe('completed');
    expect($renewal->fresh()?->processed_at)->not->toBeNull();

    $nextRenewal = AutoRenewal::query()
        ->where('id', '!=', $renewal->id)
        ->first();

    expect($nextRenewal)->not->toBeNull();
    expect($nextRenewal?->status)->toBe('scheduled');
    expect($nextRenewal?->renew_days)->toBe(2);
    expect($nextRenewal?->scheduled_for?->toDateString())->toBe($renewal->scheduled_for->copy()->addDay()->toDateString());
});

it('loads each due renewal offer only once while processing a batch', function (): void {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create([
        'name' => 'Daily Data',
        'category' => 'data',
        'price' => 50,
        'ussd_code' => '*180*5*PN#',
        'is_active' => true,
    ]);

    AutoRenewal::factory()
        ->for($user)
        ->for($offer)
        ->count(2)
        ->create([
            'customer_phone' => '0712345678',
            'scheduled_for' => now()->subMinute(),
            'auto_renew' => false,
            'renew_days' => 1,
            'status' => 'scheduled',
            'processed_at' => null,
        ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $result = app(ProcessDueAutoRenewals::class)->process($user->id);

    $offerQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(function (string $sql): bool {
            return str_contains(strtolower($sql), 'from "offers"');
        })
        ->values();

    expect($result)
        ->queued->toBe(2)
        ->rescheduled->toBe(0)
        ->failed->toBe(0);
    expect($offerQueries)->toHaveCount(1);
});

it('does not process future auto renewals', function (): void {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create(['is_active' => true]);

    AutoRenewal::factory()->for($user)->for($offer)->create([
        'scheduled_for' => now()->addHour(),
        'status' => 'scheduled',
    ]);

    $result = app(ProcessDueAutoRenewals::class)->process($user->id);

    expect($result['queued'])->toBe(0);
    expect(Transaction::query()->count())->toBe(0);
});

it('dispatches the existing transaction processor after queueing due auto renewals', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create(['is_active' => true]);

    AutoRenewal::factory()->for($user)->for($offer)->create([
        'scheduled_for' => now()->subMinute(),
        'status' => 'scheduled',
    ]);

    $this->artisan('bingwa:process-auto-renewals')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBingwaQueuedTransactionsJob::class);
});
