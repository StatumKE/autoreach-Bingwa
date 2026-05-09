<?php

use Illuminate\Support\Facades\File;

test('the android shell uses NativePHP default persistent queue worker', function (): void {
    $mainActivity = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'));

    expect($mainActivity)
        ->toContain('import com.nativephp.mobile.bridge.PHPQueueWorker')
        ->toContain('import com.nativephp.mobile.bridge.LaravelRuntimeBridgeProvider')
        ->toContain('import com.nativephp.mobile.runtime.BackgroundRuntimeSyncScheduler')
        ->toContain('import com.nativephp.mobile.runtime.RuntimeTickForegroundService')
        ->toContain('private var queueWorker: PHPQueueWorker? = null')
        ->toContain('queueWorker = PHPQueueWorker(phpBridge).also { it.start() }')
        ->toContain('LaravelRuntimeBridgeProvider.register(this, phpBridge)')
        ->toContain('BackgroundRuntimeSyncScheduler.schedule(this)')
        ->toContain('RuntimeTickForegroundService.start(this)')
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

test('the native scheduler manifest registers runtime fallback permissions without init hooks', function (): void {
    $manifest = json_decode(File::get(base_path('packages/statum/native-scheduler/nativephp.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['android']['init_function'] ?? null)->toBeNull();
    expect($manifest['hooks'] ?? null)->toBeNull();
    expect($manifest['android']['permissions'])
        ->toContain('android.permission.FOREGROUND_SERVICE')
        ->toContain('android.permission.FOREGROUND_SERVICE_DATA_SYNC')
        ->toContain('android.permission.RECEIVE_BOOT_COMPLETED')
        ->toContain('android.permission.WAKE_LOCK');
});

test('the connect baseline runtime scheduler files are installed into the android shell', function (): void {
    $service = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/RuntimeTickForegroundService.kt'));
    $backgroundScheduler = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/BackgroundRuntimeSyncScheduler.kt'));
    $backgroundJob = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/BackgroundRuntimeSyncJobService.kt'));
    $bootReceiver = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/BootCompletedReceiver.kt'));
    $wakeLock = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/runtime/RuntimeWakeLock.kt'));
    $manifest = File::get(base_path('nativephp/android/app/src/main/AndroidManifest.xml'));

    expect($service)
        ->toContain('ContextCompat.startForegroundService')
        ->toContain('startForeground(NOTIFICATION_ID, buildNotification())')
        ->toContain('path = "/api/v1/native/runtime/tick"')
        ->toContain('"X-Bingwa-Runtime" to "android"')
        ->toContain('delayMillis.coerceIn(FAST_INTERVAL_MILLIS, IDLE_INTERVAL_MILLIS)');

    expect($backgroundScheduler)
        ->toContain('setPersisted(true)')
        ->toContain('setPeriodic(INTERVAL_MILLIS, FLEX_MILLIS)')
        ->toContain('setExpedited(true)')
        ->toContain('setMinimumLatency(0L)');

    expect($backgroundJob)
        ->toContain('LaravelRuntimeBridgeProvider.isInitializing()')
        ->toContain('RuntimeWakeLock.withLock(this, "background-runtime-job")')
        ->toContain('path = "/api/v1/native/runtime/tick"')
        ->toContain('jobFinished(params, false)');

    expect($bootReceiver)
        ->toContain('Intent.ACTION_BOOT_COMPLETED')
        ->toContain('Intent.ACTION_MY_PACKAGE_REPLACED')
        ->toContain('BackgroundRuntimeSyncScheduler.schedule(context)')
        ->toContain('BackgroundRuntimeSyncScheduler.scheduleImmediate(context)');

    expect($wakeLock)
        ->toContain('PowerManager.PARTIAL_WAKE_LOCK')
        ->toContain('wakeLock?.acquire(TIMEOUT_MILLIS)')
        ->toContain('wakeLock.release()');

    expect($manifest)
        ->toContain('android.permission.FOREGROUND_SERVICE')
        ->toContain('android.permission.FOREGROUND_SERVICE_DATA_SYNC')
        ->toContain('android.permission.RECEIVE_BOOT_COMPLETED')
        ->toContain('android.permission.WAKE_LOCK')
        ->toContain('android:name=".runtime.BootCompletedReceiver"')
        ->toContain('android:name=".runtime.BackgroundRuntimeSyncJobService"')
        ->toContain('android:foregroundServiceType="dataSync"');
});
