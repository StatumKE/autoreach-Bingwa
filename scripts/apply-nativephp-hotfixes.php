<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

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
        echo "NativePHP hotfix skipped: snippet not found for {$label}.".PHP_EOL;

        return $contents;
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
 * @param  non-empty-string  $stub
 * @param  array<int, non-empty-string>  $targets
 */
function copy_stub_to_targets(string $stub, array $targets): void
{
    if (! file_exists($stub)) {
        throw new RuntimeException("NativePHP hotfix failed: expected stub was not found at {$stub}.");
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

function patch_mobile_background_tasks_scheduler_lock(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile-background-tasks/resources/android/PHPSchedulerWorker.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/background/PHPSchedulerWorker.kt'),
    ];

    $patched = <<<'KOTLIN'
package com.nativephp.mobile.background

import android.content.Context
import android.util.Log
import androidx.work.Worker
import androidx.work.WorkerParameters
import com.nativephp.mobile.bridge.BridgeFunctionRegistry
import com.nativephp.mobile.bridge.LaravelEnvironment
import com.nativephp.mobile.bridge.PHPBridge
import com.nativephp.mobile.bridge.plugins.registerContextOnlyBridgeFunctions

/**
 * WorkManager Worker that executes a scheduled artisan command.
 *
 * Each task has its own PeriodicWorkRequest with independent intervals and
 * constraints, but execution is serialized with PHPBridge.phpLock because
 * the ephemeral PHP runtime must be booted, used, and shut down on the same
 * thread without another PHP runtime user entering the session.
 *
 * Each execution: acquire lock -> boot ephemeral -> run command -> shutdown -> release lock.
 * This runs in a WorkManager-managed thread, potentially in a cold process
 * start after the app has been closed.
 */
class PHPSchedulerWorker(
    context: Context,
    params: WorkerParameters
) : Worker(context, params) {

    companion object {
        private const val TAG = "PHPSchedulerWorker"
        const val KEY_COMMAND = "command"
    }

    override fun doWork(): Result {
        val command = inputData.getString(KEY_COMMAND)
        if (command.isNullOrEmpty()) {
            Log.e(TAG, "No command specified in work request")
            return Result.failure()
        }

        Log.i(TAG, "Executing scheduled command: $command (waiting for PHP lock)")

        return synchronized(PHPBridge.phpLock) {
            Log.i(TAG, "PHP lock acquired for: $command")

            try {
                if (!BridgeFunctionRegistry.shared.exists("BackgroundTasks.Register")) {
                    Log.i(TAG, "Cold boot detected - registering context-only bridge functions")
                    registerContextOnlyBridgeFunctions(applicationContext)
                }

                val env = LaravelEnvironment(applicationContext)
                env.initializeForBackground()

                val phpBridge = PHPBridge(applicationContext)
                val booted = phpBridge.nativeEphemeralBoot(
                    "${phpBridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
                )

                if (booted != 0) {
                    Log.e(TAG, "Failed to boot ephemeral runtime for: $command")
                    return@synchronized Result.retry()
                }

                try {
                    val output = phpBridge.nativeEphemeralArtisan(command)
                    Log.i(TAG, "Command completed: $command (output=${output.take(200)})")
                } finally {
                    phpBridge.nativeEphemeralShutdown()
                }

                Result.success()
            } catch (e: Exception) {
                Log.e(TAG, "Scheduler execution failed for: $command", e)
                Result.retry()
            } finally {
                Log.i(TAG, "PHP lock released for: $command")
            }
        }
    }
}
KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (! str_contains($contents, 'return synchronized(PHPBridge.phpLock)')) {
            write_if_changed($target, $patched);
        }
    }
}

