<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat and Transaction Sync schedules
Schedule::command('bingwa:heartbeat')->everyMinute();
Schedule::command('bingwa:sync-transactions')->everyMinute();
Schedule::command('bingwa:process-auto-renewals')->everyMinute()->withoutOverlapping();
