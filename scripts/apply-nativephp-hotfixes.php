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
import android.content.Intent
import android.os.Build
import android.util.Log
import androidx.work.Worker
import androidx.work.WorkerParameters

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

        Log.i(TAG, "Executing scheduled command via PHPQueueService: $command")

        val serviceIntent = Intent(applicationContext, com.nativephp.mobile.bridge.PHPQueueService::class.java).apply {
            action = "RUN_COMMAND"
            putExtra("command", command)
        }
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                applicationContext.startForegroundService(serviceIntent)
            } else {
                applicationContext.startService(serviceIntent)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to start PHPQueueService for command: $command", e)
            return Result.failure()
        }
        return Result.success()
    }
}
KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }
        write_if_changed($target, $patched);
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

    copy_stub_to_targets(project_path('patches/php_bridge.c'), $targets);
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
                'workerThread = Thread({',
                '}, "php-queue-worker").apply {',
            ],
            [
                '"queue:work --once --quiet --timeout=360"',
                '"queue:work --once --timeout=360"',
                'workerThread = Thread(null, {',
                '}, "php-queue-worker", 8 * 1024 * 1024).apply {',
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

function patch_mobile_phpbridge_executor(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt'),
    ];

    $originalProp = <<<'KOTLIN'
    private val postDataByKey = ConcurrentHashMap<String, String>()
    private val phpExecutor = java.util.concurrent.Executors.newSingleThreadExecutor()
KOTLIN;

    $patchedProp = <<<'KOTLIN'
    private val postDataByKey = ConcurrentHashMap<String, String>()
KOTLIN;

    $originalCompanion = <<<'KOTLIN'
    companion object {
        private const val TAG = "PHPBridge"
        private const val MAX_REQUEST_AGE = 5 * 60 * 1000L
        val phpLock = Any()

        init {
KOTLIN;

    $patchedCompanion = <<<'KOTLIN'
    companion object {
        private const val TAG = "PHPBridge"
        private const val MAX_REQUEST_AGE = 5 * 60 * 1000L
        val phpLock = Any()
        val phpExecutor = java.util.concurrent.Executors.newSingleThreadExecutor { runnable ->
            Thread(null, runnable, "php-executor", 8 * 1024 * 1024)
        }

        init {
KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (str_contains($contents, 'private val phpExecutor =')) {
            $contents = replace_or_fail($contents, $originalProp, $patchedProp, 'PHPBridge phpExecutor prop removal');
            $contents = replace_or_fail($contents, $originalCompanion, $patchedCompanion, 'PHPBridge phpExecutor companion addition');
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_runtime_terminate(): void
{
    $target = project_path('vendor/nativephp/mobile/src/Runtime.php');

    if (! file_exists($target)) {
        return;
    }

    $original = <<<'PHP'
        // Handle the request through the kernel
        try {
            $response = static::$kernel->handle($request);
        } catch (\Throwable $e) {
            $response = new Response(
                'Error: '.$e->getMessage()."\n".$e->getTraceAsString(),
                500,
                ['Content-Type' => 'text/plain']
            );
        }

        // Terminate (fires terminable middleware)
        static::$kernel->terminate($request, $response);

        return $response;
PHP;

    $patched = <<<'PHP'
        // Handle the request through the kernel
        try {
            $response = static::$kernel->handle($request);
            try {
                // Terminate (fires terminable middleware)
                static::$kernel->terminate($request, $response);
            } catch (\Throwable $e) {
                // Terminate failures (e.g. from terminable middleware) shouldn't crash the request response
            }
        } catch (\Throwable $e) {
            $response = new Response(
                'Error: '.$e->getMessage()."\n".$e->getTraceAsString(),
                500,
                ['Content-Type' => 'text/plain']
            );
        }

        return $response;
PHP;

    $contents = (string) file_get_contents($target);

    if (str_contains($contents, 'static::$kernel->terminate($request, $response);') && ! str_contains($contents, 'Terminate failures (e.g. from terminable middleware)')) {
        $contents = replace_or_fail(
            $contents,
            $original,
            $patched,
            'Runtime terminate safety'
        );
        write_if_changed($target, $contents);
    }
}

function patch_mobile_manifest_queue_service(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/AndroidManifest.xml'),
        project_path('nativephp/android/app/src/main/AndroidManifest.xml'),
    ];

    $serviceDef = <<<'XML'
        <service
            android:name="com.nativephp.mobile.bridge.PHPQueueService"
            android:process=":queue"
            android:foregroundServiceType="dataSync"
            android:exported="false" />
XML;

    // The AndroidPluginCompiler::mergeManifestEntries() wipes everything between
    // "<!-- NativePHP Plugin Components -->" and "</application>" on every build.
    // PHPQueueService must therefore live BEFORE that comment to survive each rebuild.
    $safeAnchor = '<!-- NativePHP Plugin Components -->';
    $fallbackAnchor = '</application>';

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (str_contains($contents, 'com.nativephp.mobile.bridge.PHPQueueService')) {
            // Already injected — ensure it has the correct attributes (foregroundServiceType)
            // and is in the safe zone (before plugin comment).
            $pluginCommentPos = strpos($contents, $safeAnchor);
            $servicePos = strpos($contents, 'com.nativephp.mobile.bridge.PHPQueueService');
            $hasCorrectAttrs = str_contains($contents, 'android:foregroundServiceType="dataSync"') && str_contains($contents, 'android:process=":queue"');

            if (! $hasCorrectAttrs || ($pluginCommentPos !== false && $servicePos !== false && $servicePos > $pluginCommentPos)) {
                // Remove the old/mis-placed entry
                $contents = preg_replace(
                    '/\s*<service[^>]*com\.nativephp\.mobile\.bridge\.PHPQueueService[^>]*\/>/',
                    '',
                    $contents
                );
                // Re-inject in the safe zone
                if (str_contains($contents, $safeAnchor)) {
                    $contents = str_replace(
                        $safeAnchor,
                        $serviceDef.PHP_EOL.'        '.$safeAnchor,
                        $contents
                    );
                } elseif (str_contains($contents, $fallbackAnchor)) {
                    $contents = str_replace(
                        $fallbackAnchor,
                        $serviceDef.PHP_EOL.'    '.$fallbackAnchor,
                        $contents
                    );
                }
                write_if_changed($target, $contents);
            }

            continue;
        }

        // Not present at all — inject before the plugin-components comment (safe zone)
        // or fall back to before </application> for templates that have no plugin comment yet.
        if (str_contains($contents, $safeAnchor)) {
            $contents = str_replace(
                $safeAnchor,
                $serviceDef.PHP_EOL.'        '.$safeAnchor,
                $contents
            );
        } elseif (str_contains($contents, $fallbackAnchor)) {
            $contents = str_replace(
                $fallbackAnchor,
                $serviceDef.PHP_EOL.'    '.$fallbackAnchor,
                $contents
            );
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_manifest_battery_and_foreground(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/AndroidManifest.xml'),
        project_path('nativephp/android/app/src/main/AndroidManifest.xml'),
    ];

    $permissions = <<<'XML'
    <uses-permission android:name="android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE_DATA_SYNC" />
XML;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (! str_contains($contents, 'android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS')) {
            $contents = replace_or_fail(
                $contents,
                '<uses-permission android:name="android.permission.INTERNET" />',
                '<uses-permission android:name="android.permission.INTERNET" />'.PHP_EOL.$permissions,
                'AndroidManifest permissions injection'
            );
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_queue_service_foreground(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/PHPQueueService.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPQueueService.kt'),
    ];

    $contents = <<<'KOTLIN'
package com.nativephp.mobile.bridge

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.IBinder
import android.util.Log
import androidx.core.app.NotificationCompat
import com.nativephp.mobile.bridge.plugins.registerContextOnlyBridgeFunctions

class PHPQueueService : Service() {
    private var queueWorker: PHPQueueWorker? = null

    companion object {
        private const val TAG = "PHPQueueService"
        private const val CHANNEL_ID = "PHPQueueServiceChannel"
    }

    override fun onCreate() {
        super.onCreate()
        Log.i(TAG, "QueueService created in separate process")
        createNotificationChannel()
        val notification: Notification = NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("Autoreach Bingwa")
            .setContentText("Status: Running")
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
        
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                startForeground(1, notification, android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC)
            } else {
                startForeground(1, notification)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to start foreground service", e)
        }
        queueWorker = PHPQueueWorker(applicationContext)
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val serviceChannel = NotificationChannel(
                CHANNEL_ID,
                "Background Processing",
                NotificationManager.IMPORTANCE_LOW
            )
            val manager = getSystemService(NotificationManager::class.java)
            manager?.createNotificationChannel(serviceChannel)
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent != null && intent.action == "RUN_COMMAND") {
            val command = intent.getStringExtra("command")
            if (command != null) {
                Log.i(TAG, "QueueService executing command: $command")
                Thread {
                    synchronized(PHPBridge.phpLock) {
                        try {
                            if (!BridgeFunctionRegistry.shared.exists("BackgroundTasks.Register")) {
                                Log.i(TAG, "Registering context-only bridge functions in queue process")
                                registerContextOnlyBridgeFunctions(applicationContext)
                            }
                            val env = LaravelEnvironment(applicationContext)
                            env.initializeForBackground()
                            val phpBridge = PHPBridge(applicationContext)
                            val booted = phpBridge.nativeEphemeralBoot(
                                "${phpBridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
                            )
                            if (booted == 0) {
                                try {
                                    val output = phpBridge.nativeEphemeralArtisan(command)
                                    Log.i(TAG, "Command execution completed: $command")
                                } finally {
                                    phpBridge.nativeEphemeralShutdown()
                                }
                            } else {
                                Log.e(TAG, "Failed to boot ephemeral runtime for command: $command")
                            }
                        } catch (e: Exception) {
                            Log.e(TAG, "Exception running command: $command", e)
                        }
                    }
                }.start()
            }
        } else {
            Log.i(TAG, "QueueService starting worker thread")
            queueWorker?.start()
        }
        return START_STICKY
    }

    override fun onDestroy() {
        Log.i(TAG, "QueueService destroying, stopping worker thread")
        queueWorker?.stop()
        queueWorker = null
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? {
        return null
    }
}
KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }
        write_if_changed($target, $contents);
    }
}

function patch_mobile_main_activity_battery(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'),
    ];

    $originalOnCreateStart = <<<'KOTLIN'
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        instance = this

        // Android 15 edge-to-edge compatibility fix
KOTLIN;

    $patchedOnCreateStart = <<<'KOTLIN'
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        instance = this

        // Request Ignore Battery Optimizations to ensure background scheduler/queues aren't killed
        try {
            val intent = Intent()
            val packageName = packageName
            val pm = getSystemService(android.content.Context.POWER_SERVICE) as android.os.PowerManager
            if (!pm.isIgnoringBatteryOptimizations(packageName)) {
                intent.action = android.provider.Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS
                intent.data = android.net.Uri.parse("package:$packageName")
                startActivity(intent)
            }
        } catch (e: Exception) {
            Log.e("BatteryOpt", "Failed to request battery optimization ignore", e)
        }

        // Android 15 edge-to-edge compatibility fix
KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (! str_contains($contents, 'isIgnoringBatteryOptimizations(')) {
            $contents = replace_or_fail(
                $contents,
                $originalOnCreateStart,
                $patchedOnCreateStart,
                'MainActivity Battery Optimization prompt'
            );
        }

        write_if_changed($target, $contents);
    }
}

function write_mobile_main_application(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/MainApplication.kt'),
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/MainApplication.kt'),
    ];

    $contents = <<<'KOTLIN'
package com.nativephp.mobile

import android.app.Application
import android.util.Log
import androidx.work.Configuration

class MainApplication : Application(), Configuration.Provider {
    companion object {
        private const val TAG = "MainApplication"
    }

    override fun onCreate() {
        super.onCreate()
        Log.i(TAG, "MainApplication onCreate - process: ${android.os.Process.myPid()}")
    }

    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder()
            .setMinimumLoggingLevel(Log.INFO)
            .build()
}
KOTLIN;

    foreach ($targets as $target) {
        write_if_changed($target, $contents);
    }
}

function patch_mobile_manifest_workmanager_and_application(): void
{
    $targets = [
        project_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/AndroidManifest.xml'),
        project_path('nativephp/android/app/src/main/AndroidManifest.xml'),
    ];

    $providerDef = <<<'XML'
        <provider
            android:name="androidx.startup.InitializationProvider"
            android:authorities="${applicationId}.androidx-startup"
            android:exported="false"
            tools:node="merge">
            <meta-data
                android:name="androidx.work.WorkManagerInitializer"
                android:value="androidx.startup"
                tools:node="remove" />
        </provider>
XML;

    $safeAnchor = '<!-- NativePHP Plugin Components -->';
    $fallbackAnchor = '</application>';

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (! str_contains($contents, 'android:name="com.nativephp.mobile.MainApplication"')) {
            $contents = replace_or_fail(
                $contents,
                '<application',
                '<application'.PHP_EOL.'        android:name="com.nativephp.mobile.MainApplication"',
                'AndroidManifest Application class'
            );
        }

        if (! str_contains($contents, 'androidx.work.WorkManagerInitializer')) {
            if (str_contains($contents, $safeAnchor)) {
                $contents = str_replace(
                    $safeAnchor,
                    $providerDef.PHP_EOL.'        '.$safeAnchor,
                    $contents
                );
            } elseif (str_contains($contents, $fallbackAnchor)) {
                $contents = str_replace(
                    $fallbackAnchor,
                    $providerDef.PHP_EOL.'    '.$fallbackAnchor,
                    $contents
                );
            }
        }

        write_if_changed($target, $contents);
    }
}