function patch_mobile_firebase_ephemeral_lock(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile-firebase/resources/android/PushNotificationService.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/firebase/PushNotificationService.kt'),
    ];

    $original = <<<'KOTLIN'
                val phpBridge = PHPBridge(applicationContext)
                val bootstrapPath = "${phpBridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
                val booted = phpBridge.nativeEphemeralBoot(bootstrapPath)

                if (booted != 0) {
                    Log.e(TAG, "Failed to boot ephemeral runtime for data message events")
                    pendingCommands.clear()
                    return@Thread
                }

                try {
                    // Process all queued commands in this single ephemeral session
                    while (true) {
                        val cmd = pendingCommands.poll() ?: break
                        val output = phpBridge.nativeEphemeralArtisan(cmd)
                        Log.d(TAG, "Data message event dispatched (output=${output.take(200)})")
                    }
                } finally {
                    phpBridge.nativeEphemeralShutdown()
                }
KOTLIN;

    $patched = <<<'KOTLIN'
                val phpBridge = PHPBridge(applicationContext)
                synchronized(PHPBridge.phpLock) {
                    val bootstrapPath = "${phpBridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
                    val booted = phpBridge.nativeEphemeralBoot(bootstrapPath)

                    if (booted != 0) {
                        Log.e(TAG, "Failed to boot ephemeral runtime for data message events")
                        pendingCommands.clear()
                        return@Thread
                    }

                    try {
                        // Process all queued commands in this single ephemeral session
                        while (true) {
                            val cmd = pendingCommands.poll() ?: break
                            val output = phpBridge.nativeEphemeralArtisan(cmd)
                            Log.d(TAG, "Data message event dispatched (output=${output.take(200)})")
                        }
                    } finally {
                        phpBridge.nativeEphemeralShutdown()
                    }
                }
KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (! str_contains($contents, 'synchronized(PHPBridge.phpLock)')) {
            $contents = replace_or_fail(
                $contents,
                $original,
                $patched,
                'PushNotificationService ephemeral PHP lock'
            );
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_native_mutexes(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/cpp/php_bridge.c'),
        project_path('nativephp/android/app/src/main/cpp/php_bridge.c'),
    ];

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        // 1. Cleanup: Remove any existing multiple unlocks (prevents corruption from multiple runs)
        $contents = preg_replace('/(\s+pthread_mutex_unlock\(&g_php_request_mutex\);){2,}/', "\n        pthread_mutex_unlock(&g_php_request_mutex);", $contents);

        // 2. Ephemeral Boot
        if (! str_contains($contents, "native_ephemeral_boot(JNIEnv *env, jobject thiz, jstring jBootstrapPath) {\n    pthread_mutex_lock(&g_php_request_mutex);")) {
            $contents = str_replace(
                'native_ephemeral_boot(JNIEnv *env, jobject thiz, jstring jBootstrapPath) {',
                "native_ephemeral_boot(JNIEnv *env, jobject thiz, jstring jBootstrapPath) {\n    pthread_mutex_lock(&g_php_request_mutex);",
                $contents
            );
        }
        // Ephemeral Boot Success Unlock
        if (! str_contains($contents, 'LOGI("ephemeral_boot: ephemeral PHP interpreter ready");'.PHP_EOL.PHP_EOL.'    pthread_mutex_unlock(&g_php_request_mutex);')) {
            $contents = str_replace(
                'LOGI("ephemeral_boot: ephemeral PHP interpreter ready");',
                'LOGI("ephemeral_boot: ephemeral PHP interpreter ready");'.PHP_EOL.PHP_EOL.'    pthread_mutex_unlock(&g_php_request_mutex);',
                $contents
            );
        }

        // 3. Ephemeral Artisan
        if (! str_contains($contents, "native_ephemeral_artisan(JNIEnv *env, jobject thiz, jstring jCommand) {\n    pthread_mutex_lock(&g_php_request_mutex);")) {
            $contents = str_replace(
                'native_ephemeral_artisan(JNIEnv *env, jobject thiz, jstring jCommand) {',
                "native_ephemeral_artisan(JNIEnv *env, jobject thiz, jstring jCommand) {\n    pthread_mutex_lock(&g_php_request_mutex);",
                $contents
            );
        }
        // Ephemeral Artisan Success Unlock
        if (! str_contains($contents, 'pthread_mutex_unlock(&g_php_request_mutex);'.PHP_EOL.'    pthread_mutex_unlock(&g_ephemeral_mutex);'.PHP_EOL.'    return result;')) {
            $contents = str_replace(
                'pthread_mutex_unlock(&g_ephemeral_mutex);'.PHP_EOL.'    return result;',
                'pthread_mutex_unlock(&g_php_request_mutex);'.PHP_EOL.'    pthread_mutex_unlock(&g_ephemeral_mutex);'.PHP_EOL.'    return result;',
                $contents
            );
        }
        // Ephemeral Artisan Early Return Fix
        if (! str_contains($contents, 'LOGE("ephemeral_artisan: ephemeral runtime not initialized!");'.PHP_EOL.'        pthread_mutex_unlock(&g_ephemeral_mutex);'.PHP_EOL.'        pthread_mutex_unlock(&g_php_request_mutex);')) {
            $contents = str_replace(
                'LOGE("ephemeral_artisan: ephemeral runtime not initialized!");'.PHP_EOL.'        pthread_mutex_unlock(&g_ephemeral_mutex);',
                'LOGE("ephemeral_artisan: ephemeral runtime not initialized!");'.PHP_EOL.'        pthread_mutex_unlock(&g_ephemeral_mutex);'.PHP_EOL.'        pthread_mutex_unlock(&g_php_request_mutex);',
                $contents
            );
        }

        // 4. Worker Boot
        if (! str_contains($contents, "native_worker_boot(JNIEnv *env, jobject thiz, jstring jBootstrapPath) {\n    pthread_mutex_lock(&g_php_request_mutex);")) {
            $contents = str_replace(
                'native_worker_boot(JNIEnv *env, jobject thiz, jstring jBootstrapPath) {',
                "native_worker_boot(JNIEnv *env, jobject thiz, jstring jBootstrapPath) {\n    pthread_mutex_lock(&g_php_request_mutex);",
                $contents
            );
        }
        // Worker Boot Success Unlock
        if (! str_contains($contents, 'LOGI("worker_boot: worker PHP interpreter ready");'.PHP_EOL.PHP_EOL.'    pthread_mutex_unlock(&g_php_request_mutex);')) {
            $contents = str_replace(
                'LOGI("worker_boot: worker PHP interpreter ready");',
                'LOGI("worker_boot: worker PHP interpreter ready");'.PHP_EOL.PHP_EOL.'    pthread_mutex_unlock(&g_php_request_mutex);',
                $contents
            );
        }
        // Worker Boot Early Return Fix
        if (! str_contains($contents, 'LOGE("worker_boot: timed out waiting for persistent boot to settle");'.PHP_EOL.'        pthread_mutex_unlock(&g_php_request_mutex);')) {
            $contents = str_replace(
                'LOGE("worker_boot: timed out waiting for persistent boot to settle");',
                'LOGE("worker_boot: timed out waiting for persistent boot to settle");'.PHP_EOL.'        pthread_mutex_unlock(&g_php_request_mutex);',
                $contents
            );
        }

        // 5. Worker Artisan
        if (! str_contains($contents, "native_worker_artisan(JNIEnv *env, jobject thiz, jstring jCommand) {\n    pthread_mutex_lock(&g_php_request_mutex);")) {
            $contents = str_replace(
                'native_worker_artisan(JNIEnv *env, jobject thiz, jstring jCommand) {',
                "native_worker_artisan(JNIEnv *env, jobject thiz, jstring jCommand) {\n    pthread_mutex_lock(&g_php_request_mutex);",
                $contents
            );
        }
        // Worker Artisan Success Unlock
        if (! str_contains($contents, 'pthread_mutex_unlock(&g_php_request_mutex);'.PHP_EOL.'    pthread_mutex_unlock(&g_worker_mutex);'.PHP_EOL.'    return result;')) {
            $contents = str_replace(
                'pthread_mutex_unlock(&g_worker_mutex);'.PHP_EOL.'    return result;',
                'pthread_mutex_unlock(&g_php_request_mutex);'.PHP_EOL.'    pthread_mutex_unlock(&g_worker_mutex);'.PHP_EOL.'    return result;',
                $contents
            );
        }
        // Worker Artisan Early Return Fix
        if (! str_contains($contents, 'LOGE("worker_artisan: worker not initialized!");'.PHP_EOL.'        pthread_mutex_unlock(&g_worker_mutex);'.PHP_EOL.'        pthread_mutex_unlock(&g_php_request_mutex);')) {
            $contents = str_replace(
                'LOGE("worker_artisan: worker not initialized!");'.PHP_EOL.'        pthread_mutex_unlock(&g_worker_mutex);',
                'LOGE("worker_artisan: worker not initialized!");'.PHP_EOL.'        pthread_mutex_unlock(&g_worker_mutex);'.PHP_EOL.'        pthread_mutex_unlock(&g_php_request_mutex);',
                $contents
            );
        }

        // 6. Ephemeral Shutdown Early Return Fix
        if (! str_contains($contents, 'LOGI("ephemeral_shutdown: not initialized, nothing to do");'.PHP_EOL.'        pthread_mutex_unlock(&g_ephemeral_mutex);'.PHP_EOL.'        pthread_mutex_unlock(&g_php_request_mutex);')) {
            $contents = str_replace(
                'LOGI("ephemeral_shutdown: not initialized, nothing to do");'.PHP_EOL.'        pthread_mutex_unlock(&g_ephemeral_mutex);',
                'LOGI("ephemeral_shutdown: not initialized, nothing to do");'.PHP_EOL.'        pthread_mutex_unlock(&g_ephemeral_mutex);'.PHP_EOL.'        pthread_mutex_unlock(&g_php_request_mutex);',
                $contents
            );
        }

        // 7. Shared Error Unlocks
        if (str_contains($contents, "LOGI(\"ephemeral_boot: already initialized, skipping\");\n        pthread_mutex_unlock(&g_ephemeral_mutex);")
            && ! str_contains($contents, "LOGI(\"ephemeral_boot: already initialized, skipping\");\n        pthread_mutex_unlock(&g_ephemeral_mutex);\n        pthread_mutex_unlock(&g_php_request_mutex);")) {
            $contents = str_replace(
                "LOGI(\"ephemeral_boot: already initialized, skipping\");\n        pthread_mutex_unlock(&g_ephemeral_mutex);",
                "LOGI(\"ephemeral_boot: already initialized, skipping\");\n        pthread_mutex_unlock(&g_ephemeral_mutex);\n        pthread_mutex_unlock(&g_php_request_mutex);",
                $contents
            );
        }

        if (str_contains($contents, "LOGE(\"ephemeral_boot: ephemeral_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_ephemeral_mutex);")
            && ! str_contains($contents, "LOGE(\"ephemeral_boot: ephemeral_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_ephemeral_mutex);\n        pthread_mutex_unlock(&g_php_request_mutex);")) {
            $contents = str_replace(
                "LOGE(\"ephemeral_boot: ephemeral_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_ephemeral_mutex);",
                "LOGE(\"ephemeral_boot: ephemeral_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_ephemeral_mutex);\n        pthread_mutex_unlock(&g_php_request_mutex);",
                $contents
            );
        }

        // 8. Fix invalid module startup in hot paths (SIGSEGV cause)
        $hotPathStartup = <<<'C'
        if (php_embed_module.startup(&php_embed_module) == FAILURE) {
            LOGE("ephemeral_embed_init: module startup failed");
            return FAILURE;
        }
C;
        $contents = str_replace($hotPathStartup, '', $contents);

        $workerStartup = <<<'C'
    // php_module_startup() is guarded by module_initialized — it won't re-init
    // but it will call sapi_activate() for this thread's context
    if (php_embed_module.startup(&php_embed_module) == FAILURE) {
        LOGE("worker_embed_init: module startup failed");
        return FAILURE;
    }
C;
        $contents = str_replace($workerStartup, '', $contents);

        // 9. Fix more mutex leaks in boot error paths
        if (str_contains($contents, "if (worker_embed_init() != SUCCESS) {\n        LOGE(\"worker_boot: worker_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_worker_mutex);")
            && ! str_contains($contents, "if (worker_embed_init() != SUCCESS) {\n        LOGE(\"worker_boot: worker_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_worker_mutex);\n        pthread_mutex_unlock(&g_php_request_mutex);")) {
            $contents = str_replace(
                "if (worker_embed_init() != SUCCESS) {\n        LOGE(\"worker_boot: worker_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_worker_mutex);",
                "if (worker_embed_init() != SUCCESS) {\n        LOGE(\"worker_boot: worker_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_worker_mutex);\n        pthread_mutex_unlock(&g_php_request_mutex);",
                $contents
            );
        }

        if (str_contains($contents, "if (ephemeral_embed_init() != SUCCESS) {\n        LOGE(\"ephemeral_boot: ephemeral_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_ephemeral_mutex);")
            && ! str_contains($contents, "if (ephemeral_embed_init() != SUCCESS) {\n        LOGE(\"ephemeral_boot: ephemeral_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_ephemeral_mutex);\n        pthread_mutex_unlock(&g_php_request_mutex);")) {
            $contents = str_replace(
                "if (ephemeral_embed_init() != SUCCESS) {\n        LOGE(\"ephemeral_boot: ephemeral_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_ephemeral_mutex);",
                "if (ephemeral_embed_init() != SUCCESS) {\n        LOGE(\"ephemeral_boot: ephemeral_embed_init() FAILED\");\n        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);\n        pthread_mutex_unlock(&g_ephemeral_mutex);\n        pthread_mutex_unlock(&g_php_request_mutex);",
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
            Log.w(TAG, "Persistent runtime state cleared (native shutdown skipped to avoid PHP embed teardown abort)")
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

function patch_mobile_queue_worker_timeout(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/PHPQueueWorker.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPQueueWorker.kt'),
    ];

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);
        $contents = str_replace(
            [
                '"queue:work --once --quiet"',
                '"queue:work --once"',
            ],
            [
                '"queue:work --once --quiet --timeout=360"',
                '"queue:work --once --timeout=360"',
            ],
            $contents
        );

        write_if_changed($target, $contents);
    }
}

