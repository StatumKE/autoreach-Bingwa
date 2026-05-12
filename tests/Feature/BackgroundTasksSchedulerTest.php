<?php

use Illuminate\Support\Facades\File;

it('registers the bingwa background tasks in the Laravel scheduler', function (): void {
    $console = File::get(base_path('routes/console.php'));

    expect($console)
        ->toContain("Schedule::command('bingwa:heartbeat')")
        ->toContain("Schedule::command('bingwa:sync-transactions')")
        ->toContain('->everyFifteenMinutes()')
        ->toContain('->onAnyNetwork()');
});
