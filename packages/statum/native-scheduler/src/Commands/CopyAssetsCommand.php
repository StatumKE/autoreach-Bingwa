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
        if ($this->isAndroid()) {
            $this->copyAndroidAssets();
        }

        if ($this->isIos()) {
            $this->copyIosAssets();
        }

        return self::SUCCESS;
    }

    /**
     * Copy assets for Android build.
     */
    protected function copyAndroidAssets(): void
    {
        $this->removeLegacySchedulerServiceIntegration();
        $this->deleteLegacySchedulerFiles();
        $this->patchAndroidReleaseSymbols();

        $this->info('Android assets copied for NativeScheduler');
    }

    /**
     * Remove the old foreground-service scheduler wiring, queue-worker startup, and
     * startup cookie clearing from the generated Android app.
     */
    protected function removeLegacySchedulerServiceIntegration(): void
    {
        $mainActivityPath = $this->buildPath().'/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';

        if (! is_file($mainActivityPath)) {
            $this->warn("MainActivity.kt not found at {$mainActivityPath}; skipping legacy scheduler cleanup.");

            return;
        }

        $contents = file_get_contents($mainActivityPath);

        if ($contents === false) {
            $this->warn("Unable to read {$mainActivityPath}; skipping legacy scheduler cleanup.");

            return;
        }

        $replacements = 0;
        $cookieMethodReplacements = 0;
        $queueWorkerReplacements = 0;
        $onDestroyReplacements = 0;
        $methodReplacements = 0;
        $fieldReplacements = 0;
        $cleanupReplacements = 0;
        $tokenReplacements = 0;

        $updatedContents = str_replace(
            [
                "import android.os.SystemClock\n",
                "import com.nativephp.mobile.bridge.PHPQueueWorker\n",
                "import com.statum.plugins.nativescheduler.ArtisanSchedulerService\n",
                "import com.statum.plugins.nativescheduler.SchedulerStartupState\n",
                "import android.webkit.CookieManager\n",
                "    private var queueWorker: PHPQueueWorker? = null\n",
                "        SchedulerStartupState.appBootstrapComplete = false\n        stopSchedulerBeforeBootstrap()\n",
                "            waitForSchedulerShutdown()\n",
                "            SchedulerStartupState.appBootstrapComplete = true\n            startSchedulerAfterLaravelReady()\n",
                "        SchedulerStartupState.appBootstrapComplete = false\n",
                "                    // Start background queue worker after persistent runtime is ready\n                    queueWorker = PHPQueueWorker(phpBridge).also { it.start() }\n",
                "        clearAllCookies()\n",
            ],
            [
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ],
            $contents,
            $replacements,
        );

        $updatedContents = preg_replace(
            '/^    fun clearAllCookies\(\) \{\R(?:^        .*\R){4}^    \}\R\R/m',
            '',
            $updatedContents,
            1,
            $cookieMethodReplacements,
        ) ?? $updatedContents;

        $updatedContents = str_replace(
            <<<'KOTLIN'
                if (booted) {
                    Log.d("LaravelInit", "Persistent runtime booted in ${bootTime}ms — requests will skip init/shutdown")

                    // Start background queue worker after persistent runtime is ready
                    queueWorker = PHPQueueWorker(phpBridge).also { it.start() }
                } else {
                    Log.w("LaravelInit", "Persistent runtime boot failed after ${bootTime}ms — falling back to classic mode")
                }
KOTLIN,
            <<<'KOTLIN'
                if (booted) {
                    Log.d("LaravelInit", "Persistent runtime booted in ${bootTime}ms — requests will skip init/shutdown")
                } else {
                    Log.w("LaravelInit", "Persistent runtime boot failed after ${bootTime}ms — falling back to classic mode")
                }
KOTLIN,
            $updatedContents,
            $queueWorkerReplacements,
        );

        $updatedContents = str_replace(
            <<<'KOTLIN'
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
KOTLIN,
            <<<'KOTLIN'
    override fun onDestroy() {
        super.onDestroy()
        instance = null

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

        if (phpBridge.isPersistentMode()) {
            Log.d("MainActivity", "Shutting down persistent PHP runtime...")
            phpBridge.shutdownPersistentRuntime()
        }

        laravelEnv.cleanup()
        phpBridge.shutdown()
    }
KOTLIN,
            $updatedContents,
            $onDestroyReplacements,
        );

        $updatedContents = str_replace(
            <<<'KOTLIN'
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

KOTLIN,
            '',
            $updatedContents,
            $methodReplacements,
        );

        $updatedContents = str_replace(
            "    private var queueWorker: PHPQueueWorker? = null\n",
            '',
            $updatedContents,
            $fieldReplacements,
        );

        $cleanupTokens = [
            'PHPQueueWorker',
            'queueWorker',
            'ArtisanSchedulerService',
            'SchedulerStartupState',
            'stopSchedulerBeforeBootstrap()',
            'waitForSchedulerShutdown()',
            'startSchedulerAfterLaravelReady()',
            'SystemClock',
            'Start background queue worker after persistent runtime is ready',
            'Scheduler still active; keeping persistent PHP runtime alive',
        ];

        $cleanupReplacements = 0;

        foreach ($cleanupTokens as $cleanupToken) {
            $updatedContents = preg_replace(
                '/^.*'.preg_quote($cleanupToken, '/').'.*\\R/m',
                '',
                $updatedContents,
                -1,
                $tokenReplacements,
            ) ?? $updatedContents;

            $cleanupReplacements += $tokenReplacements;
        }

        if ($replacements === 0 && $queueWorkerReplacements === 0 && $onDestroyReplacements === 0 && $methodReplacements === 0 && $fieldReplacements === 0 && $cleanupReplacements === 0 && $cookieMethodReplacements === 0) {
            $this->info('Legacy scheduler cleanup already applied.');

            return;
        }

        file_put_contents($mainActivityPath, $updatedContents);
        $this->info('Removed legacy scheduler cleanup from MainActivity.');
    }

    /**
     * Remove stale generated scheduler service files from the Android build.
     */
    protected function deleteLegacySchedulerFiles(): void
    {
        $legacyFiles = [
            $this->buildPath().'/app/src/main/java/com/statum/plugins/nativescheduler/ArtisanSchedulerService.kt',
            $this->buildPath().'/app/src/main/java/com/statum/plugins/nativescheduler/SchedulerStartupState.kt',
        ];

        foreach ($legacyFiles as $legacyFile) {
            if (! is_file($legacyFile)) {
                continue;
            }

            unlink($legacyFile);
            $this->info("Deleted stale scheduler file: {$legacyFile}");
        }
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
     * Copy assets for iOS build.
     */
    protected function copyIosAssets(): void
    {
        $this->info('iOS assets copied for NativeScheduler');
    }
}
