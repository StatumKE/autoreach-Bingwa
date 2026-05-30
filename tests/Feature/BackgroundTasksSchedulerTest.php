<?php

use Illuminate\Support\Facades\File;
use NativePHP\BackgroundTasks\SchedulerManifestGenerator;

it('registers the bingwa background tasks in the Laravel scheduler', function (): void {
    $console = File::get(base_path('routes/console.php'));

    expect($console)
        ->toContain("Schedule::command('bingwa:heartbeat')->everyFifteenMinutes()->withoutOverlapping()")
        ->toContain("Schedule::command('bingwa:sync-transactions')->everyFiveMinutes()->withoutOverlapping()")
        ->toContain("Schedule::command('bingwa:process-auto-renewals')->everyFiveMinutes()->withoutOverlapping()")
        ->toContain("Schedule::command('bingwa:fetch-airtime-balance')->everyFifteenMinutes()->withoutOverlapping()")
        ->not->toContain('->everyMinute()');
});

it('exports fifteen minute intervals for NativePHP background tasks', function (): void {
    $tasks = collect((new SchedulerManifestGenerator)->generate())->keyBy('command');

    expect($tasks->get('bingwa:heartbeat')['interval_minutes'])
        ->toBe(15)
        ->and($tasks->get('bingwa:sync-transactions')['interval_minutes'])
        ->toBe(15)
        ->and($tasks->get('bingwa:process-auto-renewals')['interval_minutes'])
        ->toBe(15);
});
