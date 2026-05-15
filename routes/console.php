<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$firstBootAt = Cache::get('native_app_first_boot_at');
$isFreshBoot = false;

if ($firstBootAt === null) {
    Cache::forever('native_app_first_boot_at', now()->timestamp);
    $isFreshBoot = true;
} elseif ((now()->timestamp - (int) $firstBootAt) < 90) {
    $isFreshBoot = true;
}

if (! $isFreshBoot) {
    // Heartbeat and Transaction Sync schedules
    Schedule::command('bingwa:heartbeat')->everyFifteenMinutes();
    Schedule::command('bingwa:sync-transactions')->everyFifteenMinutes();
}