function patch_mobile_action_coordinator(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/utils/NativeActionCoordinator.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/utils/NativeActionCoordinator.kt'),
    ];

    $original = <<<'KOTLIN'
package com.nativephp.mobile.utils

import android.util.Log
import android.webkit.WebView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import org.json.JSONObject
KOTLIN;

    $patched = <<<'KOTLIN'
package com.nativephp.mobile.utils

import android.os.Handler
import android.os.Looper
import android.util.Log
import android.webkit.WebView
import androidx.annotation.MainThread
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import org.json.JSONObject
KOTLIN;

    $originalDispatch = <<<'KOTLIN'
    private fun dispatch(event: String, payloadJson: String) {
            Log.d("JSFUNC", "native:$event");
            Log.d("JSFUNC", "$payloadJson");
            val eventForJs = event.replace("\\", "\\\\")
KOTLIN;

    $patchedDispatch = <<<'KOTLIN'
    private fun dispatch(event: String, payloadJson: String) {
            if (Looper.myLooper() != Looper.getMainLooper()) {
                Handler(Looper.getMainLooper()).post {
                    dispatch(event, payloadJson)
                }

                return
            }

            Log.d("JSFUNC", "native:$event");
            Log.d("JSFUNC", "$payloadJson");
            val eventForJs = event.replace("\\", "\\\\")
KOTLIN;

    $originalInstall = <<<'KOTLIN'
        fun install(activity: FragmentActivity): NativeActionCoordinator =
            activity.supportFragmentManager.findFragmentByTag("NativeActionCoordinator") as? NativeActionCoordinator
                ?: NativeActionCoordinator().also {
                    activity.supportFragmentManager.beginTransaction()
                        .add(it, "NativeActionCoordinator")
                        .commitNow()
                }

        /**
         * Dispatch an event to PHP from anywhere in the app
         * This is a helper method for activities/fragments that need to dispatch events
         */
        fun dispatchEvent(activity: FragmentActivity, event: String, payloadJson: String) {
            Log.d("NativeActionCoordinator", "📢 Static dispatch event: $event")
            val coordinator = install(activity)
            coordinator.dispatch(event, payloadJson)
        }
    }
}
KOTLIN;

    $patchedInstall = <<<'KOTLIN'
        private const val TAG = "NativeActionCoordinator"

        @MainThread
        fun install(activity: FragmentActivity): NativeActionCoordinator {
            val fragmentManager = activity.supportFragmentManager

            return fragmentManager.findFragmentByTag(TAG) as? NativeActionCoordinator
                ?: NativeActionCoordinator().also {
                    val transaction = fragmentManager.beginTransaction()
                        .add(it, TAG)

                    if (fragmentManager.isStateSaved) {
                        transaction.commitNowAllowingStateLoss()
                    } else {
                        transaction.commitNow()
                    }
                }
        }

        /**
         * Dispatch an event to PHP from anywhere in the app
         * This is a helper method for activities/fragments that need to dispatch events
         */
        fun dispatchEvent(activity: FragmentActivity, event: String, payloadJson: String) {
            if (Looper.myLooper() != Looper.getMainLooper()) {
                Handler(Looper.getMainLooper()).post {
                    dispatchEvent(activity, event, payloadJson)
                }

                return
            }

            Log.d("NativeActionCoordinator", "📢 Static dispatch event: $event")
            val coordinator = install(activity)
            coordinator.dispatch(event, payloadJson)
        }
    }
}
KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (! str_contains($contents, 'commitNowAllowingStateLoss()')) {
            $contents = replace_or_fail(
                $contents,
                $original,
                $patched,
                'NativeActionCoordinator imports'
            );
        }

        if (! str_contains($contents, 'Looper.myLooper() != Looper.getMainLooper()')) {
            $contents = replace_or_fail(
                $contents,
                $originalDispatch,
                $patchedDispatch,
                'NativeActionCoordinator dispatch main-thread guard'
            );
        }

        if (! str_contains($contents, 'private const val TAG = "NativeActionCoordinator"')) {
            $contents = replace_or_fail(
                $contents,
                $originalInstall,
                $patchedInstall,
                'NativeActionCoordinator install safety'
            );
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_security_csrf_header(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/security/LaravelSecurity.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/security/LaravelSecurity.kt'),
    ];

    $original = <<<'KOTLIN'
    fun applyToHeaders(headers: MutableMap<String, String>) {
        csrfToken?.let {
            headers["X-CSRF-TOKEN"] = it
            Log.d(TAG, "🛡️ Applied CSRF token to headers")
        }

        // Pull XSRF-TOKEN directly from CookieManager for best sync
        val cookieManager = android.webkit.CookieManager.getInstance()
        val cookies = cookieManager.getCookie("http://127.0.0.1")
        if (!cookies.isNullOrBlank()) {
            val xsrfCookie = cookies.split(";")
                .find { it.trim().startsWith("XSRF-TOKEN=") }
                ?.substringAfter("=")
                ?.trim()
            
            if (!xsrfCookie.isNullOrBlank()) {
                headers["X-XSRF-TOKEN"] = Uri.decode(xsrfCookie)
                Log.d(TAG, "🛡️ Applied XSRF token from CookieManager")
            }
        }
    }

    fun get(): String? = csrfToken

    fun set(token: String) {
        csrfToken = token
        Log.d(TAG, "📥 Stored CSRF token manually: $token")
    }

    fun clear() {
        csrfToken = null
        Log.d(TAG, "🧹 Cleared CSRF token")
    }
}
KOTLIN;

    $patched = <<<'KOTLIN'
    fun applyToHeaders(headers: MutableMap<String, String>) {
        // Pull XSRF-TOKEN directly from CookieManager so Android always uses the
        // current session token instead of a cached CSRF header from a previous page.
        val cookieManager = android.webkit.CookieManager.getInstance()
        val cookies = cookieManager.getCookie("http://127.0.0.1")
        if (!cookies.isNullOrBlank()) {
            val xsrfCookie = cookies.split(";")
                .find { it.trim().startsWith("XSRF-TOKEN=") }
                ?.substringAfter("=")
                ?.trim()

            if (!xsrfCookie.isNullOrBlank()) {
                headers["X-XSRF-TOKEN"] = Uri.decode(xsrfCookie)
                Log.d(TAG, "🛡️ Applied XSRF token from CookieManager")
            }
        }
    }
}
KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (str_contains($contents, 'headers["X-CSRF-TOKEN"] = it')) {
            $contents = replace_or_fail(
                $contents,
                $original,
                $patched,
                'LaravelSecurity X-CSRF-TOKEN removal'
            );
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_webview_csrf_bridge(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt'),
    ];

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (str_contains($contents, 'LaravelSecurity.set(token)')) {
            $contents = str_replace(
                [
                    '        LaravelSecurity.set(token)'.PHP_EOL,
                    '                    LaravelSecurity.set(token)'.PHP_EOL,
                    '                        LaravelSecurity.set(token)'.PHP_EOL,
                ],
                '',
                $contents
            );
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_debug_bundle_exclusions(): void
{
    $target = project_path('vendor/nativephp/mobile/src/Traits/PreparesBuild.php');

    if (! file_exists($target)) {
        return;
    }

    $original = <<<'PHP'
            $excludedDirs = match (PHP_OS_FAMILY) {
                'Windows' => array_merge(config('nativephp.cleanup_exclude_files'), ['.git', 'node_modules', 'nativephp', 'vendor/nativephp/mobile/resources']),
                'Linux' => array_merge(config('nativephp.cleanup_exclude_files'), ['.git', 'node_modules', 'nativephp/ios', 'nativephp/android']),
                'Darwin' => array_merge(config('nativephp.cleanup_exclude_files'), ['.git', 'node_modules', 'nativephp/ios', 'nativephp/android']),
                default => config('nativephp.cleanup_exclude_files'),
            };
PHP;

    $patched = <<<'PHP'
            $excludedDirs = match (PHP_OS_FAMILY) {
                'Windows' => array_merge(config('nativephp.cleanup_exclude_files'), ['.git', 'node_modules', 'nativephp', 'vendor/nativephp/mobile/resources']),
                'Linux' => array_merge(config('nativephp.cleanup_exclude_files'), ['.git', 'node_modules', 'nativephp/ios', 'nativephp/android']),
                'Darwin' => array_merge(config('nativephp.cleanup_exclude_files'), ['.git', 'node_modules', 'nativephp/ios', 'nativephp/android']),
                default => config('nativephp.cleanup_exclude_files'),
            };

            if (! $excludeDevDependencies) {
                $excludedDirs = array_values(array_filter(
                    $excludedDirs,
                    static fn (string $path): bool => ! in_array($path, [
                        'vendor/phpunit',
                        'vendor/pestphp',
                        'vendor/mockery',
                        'vendor/phpstan',
                        'vendor/nunomaduro/collision',
                    ], true)
                ));
            }
PHP;

    $contents = (string) file_get_contents($target);

    if (str_contains($contents, $patched)) {
        return;
    }

    $contents = replace_or_fail(
        $contents,
        $original,
        $patched,
        'PreparesBuild excludedDirs'
    );

    write_if_changed($target, $contents);
}

try {

    patch_mobile_firebase_dispatch_command();
    patch_mobile_firebase_android_service();
    patch_mobile_background_tasks_scheduler_lock();
    patch_mobile_firebase_ephemeral_lock();
    patch_mobile_native_mutexes();
    patch_mobile_background_initializer();
    patch_mobile_debug_extraction_marker();
    patch_mobile_debug_bundle_exclusions();
    patch_mobile_persistent_shutdown();
    patch_mobile_queue_worker_timeout();
    patch_mobile_action_coordinator();
    patch_mobile_security_csrf_header();
    patch_mobile_webview_csrf_bridge();
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage().PHP_EOL);

    exit(1);
}
