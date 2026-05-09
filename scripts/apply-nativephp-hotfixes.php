<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

const EXPECTED_NATIVEPHP_MOBILE_VERSION = '3.2.6';
const EXPECTED_NATIVEPHP_MOBILE_FIREBASE_VERSION = '1.1.0';

/**
 * @param  non-empty-string  $path
 */
function project_path(string $path): string
{
    global $projectRoot;

    return $projectRoot.'/'.$path;
}

function replace_or_fail(string $contents, string $search, string $replace, string $label): string
{
    if (! str_contains($contents, $search)) {
        throw new RuntimeException("NativePHP hotfix failed: expected snippet not found for {$label}.");
    }

    return str_replace($search, $replace, $contents);
}

function write_if_changed(string $path, string $contents): void
{
    $directory = dirname($path);
    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $currentContents = file_exists($path) ? file_get_contents($path) : false;

    if ($currentContents === $contents) {
        return;
    }

    file_put_contents($path, $contents);
}

/**
 * @return array<string, string>
 */
function installed_package_versions(): array
{
    $composerLockPath = project_path('composer.lock');

    if (! file_exists($composerLockPath)) {
        throw new RuntimeException('NativePHP hotfix failed: composer.lock was not found.');
    }

    $composerLock = json_decode((string) file_get_contents($composerLockPath), true, flags: JSON_THROW_ON_ERROR);
    $packages = array_merge($composerLock['packages'] ?? [], $composerLock['packages-dev'] ?? []);
    $versions = [];

    foreach ($packages as $package) {
        if (! isset($package['name'], $package['version'])) {
            continue;
        }

        $versions[$package['name']] = $package['version'];
    }

    return $versions;
}

function guard_supported_versions(): void
{
    $versions = installed_package_versions();

    $mobileVersion = $versions['nativephp/mobile'] ?? null;
    $firebaseVersion = $versions['nativephp/mobile-firebase'] ?? null;

    if ($mobileVersion !== EXPECTED_NATIVEPHP_MOBILE_VERSION) {
        throw new RuntimeException(sprintf(
            'NativePHP hotfix failed: expected nativephp/mobile %s, found %s.',
            EXPECTED_NATIVEPHP_MOBILE_VERSION,
            $mobileVersion ?? 'not installed'
        ));
    }

    if ($firebaseVersion !== EXPECTED_NATIVEPHP_MOBILE_FIREBASE_VERSION) {
        throw new RuntimeException(sprintf(
            'NativePHP hotfix failed: expected nativephp/mobile-firebase %s, found %s.',
            EXPECTED_NATIVEPHP_MOBILE_FIREBASE_VERSION,
            $firebaseVersion ?? 'not installed'
        ));
    }
}

/**
 * @param  non-empty-string  $stub
 * @param  array<int, non-empty-string>  $targets
 */
function copy_stub_to_targets(string $stub, array $targets): void
{
    if (! file_exists($stub)) {
        throw new RuntimeException("NativePHP hotfix failed: runtime scheduler stub was not found at {$stub}.");
    }

    $contents = (string) file_get_contents($stub);

    foreach ($targets as $target) {
        write_if_changed($target, $contents);
    }
}

function patch_mobile_firebase_dispatch_command(): void
{
    $stub = project_path('stubs/nativephp/mobile-firebase/DispatchPushEvent.php');
    $targets = [
        project_path('vendor/nativephp/mobile-firebase/src/Commands/DispatchPushEvent.php'),
    ];

    if (! file_exists($stub)) {
        return;
    }

    $stubContents = (string) file_get_contents($stub);

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        write_if_changed($target, $stubContents);
    }
}

