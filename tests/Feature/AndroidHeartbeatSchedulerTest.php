<?php

use Illuminate\Support\Facades\File;

test('the android shell uses NativePHP default persistent queue worker', function (): void {
    $mainActivity = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'));

    expect($mainActivity)
        ->toContain('import com.nativephp.mobile.bridge.PHPQueueWorker')
        ->toContain('private var queueWorker: PHPQueueWorker? = null')
        ->toContain('queueWorker = PHPQueueWorker(phpBridge).also { it.start() }')
        ->toContain('queueWorker?.stop()')
        ->not->toContain('BingwaHeartbeatScheduler.register(applicationContext)')
        ->not->toContain('BingwaSyncTransactionsScheduler.register(applicationContext)');
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
        'nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/BingwaHeartbeatScheduler.kt',
        'nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/BingwaHeartbeatWorker.kt',
        'nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/BingwaPeriodicArtisanScheduler.kt',
        'nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/BingwaSyncTransactionsScheduler.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/BingwaPhpRuntime.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/BingwaScheduler.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/BingwaSchedulerNotification.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/BingwaSchedulerService.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/BingwaSchedulerWorker.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/SchedulerBootReceiver.kt',
        'nativephp/android/app/src/main/java/com/statum/plugins/nativescheduler/SchedulerRuntimeState.kt',
        'nativephp/ios/laravel/packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaPhpRuntime.kt',
        'packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaPluginInit.kt',
    ];

    foreach ($legacyFiles as $legacyFile) {
        expect(File::exists(base_path($legacyFile)))->toBeFalse();
    }
});

test('the native scheduler manifest does not register custom android init hooks', function (): void {
    $manifest = json_decode(File::get(base_path('packages/statum/native-scheduler/nativephp.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['android']['init_function'] ?? null)->toBeNull();
    expect($manifest['hooks'] ?? null)->toBeNull();
});