function patch_mobile_firebase_nativephp_json(): void
{
    $target = project_path('vendor/nativephp/mobile-firebase/nativephp.json');

    if (! file_exists($target)) {
        return;
    }

    $contents = (string) file_get_contents($target);

    $originalGetToken = <<<'JSON'
        {
            "name": "PushNotification.GetToken",
            "android": "com.nativephp.firebase.PushNotificationFunctions.GetToken",
            "ios": "PushNotificationFunctions.GetToken",
            "description": "Get the current push notification token"
        }
JSON;

    $patchedGetToken = <<<'JSON'
        {
            "name": "PushNotification.GetToken",
            "android": "com.nativephp.firebase.PushNotificationFunctions.GetToken",
            "android_params": [
                "context"
            ],
            "ios": "PushNotificationFunctions.GetToken",
            "description": "Get the current push notification token"
        }
JSON;

    $originalCheckPermission = <<<'JSON'
        {
            "name": "PushNotification.CheckPermission",
            "android": "com.nativephp.firebase.PushNotificationFunctions.CheckPermission",
            "ios": "PushNotificationFunctions.CheckPermission",
            "description": "Check current push notification permission status without prompting the user"
        }
JSON;

    $patchedCheckPermission = <<<'JSON'
        {
            "name": "PushNotification.CheckPermission",
            "android": "com.nativephp.firebase.PushNotificationFunctions.CheckPermission",
            "android_params": [
                "context"
            ],
            "ios": "PushNotificationFunctions.CheckPermission",
            "description": "Check current push notification permission status without prompting the user"
        }
JSON;

    if (! str_contains($contents, '"android_params"')) {
        $contents = replace_or_fail($contents, $originalGetToken, $patchedGetToken, 'mobile-firebase nativephp.json GetToken params');
        $contents = replace_or_fail($contents, $originalCheckPermission, $patchedCheckPermission, 'mobile-firebase nativephp.json CheckPermission params');
    }

    write_if_changed($target, $contents);
}

