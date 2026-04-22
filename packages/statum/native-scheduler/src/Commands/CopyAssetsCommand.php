<?php

namespace Statum\NativeScheduler\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

/**
 * Copy assets hook command for NativeScheduler plugin.
 *
 * This hook runs during the copy_assets phase of the build process.
 * Use it to copy ML models, binary files, or other assets that need
 * to be in specific locations in the native project.
 *
 * @see NativePluginHookCommand
 */
class CopyAssetsCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:native-scheduler:copy-assets';

    protected $description = 'Copy assets for NativeScheduler plugin';

    public function handle(): int
    {
        // Example: Copy different files based on platform
        if ($this->isAndroid()) {
            $this->copyAndroidAssets();
        }

        if ($this->isIos()) {
            $this->copyIosAssets();
        }

        return self::SUCCESS;
    }

    /**
     * Copy assets for Android build
     */
    protected function copyAndroidAssets(): void
    {
        // Example: Copy a TensorFlow Lite model to Android assets
        // $this->copyToAndroidAssets('model.tflite', 'model.tflite');

        // Example: Download a model if not present locally
        // $modelPath = $this->pluginPath() . '/resources/model.tflite';
        // $this->downloadIfMissing(
        //     'https://example.com/model.tflite',
        //     $modelPath
        // );
        // $this->copyToAndroidAssets('model.tflite', 'model.tflite');

        $this->patchMainActivityRuntimeShutdown();
        $this->patchAndroidOkHttpDependency();
        $this->patchAndroidReleaseSymbols();

        $this->info('Android assets copied for NativeScheduler');
    }

    /**
     * Ensure the generated Android project can send native PATCH callbacks.
     */
    protected function patchAndroidOkHttpDependency(): void
    {
        $buildGradlePath = $this->buildPath().'/app/build.gradle.kts';

        if (! is_file($buildGradlePath)) {
            $this->warn("Android app build.gradle.kts not found at {$buildGradlePath}; skipping OkHttp dependency patch.");

            return;
        }

        $contents = file_get_contents($buildGradlePath);

        if ($contents === false) {
            $this->warn("Unable to read {$buildGradlePath}; skipping OkHttp dependency patch.");

            return;
        }

        if (str_contains($contents, 'com.squareup.okhttp3:okhttp')) {
            $this->info('OkHttp dependency already present.');

            return;
        }

        $contents = str_replace(
            <<<'KOTLIN'
    // Gson for JSON handling
    implementation("com.google.code.gson:gson:2.10.1")
KOTLIN,
            <<<'KOTLIN'
    // Gson for JSON handling
    implementation("com.google.code.gson:gson:2.10.1")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
KOTLIN,
            $contents,
            $replacements,
        );

        if ($replacements === 0) {
            $contents = str_replace(
                <<<'KOTLIN'
dependencies {
KOTLIN,
                <<<'KOTLIN'
dependencies {
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
KOTLIN,
                $contents,
                $replacements,
            );
        }

        if ($replacements === 0) {
            $this->warn('Unable to find dependencies block in Android build.gradle.kts; no OkHttp dependency patch applied.');

            return;
        }

        file_put_contents($buildGradlePath, $contents);
        $this->info('Patched Android OkHttp dependency for USSD status callbacks.');
    }

    /**
     * Strip release-native debug symbols from the shipped APK when configured.
     */
    protected function patchAndroidReleaseSymbols(): void
    {
        $debugSymbols = strtoupper((string) config('nativephp.android.build.debug_symbols', 'FULL'));
        if ($debugSymbols === 'FULL') {
            return;
        }

        $buildGradlePath = $this->buildPath().'/app/build.gradle.kts';

        if (! is_file($buildGradlePath)) {
            $this->warn("Android app build.gradle.kts not found at {$buildGradlePath}; skipping release symbol patch.");

            return;
        }

        $contents = file_get_contents($buildGradlePath);

        if ($contents === false) {
            $this->warn("Unable to read {$buildGradlePath}; skipping release symbol patch.");

            return;
        }

        $updatedContents = str_replace(
            "            keepDebugSymbols.add(\"**/*.so\")\n",
            '',
            $contents,
            $replacements,
        );

        if ($replacements === 0) {
            $this->info('Android release symbol patch already applied.');

            return;
        }

        file_put_contents($buildGradlePath, $updatedContents);
        $this->info("Patched Android release symbol packaging for {$debugSymbols} debug symbols.");
    }

    /**
     * Keep the Android scheduler's ephemeral PHP runtime alive during Activity teardown.
     */
    protected function patchMainActivityRuntimeShutdown(): void
    {
        $mainActivityPath = $this->buildPath().'/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';

        if (! is_file($mainActivityPath)) {
            $this->warn("MainActivity.kt not found at {$mainActivityPath}; skipping scheduler runtime patch.");

            return;
        }

        $contents = file_get_contents($mainActivityPath);

        if ($contents === false) {
            $this->warn("Unable to read {$mainActivityPath}; skipping scheduler runtime patch.");

            return;
        }

        if (str_contains($contents, 'val schedulerActive = com.statum.plugins.nativescheduler.ArtisanSchedulerService.engineActive')) {
            $this->info('MainActivity scheduler runtime patch already applied.');

            return;
        }

        $contents = str_replace(
            <<<'KOTLIN'
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
KOTLIN,
            <<<'KOTLIN'
        val schedulerActive = com.statum.plugins.nativescheduler.ArtisanSchedulerService.engineActive

        // 5. The Activity owns the persistent UI runtime unless the foreground
        // scheduler is still using PHP in this process.
        if (phpBridge.isPersistentMode()) {
            if (schedulerActive) {
                Log.d("MainActivity", "Scheduler still active; keeping persistent PHP runtime alive")
            } else {
                Log.d("MainActivity", "Shutting down persistent PHP runtime...")
                phpBridge.shutdownPersistentRuntime()
            }
        }

        // 6. Final Global Cleanup
        if (schedulerActive) {
            Log.d("MainActivity", "Scheduler still active; skipping global PHP bridge cleanup")
        } else {
            Log.d("MainActivity", "Final PHP bridge global cleanup")
            laravelEnv.cleanup()
            phpBridge.shutdown()
        }
KOTLIN,
            $contents,
            $replacements,
        );

        if ($replacements === 0) {
            $contents = str_replace(
                <<<'KOTLIN'
        laravelEnv.cleanup()
        phpBridge.shutdown()
KOTLIN,
                <<<'KOTLIN'
        if (com.statum.plugins.nativescheduler.ArtisanSchedulerService.engineActive) {
            Log.d("MainActivity", "Scheduler still active; skipping global PHP bridge cleanup")
        } else {
            laravelEnv.cleanup()
            phpBridge.shutdown()
        }
KOTLIN,
                $contents,
                $replacements,
            );
        }

        if ($replacements === 0) {
            $this->warn('MainActivity runtime shutdown block did not match known patterns; no patch applied.');

            return;
        }

        file_put_contents($mainActivityPath, $contents);
        $this->info('Patched MainActivity scheduler runtime shutdown guard.');
    }

    /**
     * Copy assets for iOS build
     */
    protected function copyIosAssets(): void
    {
        // Example: Copy a Core ML model to iOS bundle
        // $this->copyToIosBundle('model.mlmodelc', 'model.mlmodelc');

        $this->info('iOS assets copied for NativeScheduler');
    }
}