function patch_mobile_firebase_android_service(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile-firebase/resources/android/PushNotificationService.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/firebase/PushNotificationService.kt'),
    ];

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (str_contains($contents, 'env.prepareBackgroundRuntime()')) {
            $contents = str_replace(
                'env.prepareBackgroundRuntime()',
                'env.initializeForBackground()',
                $contents
            );
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_background_initializer(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt'),
    ];

    $originalExtractSignature = <<<'KOTLIN'
    private fun extractLaravelBundle(): Boolean = extractionLock.withLock {
        extractLaravelBundleUnlocked()
    }

    private fun extractLaravelBundleUnlocked(): Boolean {
KOTLIN;

    $patchedExtractSignature = <<<'KOTLIN'
    private fun extractLaravelBundle(forceDebugRefresh: Boolean = true): Boolean = extractionLock.withLock {
        extractLaravelBundleUnlocked(forceDebugRefresh)
    }

    private fun extractLaravelBundleUnlocked(forceDebugRefresh: Boolean): Boolean {
KOTLIN;

    $originalShouldExtract = '        val shouldExtract = embeddedVersion.isDebug || !isUpToDate';
    $patchedShouldExtract = '        val shouldExtract = (forceDebugRefresh && embeddedVersion.isDebug) || !isUpToDate';
    $originalBackgroundExtract = '            val didExtract = extractLaravelBundle()';
    $patchedBackgroundExtract = '            val didExtract = extractLaravelBundle(forceDebugRefresh = false)';

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (! str_contains($contents, $patchedExtractSignature)) {
            $contents = replace_or_fail(
                $contents,
                $originalExtractSignature,
                $patchedExtractSignature,
                'LaravelEnvironment extract signature'
            );
        }

        if (! str_contains($contents, $patchedShouldExtract)
            && ! str_contains($contents, 'val apkChanged = apkLastUpdateTime != null && extractedApkLastUpdateTime != apkLastUpdateTime')) {
            $contents = replace_or_fail(
                $contents,
                $originalShouldExtract,
                $patchedShouldExtract,
                'LaravelEnvironment shouldExtract'
            );
        }

        if (! str_contains($contents, $patchedBackgroundExtract)) {
            $contents = replace_or_fail(
                $contents,
                $originalBackgroundExtract,
                $patchedBackgroundExtract,
                'LaravelEnvironment background extract'
            );
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_debug_extraction_marker(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt'),
    ];

    $versionConstant = '        private const val VERSION_FILE = ".version"';
    $apkUpdateConstant = '        private const val APK_UPDATE_FILE = ".apk_last_update_time"';

    $oldShouldExtract = <<<'KOTLIN'
        // Main foreground startup can force refreshes in DEBUG builds for hot reload style
        // development. Background FCM handling cannot safely do that on every push, so the
        // caller can disable the DEBUG override and only extract when the bundle is missing
        // or genuinely out of date.
        val isUpToDate = currentVersion?.clean == embeddedVersion.clean
        val shouldExtract = (forceDebugRefresh && embeddedVersion.isDebug) || !isUpToDate

        Log.d(TAG, "🔍 DEBUG: isUpToDate = $isUpToDate")
        Log.d(TAG, "🔍 DEBUG: shouldExtract = $shouldExtract")
KOTLIN;

    $newShouldExtract = <<<'KOTLIN'
        val isUpToDate = currentVersion?.clean == embeddedVersion.clean
        val apkLastUpdateTime = getApkLastUpdateTime()
        val extractedApkLastUpdateTime = File(laravelDir, APK_UPDATE_FILE)
            .takeIf { it.exists() }
            ?.readText()
            ?.trim()
            ?.toLongOrNull()
        val apkChanged = apkLastUpdateTime != null && extractedApkLastUpdateTime != apkLastUpdateTime

        // DEBUG bundles all have the same version string. Refresh only when the
        // installed APK changed, not on every Activity restart in the same process.
        val shouldExtract = !isUpToDate || (forceDebugRefresh && embeddedVersion.isDebug && apkChanged)

        Log.d(TAG, "🔍 DEBUG: isUpToDate = $isUpToDate")
        Log.d(TAG, "🔍 DEBUG: apkChanged = $apkChanged")
        Log.d(TAG, "🔍 DEBUG: shouldExtract = $shouldExtract")
KOTLIN;

    $versionWrite = <<<'KOTLIN'
            if (versionFromEnv != null) {
                val versionFile = File(laravelDir, VERSION_FILE)
                val cleanVersion = versionFromEnv.trim('"').trim('\'')
                versionFile.writeText(cleanVersion)
                Log.d(TAG, "✅ Updated .version file to: $cleanVersion")
            }
KOTLIN;

    $versionAndApkWrite = <<<'KOTLIN'
            if (versionFromEnv != null) {
                val versionFile = File(laravelDir, VERSION_FILE)
                val cleanVersion = versionFromEnv.trim('"').trim('\'')
                versionFile.writeText(cleanVersion)
                Log.d(TAG, "✅ Updated .version file to: $cleanVersion")
            }

            apkLastUpdateTime?.let {
                File(laravelDir, APK_UPDATE_FILE).writeText(it.toString())
                Log.d(TAG, "✅ Updated APK marker to: $it")
            }
KOTLIN;

    $setupDirectories = '    private fun setupDirectories() {';

    $getApkLastUpdateTime = <<<'KOTLIN'
    private fun getApkLastUpdateTime(): Long? {
        return try {
            context.packageManager.getPackageInfo(context.packageName, 0).lastUpdateTime
        } catch (e: Exception) {
            Log.w(TAG, "⚠️ Could not read APK lastUpdateTime", e)
            null
        }
    }

KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (! str_contains($contents, $apkUpdateConstant)) {
            $contents = replace_or_fail(
                $contents,
                $versionConstant,
                $versionConstant.PHP_EOL.$apkUpdateConstant,
                'LaravelEnvironment APK update marker constant'
            );
        }

        if (! str_contains($contents, 'val apkChanged = apkLastUpdateTime != null && extractedApkLastUpdateTime != apkLastUpdateTime')) {
            $contents = replace_or_fail(
                $contents,
                $oldShouldExtract,
                $newShouldExtract,
                'LaravelEnvironment DEBUG extraction marker'
            );
        }

        if (! str_contains($contents, 'File(laravelDir, APK_UPDATE_FILE).writeText(it.toString())')) {
            $contents = replace_or_fail(
                $contents,
                $versionWrite,
                $versionAndApkWrite,
                'LaravelEnvironment APK marker write'
            );
        }

        if (! str_contains($contents, 'private fun getApkLastUpdateTime(): Long?')) {
            $contents = replace_or_fail(
                $contents,
                $setupDirectories,
                $getApkLastUpdateTime.$setupDirectories,
                'LaravelEnvironment getApkLastUpdateTime'
            );
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_persistent_shutdown(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt'),
    ];

    $originalShutdown = <<<'KOTLIN'
        val future = phpExecutor.submit<Unit> {
            nativePersistentShutdown()
            persistentBooted = false
            Log.i(TAG, "Persistent runtime shut down")
        }
KOTLIN;

    $patchedShutdown = <<<'KOTLIN'
        val future = phpExecutor.submit<Unit> {
            persistentBooted = false
            persistentMode = false
            Log.w(TAG, "Persistent runtime native shutdown skipped to avoid PHP embed teardown abort")
        }
KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (! str_contains($contents, $patchedShutdown)
            && ! str_contains($contents, 'Persistent runtime state cleared (native shutdown skipped to avoid PHP embed teardown abort)')) {
            $contents = replace_or_fail(
                $contents,
                $originalShutdown,
                $patchedShutdown,
                'PHPBridge persistent shutdown'
            );
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_main_activity_destroy_order(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'),
    ];

    $wrongOrder = <<<'KOTLIN'
        // Stop background queue worker before persistent runtime shutdown

        // Shutdown persistent runtime before cleanup
        if (phpBridge.isPersistentMode()) {
            phpBridge.shutdownPersistentRuntime()
        }

        laravelEnv.cleanup()
        queueWorker?.stop()
        phpBridge.shutdown()
KOTLIN;

    $correctOrder = <<<'KOTLIN'
        // Stop background queue worker before persistent runtime shutdown
        queueWorker?.stop()

        // Shutdown persistent runtime before cleanup
        if (phpBridge.isPersistentMode()) {
            phpBridge.shutdownPersistentRuntime()
        }

        laravelEnv.cleanup()
        phpBridge.shutdown()
KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (str_contains($contents, $wrongOrder)) {
            $contents = str_replace($wrongOrder, $correctOrder, $contents);
        }

        write_if_changed($target, $contents);
    }
}

function install_android_runtime_scheduler_sources(): void
{
    $sourceRoot = project_path('stubs/nativephp/mobile-runtime');
    $targetRoots = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile'),
    ];

    $files = [
        'bridge/LaravelRuntimeBridgeProvider.kt',
        'network/InternalLaravelRequestClient.kt',
        'runtime/BackgroundRuntimeSyncJobService.kt',
        'runtime/BackgroundRuntimeSyncScheduler.kt',
        'runtime/BootCompletedReceiver.kt',
        'runtime/RuntimeTickForegroundService.kt',
        'runtime/RuntimeWakeLock.kt',
    ];

    foreach ($files as $file) {
        copy_stub_to_targets(
            $sourceRoot.'/'.$file,
            array_map(fn (string $root): string => $root.'/'.$file, $targetRoots),
        );
    }
}

function patch_android_runtime_scheduler_manifest(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/AndroidManifest.xml'),
        project_path('nativephp/android/app/src/main/AndroidManifest.xml'),
    ];

    $permissions = [
        'android.permission.FOREGROUND_SERVICE',
        'android.permission.FOREGROUND_SERVICE_DATA_SYNC',
        'android.permission.RECEIVE_BOOT_COMPLETED',
        'android.permission.WAKE_LOCK',
    ];

    $runtimeComponents = <<<'XML'
        <service
            android:name=".runtime.BackgroundRuntimeSyncJobService"
            android:exported="false"
            android:permission="android.permission.BIND_JOB_SERVICE" />
        <service
            android:name=".runtime.RuntimeTickForegroundService"
            android:exported="false"
            android:foregroundServiceType="dataSync" />
        <receiver
            android:name=".runtime.BootCompletedReceiver"
            android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.BOOT_COMPLETED" />
                <action android:name="android.intent.action.MY_PACKAGE_REPLACED" />
            </intent-filter>
        </receiver>

XML;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        foreach ($permissions as $permission) {
            $line = '    <uses-permission android:name="'.$permission.'" />';
            if (! str_contains($contents, $permission)) {
                if (str_contains($contents, '    <!-- NativePHP Plugin Permissions -->')) {
                    $contents = replace_or_fail(
                        $contents,
                        '    <!-- NativePHP Plugin Permissions -->',
                        $line.PHP_EOL.'    <!-- NativePHP Plugin Permissions -->',
                        "AndroidManifest permission {$permission}",
                    );
                } else {
                    $contents = replace_or_fail(
                        $contents,
                        '    <application',
                        $line.PHP_EOL.PHP_EOL.'    <application',
                        "AndroidManifest permission {$permission}",
                    );
                }
            }
        }

        if (! str_contains($contents, 'RuntimeTickForegroundService')) {
            $contents = replace_or_fail(
                $contents,
                '    </application>',
                $runtimeComponents.'    </application>',
                'Android runtime scheduler components',
            );
        }

        write_if_changed($target, $contents);
    }
}

function patch_android_runtime_scheduler_main_activity(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'),
    ];

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (! str_contains($contents, 'import com.nativephp.mobile.bridge.LaravelRuntimeBridgeProvider')) {
            $contents = replace_or_fail(
                $contents,
                'import com.nativephp.mobile.bridge.LaravelEnvironment',
                <<<'KOTLIN'
import com.nativephp.mobile.bridge.LaravelEnvironment
import com.nativephp.mobile.bridge.LaravelRuntimeBridgeProvider
import com.nativephp.mobile.runtime.BackgroundRuntimeSyncScheduler
import com.nativephp.mobile.runtime.RuntimeTickForegroundService
KOTLIN,
                'MainActivity runtime scheduler imports',
            );
        }

        if (! str_contains($contents, 'private var runtimeTickSchedulerStarted = false')) {
            $contents = replace_or_fail(
                $contents,
                '    private var showSplash by mutableStateOf(true)',
                <<<'KOTLIN'
    private var showSplash by mutableStateOf(true)

    @Volatile
    private var laravelEnvironmentReady = false
    private var runtimeTickSchedulerStarted = false
KOTLIN,
                'MainActivity runtime scheduler state',
            );
        }

        if (! str_contains($contents, 'LaravelRuntimeBridgeProvider.beginInitialization()')) {
            $contents = replace_or_fail(
                $contents,
                '        Thread {
            Log.d("LaravelInit", "Starting async Laravel extraction...")
            laravelEnv = LaravelEnvironment(this)
            laravelEnv.initialize()

            Log.d("LaravelInit", "Laravel environment ready")',
                <<<'KOTLIN'
        Thread {
            try {
                Log.d("LaravelInit", "Starting async Laravel extraction...")
                LaravelRuntimeBridgeProvider.beginInitialization()
                laravelEnv = LaravelEnvironment(this)
                laravelEnv.initialize()
                LaravelRuntimeBridgeProvider.register(this, phpBridge)
                laravelEnvironmentReady = true

                Log.d("LaravelInit", "Laravel environment ready")
KOTLIN,
                'MainActivity Laravel initialization start',
            );

            $contents = replace_or_fail(
                $contents,
                '            Handler(Looper.getMainLooper()).post {
                onReady()
            }
        }.start()',
                <<<'KOTLIN'
                Handler(Looper.getMainLooper()).post {
                    onReady()
                    startRuntimeScheduler()
                }
            } catch (exception: Exception) {
                LaravelRuntimeBridgeProvider.failInitialization()
                Log.e("LaravelInit", "Laravel environment initialization failed", exception)
                BackgroundRuntimeSyncScheduler.schedule(applicationContext)
                BackgroundRuntimeSyncScheduler.scheduleImmediate(applicationContext)
                Handler(Looper.getMainLooper()).post {
                    showSplash = false
                }
            }
        }.start()
KOTLIN,
                'MainActivity Laravel initialization finish',
            );
        }

        if (! str_contains($contents, 'private fun startRuntimeScheduler()')) {
            $contents = replace_or_fail(
                $contents,
                '    private fun initializeEnvironment() {',
                <<<'KOTLIN'
    private fun startRuntimeScheduler() {
        if (!laravelEnvironmentReady || runtimeTickSchedulerStarted) {
            return
        }

        runtimeTickSchedulerStarted = true
        BackgroundRuntimeSyncScheduler.schedule(this)

        try {
            RuntimeTickForegroundService.start(this)
        } catch (exception: Exception) {
            runtimeTickSchedulerStarted = false
            Log.e("RuntimeTickService", "Foreground runtime scheduler could not start", exception)
            BackgroundRuntimeSyncScheduler.scheduleImmediate(this)
        }
    }

    private fun initializeEnvironment() {
KOTLIN,
                'MainActivity startRuntimeScheduler method',
            );
        }

        if (! str_contains($contents, 'LaravelRuntimeBridgeProvider.reset(shutdown = false)')) {
            $contents = replace_or_fail(
                $contents,
                '        instance = null',
                <<<'KOTLIN'
        instance = null
        laravelEnvironmentReady = false
        runtimeTickSchedulerStarted = false
        LaravelRuntimeBridgeProvider.reset(shutdown = false)
KOTLIN,
                'MainActivity runtime provider reset',
            );
        }

        write_if_changed($target, $contents);
    }
}

try {
    guard_supported_versions();
    patch_mobile_firebase_dispatch_command();
    patch_mobile_firebase_android_service();
    install_android_runtime_scheduler_sources();
    patch_mobile_background_initializer();
    patch_mobile_debug_extraction_marker();
    patch_mobile_persistent_shutdown();
    patch_mobile_main_activity_destroy_order();
    patch_android_runtime_scheduler_manifest();
    patch_android_runtime_scheduler_main_activity();
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage().PHP_EOL);

    exit(1);
}
