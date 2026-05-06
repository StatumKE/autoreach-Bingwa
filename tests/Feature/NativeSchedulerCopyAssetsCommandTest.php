<?php

use Illuminate\Support\Facades\File;

test('native scheduler package no longer ships android scheduler kotlin sources', function () {
    $schedulerFiles = [
        'packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaScheduler.kt',
        'packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaSchedulerService.kt',
        'packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaSchedulerWorker.kt',
        'packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaPhpRuntime.kt',
        'packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/SchedulerRuntimeState.kt',
        'packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaSchedulerNotification.kt',
        'packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/NativeSchedulerInit.kt',
        'packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/SchedulerBootReceiver.kt',
    ];

    foreach ($schedulerFiles as $file) {
        expect(File::exists(base_path($file)))->toBeFalse();
    }
});

test('native scheduler android bridge source no longer exposes scheduler queueing', function () {
    $bridgeSource = File::get(base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaFunctions.kt'));

    expect($bridgeSource)
        ->not->toContain('QueueSchedulerRun')
        ->not->toContain('BingwaScheduler.enqueueStartupRun(context)');
});

test('native scheduler manifest no longer registers the removed android scheduler wiring', function () {
    $manifest = File::get(base_path('packages/statum/native-scheduler/nativephp.json'));

    expect($manifest)
        ->not->toContain('QueueSchedulerRun')
        ->not->toContain('BingwaSchedulerService')
        ->not->toContain('SchedulerBootReceiver')
        ->not->toContain('work-runtime-ktx')
        ->not->toContain('RECEIVE_BOOT_COMPLETED')
        ->not->toContain('FOREGROUND_SERVICE_DATA_SYNC')
        ->not->toContain('initNativeScheduler');
});

test('native android manifest does not register the removed scheduler components', function () {
    $manifest = File::get(base_path('nativephp/android/app/src/main/AndroidManifest.xml'));

    expect($manifest)
        ->not->toContain('BingwaSchedulerService')
        ->not->toContain('SchedulerBootReceiver')
        ->not->toContain('RECEIVE_BOOT_COMPLETED')
        ->not->toContain('FOREGROUND_SERVICE_DATA_SYNC');
});
