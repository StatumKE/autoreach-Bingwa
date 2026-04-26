package com.statum.plugins.nativescheduler

import android.content.Context
import android.util.Log

/**
 * Called by NativePHP's generated [PluginBridgeFunctionRegistration.kt] once
 * on every app launch (via the `android.init_function` manifest key).
 *
 * Do not start PHP work from this callback. NativePHP invokes plugin init while
 * the Android shell is still registering bridges, before Laravel extraction,
 * APP_KEY setup, migrations, and the persistent PHP runtime are ready.
 *
 * Scheduling WorkManager work is safe here because it does not touch the PHP
 * runtime directly.
 */
fun initNativeScheduler(context: Context) {
    BingwaScheduler.cancelScheduledWork(context)
    try {
        BingwaSchedulerService.start(context)
        Log.i("NativeSchedulerInit", "Bingwa foreground scheduler service requested")
    } catch (exception: Exception) {
        Log.e("NativeSchedulerInit", "Failed to start Bingwa foreground scheduler service", exception)
        BingwaScheduler.schedule(context)
        BingwaScheduler.enqueueStartupRun(context)
    }
}
