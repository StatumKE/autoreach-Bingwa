<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('native scheduler copy assets removes legacy scheduler wiring and strips debug symbols', function () {
    $buildPath = storage_path('framework/testing/native-scheduler-'.Str::random(8));
    $mainActivityPath = $buildPath.'/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';
    $buildGradlePath = $buildPath.'/app/build.gradle.kts';
    $legacyServicePath = $buildPath.'/app/src/main/java/com/statum/plugins/nativescheduler/ArtisanSchedulerService.kt';
    $legacyStatePath = $buildPath.'/app/src/main/java/com/statum/plugins/nativescheduler/SchedulerStartupState.kt';

    File::ensureDirectoryExists(dirname($mainActivityPath));
    File::ensureDirectoryExists(dirname($buildGradlePath));
    File::ensureDirectoryExists(dirname($legacyServicePath));

    File::put($mainActivityPath, <<<'KOTLIN'
import android.os.SystemClock
import android.webkit.CookieManager
import com.nativephp.mobile.bridge.PHPQueueWorker
import com.statum.plugins.nativescheduler.ArtisanSchedulerService
import com.statum.plugins.nativescheduler.SchedulerStartupState

class MainActivity {
    private var queueWorker: PHPQueueWorker? = null

    fun onCreate() {
        SchedulerStartupState.appBootstrapComplete = false
        stopSchedulerBeforeBootstrap()
    }

    private fun initializeEnvironmentAsync(onReady: () -> Unit) {
        clearAllCookies()
        val booted = phpBridge.bootPersistentRuntime()
        val bootTime = System.currentTimeMillis() - 1000

        if (booted) {
            Log.d("LaravelInit", "Persistent runtime booted in ${bootTime}ms — requests will skip init/shutdown")

            // Start background queue worker after persistent runtime is ready
            queueWorker = PHPQueueWorker(phpBridge).also { it.start() }
        } else {
            Log.w("LaravelInit", "Persistent runtime boot failed after ${bootTime}ms — falling back to classic mode")
        }

        Handler(Looper.getMainLooper()).post {
            onReady()
        }

        SchedulerStartupState.appBootstrapComplete = true
            startSchedulerAfterLaravelReady()
    }

    private fun startSchedulerAfterLaravelReady() {
        try {
            SchedulerStartupState.appBootstrapComplete = true
            val serviceClass = Class.forName("com.statum.plugins.nativescheduler.ArtisanSchedulerService")
            val intent = Intent(applicationContext, serviceClass)

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                applicationContext.startForegroundService(intent)
            } else {
                applicationContext.startService(intent)
            }

            Log.i("MainActivity", "Bingwa scheduler service started after Laravel initialization")
        } catch (e: ClassNotFoundException) {
            Log.d("MainActivity", "No Bingwa scheduler service installed")
        } catch (e: Exception) {
            Log.e("MainActivity", "Failed to start Bingwa scheduler service", e)
        }
    }

    private fun stopSchedulerBeforeBootstrap() {
        try {
            ArtisanSchedulerService.stop(applicationContext)
            Log.i("MainActivity", "Bingwa scheduler stop requested before Laravel bootstrap")
        } catch (e: Exception) {
            Log.e("MainActivity", "Failed to stop Bingwa scheduler before bootstrap", e)
        }
    }

    private fun waitForSchedulerShutdown(timeoutMs: Long = 3_000L) {
        val deadline = SystemClock.elapsedRealtime() + timeoutMs

        while (ArtisanSchedulerService.engineActive && SystemClock.elapsedRealtime() < deadline) {
            Thread.sleep(50)
        }

        if (ArtisanSchedulerService.engineActive) {
            Log.w("MainActivity", "Scheduler engine still active after timeout; continuing bootstrap")
        }
    }

    fun clearAllCookies() {
        val cookieManager = CookieManager.getInstance()
        cookieManager.removeAllCookies(null)
        cookieManager.flush()
        Log.d("CookieInfo", "All cookies cleared")
    }

    override fun onDestroy() {
        super.onDestroy()
        instance = null
        SchedulerStartupState.appBootstrapComplete = false

        // Post lifecycle event for plugins
        NativePHPLifecycle.post(NativePHPLifecycle.Events.ON_DESTROY)

        // Clean up coordinator fragment to prevent memory leaks
        if (::coord.isInitialized) {
            supportFragmentManager.beginTransaction()
                .remove(coord)
                .commitNowAllowingStateLoss()
        }

        if (::webViewManager.isInitialized) {
            val chromeClient = webView.webChromeClient
            if (chromeClient is WebChromeClient) {
                chromeClient.onHideCustomView()
            }
        }

        // Stop hot reload watcher thread
        shouldStopWatcher = true
        hotReloadWatcherThread?.interrupt()

        // Stop background queue worker before cleanup.
        queueWorker?.stop()

        // Keep the persistent runtime alive while the Android scheduler is active.
        // The foreground service owns the always-on background loop, so tearing the
        // persistent interpreter down here can abort the native PHP engine mid-shutdown.
        if (phpBridge.isPersistentMode()) {
            if (com.statum.plugins.nativescheduler.ArtisanSchedulerService.engineActive) {
                Log.d(
                    "MainActivity",
                    "Scheduler still active; keeping persistent PHP runtime alive"
                )
            } else {
                Log.d("MainActivity", "Shutting down persistent PHP runtime...")
                phpBridge.shutdownPersistentRuntime()
            }
        }

        laravelEnv.cleanup()
        phpBridge.shutdown()
    }
}
KOTLIN);

    File::put($legacyServicePath, '<?php // legacy scheduler service');
    File::put($legacyStatePath, '<?php // legacy scheduler state');

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
KOTLIN);

    try {
        $this->artisan('nativephp:native-scheduler:copy-assets', [
            '--platform' => 'android',
            '--build-path' => $buildPath,
            '--plugin-path' => base_path('packages/statum/native-scheduler'),
            '--app-id' => 'com.example.app',
        ])->assertExitCode(0);

        $patchedMainActivity = File::get($mainActivityPath);

        expect($patchedMainActivity)
            ->not->toContain('PHPQueueWorker')
            ->not->toContain('ArtisanSchedulerService')
            ->not->toContain('SchedulerStartupState')
            ->not->toContain('stopSchedulerBeforeBootstrap()')
            ->not->toContain('waitForSchedulerShutdown()')
            ->not->toContain('startSchedulerAfterLaravelReady()')
            ->not->toContain('queueWorker?.stop()')
            ->not->toContain('SystemClock')
            ->not->toContain('clearAllCookies()')
            ->not->toContain('CookieManager')
            ->not->toContain('removeAllCookies(null)')
            ->not->toContain('CookieInfo')
            ->toContain('if (phpBridge.isPersistentMode())')
            ->toContain('phpBridge.shutdownPersistentRuntime()')
            ->toContain('laravelEnv.cleanup()')
            ->toContain('phpBridge.shutdown()');

        expect(File::exists($legacyServicePath))->toBeFalse();
        expect(File::exists($legacyStatePath))->toBeFalse();
        expect(File::get($buildGradlePath))->not->toContain('keepDebugSymbols.add("**/*.so")');

        $this->artisan('nativephp:native-scheduler:copy-assets', [
            '--platform' => 'android',
            '--build-path' => $buildPath,
            '--plugin-path' => base_path('packages/statum/native-scheduler'),
            '--app-id' => 'com.example.app',
        ])->assertExitCode(0);
    } finally {
        File::deleteDirectory($buildPath);
    }
});

