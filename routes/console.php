<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat, transaction sync, and auto-renewal schedules
Schedule::command('bingwa:heartbeat')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('bingwa:sync-transactions')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bingwa:process-auto-renewals')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bingwa:fetch-airtime-balance')->everyFifteenMinutes()->withoutOverlapping();
