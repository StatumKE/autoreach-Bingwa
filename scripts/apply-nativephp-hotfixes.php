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
        echo "NativePHP hotfix skipped: snippet not found for {$label}." . PHP_EOL;
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
    patch_mobile_background_initializer();
    patch_mobile_debug_extraction_marker();
    patch_mobile_debug_bundle_exclusions();
    patch_mobile_persistent_shutdown();
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage().PHP_EOL);

    exit(1);
}
