<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('native scheduler copy assets guards php runtime cleanup while scheduler is active', function () {
    $buildPath = storage_path('framework/testing/native-scheduler-'.Str::random(8));
    $mainActivityPath = $buildPath.'/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';
    $buildGradlePath = $buildPath.'/app/build.gradle.kts';

    File::ensureDirectoryExists(dirname($mainActivityPath));
    File::ensureDirectoryExists(dirname($buildGradlePath));

    File::put($mainActivityPath, <<<'KOTLIN'
class MainActivity {
    fun onDestroy() {
        // 5. The Activity owns the persistent UI runtime. The Android scheduler
        // continues on its own ephemeral runtime, so stale UI contexts must not
        // survive Activity recreation.
        if (phpBridge.isPersistentMode()) {
            Log.d("MainActivity", "Shutting down persistent PHP runtime...")
            phpBridge.shutdownPersistentRuntime()
        }

        // 6. Final Global Cleanup
        Log.d("MainActivity", "Final PHP bridge global cleanup")
        laravelEnv.cleanup()
        phpBridge.shutdown()
    }
}
KOTLIN);
    File::put($buildGradlePath, <<<'KOTLIN'
android {
    buildTypes {
        release {
            ndk {
                debugSymbolLevel = "NONE"
            }
        }
    }

    packaging {
        jniLibs {
            keepDebugSymbols.add("**/*.so")
        }
    }
}

dependencies {
    // Gson for JSON handling
    implementation("com.google.code.gson:gson:2.10.1")
}
KOTLIN);

    try {
        $this->artisan('nativephp:native-scheduler:copy-assets', [
            '--platform' => 'android',
            '--build-path' => $buildPath,
            '--plugin-path' => base_path('packages/statum/native-scheduler'),
            '--app-id' => 'com.example.app',
        ])->assertExitCode(0);

        $patchedContents = File::get($mainActivityPath);

        expect($patchedContents)
            ->toContain('val schedulerActive = com.statum.plugins.nativescheduler.ArtisanSchedulerService.engineActive')
            ->toContain('Scheduler still active; keeping persistent PHP runtime alive')
            ->toContain('Scheduler still active; skipping global PHP bridge cleanup');

        $this->artisan('nativephp:native-scheduler:copy-assets', [
            '--platform' => 'android',
            '--build-path' => $buildPath,
            '--plugin-path' => base_path('packages/statum/native-scheduler'),
            '--app-id' => 'com.example.app',
        ])->assertExitCode(0);

        expect(substr_count(File::get($mainActivityPath), 'val schedulerActive = com.statum.plugins.nativescheduler.ArtisanSchedulerService.engineActive'))->toBe(1);
        expect(substr_count(File::get($buildGradlePath), 'com.squareup.okhttp3:okhttp:4.12.0'))->toBe(1);
        expect(File::get($buildGradlePath))->not->toContain('keepDebugSymbols.add("**/*.so")');
    } finally {
        File::deleteDirectory($buildPath);
    }
});

test('native scheduler passes ussd completion messages without inline shell quoting', function () {
    $servicePath = base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/ArtisanSchedulerService.kt');
    $source = File::get($servicePath);

    expect($source)
        ->toContain('Base64.encodeToString')
        ->toContain('Base64.NO_WRAP')
        ->toContain('--transaction-id=$id --result=$status')
        ->toContain('--message-base64=$encodedMessage')
        ->not->toContain('--message=\'$message\'');
});

test('native scheduler reports ussd results in a background callback without airtime_used', function () {
    $servicePath = base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/ArtisanSchedulerService.kt');
    $reporterPath = base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/UssdStatusReporter.kt');
    $serviceSource = File::get($servicePath);
    $reporterSource = File::get($reporterPath);

    expect($serviceSource)
        ->toContain('serviceScope.launch(Dispatchers.IO)')
        ->toContain('statusReporter.report(')
        ->toContain('executionTimeMs = executionTimeMs');

    expect($reporterSource)
        ->toContain('.patch(payload.toString().toRequestBody(JSON))')
        ->toContain('.header("Content-Type", "application/json")')
        ->toContain('"status", if (callback.successful) "successful" else "failed"')
        ->toContain('"failure_code", failureCodeFor(callback.ussdResponse)')
        ->toContain('"executed_at", callback.executedAt')
        ->toContain('ZoneId.of("Africa/Nairobi")')
        ->toContain('ZonedDateTime.now(NAIROBI_ZONE)')
        ->not->toContain('airtime_used');
});
