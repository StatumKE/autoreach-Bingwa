<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

describe('SendBingwaHeartbeatCommand', function () {
    beforeEach(function () {
        Cache::flush();
    });

    it('skips execution and returns success during the fresh-boot grace period', function () {
        // First call: no cache entry exists → fresh-boot detected
        $this->artisan('bingwa:heartbeat')
            ->assertSuccessful();

        // The boot timestamp should now be recorded
        expect(Cache::get('native_app_first_boot_at'))->not->toBeNull();
    });

    it('skips execution when still within the grace period', function () {
        // Simulate a recent first boot (30 seconds ago)
        Cache::forever('native_app_first_boot_at', now()->subSeconds(30)->timestamp);

        $this->artisan('bingwa:heartbeat')
            ->assertSuccessful();
    });

    it('proceeds normally after the grace period has elapsed', function () {
        // Simulate first boot well in the past (2 hours ago)
        Cache::forever('native_app_first_boot_at', now()->subHours(2)->timestamp);

        // No user with device registration → should warn but succeed
        $this->artisan('bingwa:heartbeat')
            ->assertSuccessful();
    });
});

describe('SyncBingwaTransactions', function () {
    beforeEach(function () {
        Cache::flush();
    });

    it('skips execution and returns success during the fresh-boot grace period', function () {
        $this->artisan('bingwa:sync-transactions')
            ->assertSuccessful();

        expect(Cache::get('native_app_first_boot_at'))->not->toBeNull();
    });

    it('proceeds normally after the grace period has elapsed', function () {
        Cache::forever('native_app_first_boot_at', now()->subHours(2)->timestamp);

        // No user with device registration → should warn but succeed
        $this->artisan('bingwa:sync-transactions')
            ->assertSuccessful();
    });
});