test('native scheduler android bridge source omits legacy worker JNI registrations', function () {
    $bridgeSources = [
        base_path('nativephp/android/app/src/main/cpp/php_bridge.c'),
        base_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/cpp/php_bridge.c'),
    ];

    foreach ($bridgeSources as $bridgeSource) {
        $contents = File::get($bridgeSource);

        expect($contents)
            ->toContain('nativePersistentBoot')
            ->toContain('nativeEphemeralBoot')
            ->toContain('nativeEphemeralArtisan')
            ->toContain('nativeEphemeralShutdown')
            ->not->toContain('worker_initialized')
            ->not->toContain('g_worker_mutex')
            ->not->toContain('worker_embed_init')
            ->not->toContain('native_worker_boot')
            ->not->toContain('native_worker_artisan')
            ->not->toContain('native_worker_shutdown')
            ->not->toContain('nativeWorkerBoot')
            ->not->toContain('nativeWorkerArtisan')
            ->not->toContain('nativeWorkerShutdown');
    }
});

test('native scheduler declares setup permission bridge and runtime permission request source', function () {
    $manifest = json_decode(File::get(base_path('packages/statum/native-scheduler/nativephp.json')), true);
    $bridgeFunctionNames = collect($manifest['bridge_functions'])
        ->pluck('name')
        ->all();
    $source = File::get(base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaFunctions.kt'));
    $generatedRegistration = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt'));

    expect($bridgeFunctionNames)
        ->toContain('RequestSetupPermissions');

    expect($source)
        ->toContain('class RequestSetupPermissions')
        ->toContain('ActivityCompat.requestPermissions')
        ->toContain('Manifest.permission.CALL_PHONE')
        ->toContain('Manifest.permission.READ_CONTACTS')
        ->toContain('Manifest.permission.READ_PHONE_STATE')
        ->toContain('Manifest.permission.POST_NOTIFICATIONS')
        ->toContain('Settings.ACTION_MANAGE_OVERLAY_PERMISSION')
        ->toContain('Settings.ACTION_ACCESSIBILITY_SETTINGS');

    expect($generatedRegistration)
        ->toContain('registry.register("RequestSetupPermissions", BingwaFunctions.RequestSetupPermissions(activity))');
});

test('native scheduler android artisan bootstrap uses the bundle autoloader path', function () {
    $artisanBootstrap = File::get(base_path('vendor/nativephp/mobile/bootstrap/android/artisan.php'));

    expect($artisanBootstrap)
        ->toContain('$_SERVER[\'COMPOSER_AUTOLOADER_PATH\']')
        ->toContain('$_SERVER[\'LARAVEL_BOOTSTRAP_PATH\']')
        ->not->toContain("__DIR__.'/vendor/autoload.php'");
});

test('native scheduler runs transaction polling from a long-running Android scheduler worker', function () {
    $schedulerSource = File::get(base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaScheduler.kt'));
    $workerSource = File::get(base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaSchedulerWorker.kt'));
    $runtimeSource = File::get(base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/BingwaPhpRuntime.kt'));
    $initSource = File::get(base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/NativeSchedulerInit.kt'));
    $bootReceiverSource = File::get(base_path('packages/statum/native-scheduler/resources/android/src/com/statum/plugins/nativescheduler/SchedulerBootReceiver.kt'));
    $phpBridgeSource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt'));
    $manifest = json_decode(File::get(base_path('packages/statum/native-scheduler/nativephp.json')), true);

    expect($schedulerSource)
        ->toContain('PeriodicWorkRequestBuilder<BingwaSchedulerWorker>')
        ->toContain('ExistingPeriodicWorkPolicy.UPDATE')
        ->toContain('OneTimeWorkRequestBuilder<BingwaSchedulerWorker>')
        ->toContain('ExistingWorkPolicy.KEEP')
        ->toContain('STARTUP_DELAY_SECONDS = 15L')
        ->toContain('setInitialDelay(STARTUP_DELAY_SECONDS, TimeUnit.SECONDS)')
        ->not->toContain('setExpedited(')
        ->toContain('NetworkType.CONNECTED');

    expect($workerSource)
        ->toContain('SchedulerRuntimeState.claimEngine()')
        ->toContain('setForeground(createForegroundInfo())')
        ->toContain('runtime.runSchedulerLoop { !isStopped }')
        ->toContain('getForegroundInfo()')
        ->toContain('ForegroundInfo(')
        ->toContain('ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC')
        ->toContain('NotificationChannel')
        ->toContain('NotificationCompat.Builder')
        ->toContain('Bingwa runtime is already active; skipping duplicate work')
        ->not->toContain('Bingwa runtime is already active; retrying later')
        ->not->toContain('runtime.runSyncOnly()')
        ->not->toContain('startForegroundService');

    expect($runtimeSource)
        ->toContain('LaravelEnvironment(applicationContext).initialize()')
        ->toContain('phpBridge!!.bootEphemeralRuntime()')
        ->toContain('SchedulerRuntimeState.releaseEngine()')
        ->toContain('bridge.runEphemeralArtisan(SYNC_COMMAND)')
        ->toContain('bridge.runEphemeralArtisan(HEARTBEAT_COMMAND)')
        ->toContain('bridge.runEphemeralArtisan("$NEXT_JOB_COMMAND --output=')
        ->toContain('bridge.runEphemeralArtisan("$CLAIM_JOB_COMMAND --id=')
        ->toContain('bridge.runEphemeralArtisan(')
        ->toContain('phpBridge?.shutdownEphemeralRuntime()')
        ->toContain('runSchedulerLoop')
        ->toContain('POLL_INTERVAL_MS = 5_000L')
        ->toContain('HEARTBEAT_INTERVAL_MS = 10 * 60 * 1000L')
        ->toContain('runSyncCommand(bridge)')
        ->toContain('sendHeartbeat(bridge)')
        ->toContain('while (true)')
        ->toContain('val job = fetchNextJob(bridge) ?: break')
        ->toContain('val cycleStartedAt = SystemClock.elapsedRealtime()')
        ->toContain('val remainingDelayMs = POLL_INTERVAL_MS - elapsedSincePollStart')
        ->toContain('delay(remainingDelayMs)')
        ->toContain('CLAIM_RETRY_BACKOFF_MS = 2_000L')
        ->not->toContain('sleepUntilNextPoll')
        ->not->toContain('initializeForBackground()')
        ->not->toContain('runSyncOnly()')
        ->not->toContain('runArtisanCommand(')
        ->not->toContain('bootPersistentRuntime()')
        ->not->toContain('shutdownPersistentRuntime()')
        ->not->toContain('runPersistentArtisan(')
        ->not->toContain('nativeEphemeralBoot(')
        ->not->toContain('nativeEphemeralArtisan(')
        ->not->toContain('nativeEphemeralShutdown()')
        ->not->toContain('${bridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php')
        ->not->toContain('persistent.php')
        ->not->toContain('withLock')
        ->not->toContain('Mutex')
        ->toContain('bingwa:sync-transactions')
        ->toContain('bingwa:next-ussd-job')
        ->toContain('bingwa:complete-transaction')
        ->toContain('UssdStatusReporter')
        ->not->toContain('bootWorkerRuntime()')
        ->not->toContain('runWorkerArtisan(')
        ->not->toContain('shutdownWorkerRuntime()')
        ->not->toContain('nativeWorkerBoot(')
        ->not->toContain('nativeWorkerArtisan(')
        ->not->toContain('nativeWorkerShutdown()')
        ->not->toContain('vendor/nativephp/mobile/bootstrap/android/artisan.php')
        ->not->toContain('startForegroundService');

    expect($phpBridgeSource)
        ->toContain('nativeEphemeralBoot(')
        ->toContain('nativeEphemeralArtisan(')
        ->toContain('nativeEphemeralShutdown()')
        ->toContain('bootEphemeralRuntime()')
        ->toContain('runEphemeralArtisan(')
        ->toContain('shutdownEphemeralRuntime()');

    expect($initSource)
        ->toContain('fun initNativeScheduler(context: Context)')
        ->toContain('BingwaScheduler.schedule(context)')
        ->toContain('BingwaScheduler.enqueueStartupRun(context)')
        ->not->toContain('startForegroundService');

    expect($bootReceiverSource)
        ->toContain('BingwaScheduler.schedule(context)')
        ->toContain('BingwaScheduler.enqueueStartupRun(context)')
        ->not->toContain('startForegroundService');

    $appManifest = File::get(base_path('nativephp/android/app/src/main/AndroidManifest.xml'));

    expect($appManifest)
        ->toContain('androidx.work.impl.foreground.SystemForegroundService')
        ->toContain('android:foregroundServiceType="dataSync"');

    expect($manifest['name'] ?? null)->toBe('statum/native-scheduler');
    expect($manifest['hooks']['copy_assets'] ?? null)->toBe('nativephp:native-scheduler:copy-assets');
    expect($manifest['android']['init_function'] ?? null)->toBe('com.statum.plugins.nativescheduler.initNativeScheduler');
    expect($manifest['bridge_functions'] ?? [])
        ->toContain([
            'name' => 'QueueSchedulerRun',
            'android' => 'com.statum.plugins.nativescheduler.BingwaFunctions.QueueSchedulerRun',
        ]);
    expect($manifest['android']['dependencies']['implementation'] ?? [])
        ->toContain('androidx.work:work-runtime-ktx:2.11.2')
        ->toContain('com.squareup.okhttp3:okhttp:4.12.0');

    expect($manifest['android']['permissions'] ?? [])
        ->toContain('android.permission.RECEIVE_BOOT_COMPLETED')
        ->toContain('android.permission.FOREGROUND_SERVICE')
        ->toContain('android.permission.FOREGROUND_SERVICE_DATA_SYNC');

    expect($manifest['android']['services'] ?? [])
        ->toHaveCount(1)
        ->toContain([
            'name' => 'androidx.work.impl.foreground.SystemForegroundService',
            'exported' => false,
            'foregroundServiceType' => 'dataSync',
        ]);
    expect($manifest['android']['receivers'] ?? [])->not->toBeEmpty();
});

test('native scheduler declares android accessibility assets required for the build', function () {
    $manifestPath = base_path('packages/statum/native-scheduler/nativephp.json');
    $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest['assets']['android'] ?? null)->toBeArray();
    expect($manifest['assets']['android'])->toMatchArray([
        'android/res/xml/ussd_accessibility_service_config.xml' => 'res/xml/ussd_accessibility_service_config.xml',
        'android/res/values/ussd_accessibility_service_strings.xml' => 'res/values/ussd_accessibility_service_strings.xml',
    ]);

    expect(File::exists(base_path('packages/statum/native-scheduler/resources/android/res/xml/ussd_accessibility_service_config.xml')))->toBeTrue();
    expect(File::exists(base_path('packages/statum/native-scheduler/resources/android/res/values/ussd_accessibility_service_strings.xml')))->toBeTrue();
});
