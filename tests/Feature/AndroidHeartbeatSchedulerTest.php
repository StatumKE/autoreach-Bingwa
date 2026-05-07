<?php

use Illuminate\Support\Facades\File;

test('the android heartbeat scheduler registers unique periodic work', function (): void {
    $scheduler = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/BingwaHeartbeatScheduler.kt'));
    $sharedScheduler = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/BingwaPeriodicArtisanScheduler.kt'));

    expect($scheduler)
        ->toContain('already registered in this process')
        ->not->toContain('enqueueUniquePeriodicWork');

    expect($sharedScheduler)
        ->toContain('Scheduling periodic work name=')
        ->toContain('BingwaHeartbeatWorker')
        ->toContain('workDataOf')
        ->toContain('artisanCommand')
        ->toContain('workTag')
        ->toContain('enqueueUniquePeriodicWork')
        ->toContain('ExistingPeriodicWorkPolicy.KEEP')
        ->toContain('15L')
        ->toContain('NetworkType.CONNECTED');
});

test('the android heartbeat worker runs the bingwa heartbeat command', function (): void {
    $worker = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/BingwaHeartbeatWorker.kt'));
    $environment = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt'));

    expect($worker)
        ->toContain('Worker started id=')
        ->toContain('KEY_ARTISAN_COMMAND')
        ->toContain('KEY_WORK_LABEL')
        ->toContain('isRuntimeReady()')
        ->toContain('PHPBridge.withBackgroundArtisanLock')
        ->toContain('prepareBackgroundRuntime()')
        ->toContain('runArtisanCommand(artisanCommand)')
        ->not->toContain('bootEphemeralRuntime()')
        ->not->toContain('runEphemeralArtisan(artisanCommand)');

    expect(File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt')))
        ->toContain('ReentrantLock')
        ->toContain('withBackgroundArtisanLock');

    expect($environment)
        ->toContain('RUNTIME_READY_MARKER')
        ->toContain('markRuntimeReady()')
        ->toContain('clearRuntimeReadyMarker()')
        ->toContain('prepareBackgroundRuntime()')
        ->toContain('isRuntimeReady()');
});

test('the android sync scheduler registers the queued transaction job every fifteen minutes', function (): void {
    $scheduler = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/BingwaSyncTransactionsScheduler.kt'));

    expect($scheduler)
        ->toContain('bingwa-sync-transactions')
        ->toContain('bingwa:sync-transactions');
});

test('main activity no longer registers the heartbeat scheduler directly', function (): void {
    $mainActivity = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'));

    expect($mainActivity)
        ->not->toContain('BingwaHeartbeatScheduler.register(applicationContext)');
});

test('the native scheduler init registers the heartbeat scheduler', function (): void {
    $init = File::get(base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaPluginInit.kt'));

    expect($init)
        ->toContain('Initializing Bingwa heartbeat scheduler from NativePHP init function')
        ->toContain('BingwaHeartbeatScheduler.register(context)')
        ->toContain('Bingwa heartbeat scheduler registered from NativePHP init function')
        ->toContain('Initializing Bingwa transaction sync scheduler from NativePHP init function')
        ->toContain('BingwaSyncTransactionsScheduler.register(context)')
        ->toContain('Bingwa transaction sync scheduler registered from NativePHP init function');
});

test('the native scheduler manifest exposes the android init function', function (): void {
    $manifest = json_decode(File::get(base_path('packages/statum/native-scheduler/nativephp.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['android']['init_function'] ?? null)
        ->toBe('com.statum.plugins.nativescheduler.BingwaPluginInit.initialize');
});

test('the plugin init function registers the heartbeat scheduler', function (): void {
    $pluginInit = File::get(base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaPluginInit.kt'));

    expect($pluginInit)
        ->toContain('BingwaHeartbeatScheduler.register(context)')
        ->toContain('Initializing Bingwa heartbeat scheduler from NativePHP init function');
});

test('the heartbeat poller component has been removed', function (): void {
    expect(File::exists(base_path('resources/views/components/⚡heartbeat-poller.blade.php')))->toBeFalse();
});

test('the shell layout no longer mounts the heartbeat poller', function (): void {
    $sidebar = File::get(base_path('resources/views/layouts/app/sidebar.blade.php'));

    expect($sidebar)->not->toContain('heartbeat-poller');
});

test('the legacy foreground scheduler stack has been removed', function (): void {
    $legacyFiles = [
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/BingwaPhpRuntime.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/BingwaScheduler.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/BingwaSchedulerNotification.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/BingwaSchedulerService.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/BingwaSchedulerWorker.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/SchedulerBootReceiver.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/SchedulerRuntimeState.kt',
        'nativephp/ios/laravel/packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaPhpRuntime.kt',
    ];

    foreach ($legacyFiles as $legacyFile) {
        expect(File::exists(base_path($legacyFile)))->toBeFalse();
    }
});