function patch_mobile_firebase_context_registrations(): void
{
    $targets = [
        project_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt'),
    ];

    $original = <<<'KOTLIN'
fun registerContextOnlyBridgeFunctions(context: Context) {
    val registry = BridgeFunctionRegistry.shared
KOTLIN;

    $patched = <<<'KOTLIN'
fun registerContextOnlyBridgeFunctions(context: Context) {
    val registry = BridgeFunctionRegistry.shared

    // Plugin: nativephp/mobile-firebase
    registry.register("PushNotification.GetToken", PushNotificationFunctions.GetToken(context))

    // Plugin: nativephp/mobile-firebase
    registry.register("PushNotification.CheckPermission", PushNotificationFunctions.CheckPermission(context))
KOTLIN;

    foreach ($targets as $target) {
        if (! file_exists($target)) {
            continue;
        }

        $contents = (string) file_get_contents($target);

        if (! str_contains($contents, 'PushNotificationFunctions.GetToken(context)')) {
            $contents = replace_or_fail(
                $contents,
                $original,
                $patched,
                'PluginBridgeFunctionRegistration background PushNotification registration'
            );
        }

        write_if_changed($target, $contents);
    }
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
    patch_mobile_phpbridge_executor();
    patch_mobile_runtime_terminate();
    patch_mobile_manifest_queue_service();
    patch_mobile_manifest_battery_and_foreground();
    patch_mobile_queue_service_foreground();
    patch_mobile_main_activity_battery();
    write_mobile_main_application();
    patch_mobile_manifest_workmanager_and_application();
    patch_mobile_firebase_nativephp_json();
    patch_mobile_firebase_context_registrations();
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage().PHP_EOL);

    exit(1);
}
