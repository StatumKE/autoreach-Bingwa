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
    BingwaScheduler.schedule(context)
    BingwaScheduler.enqueueStartupRun(context)
    Log.i("NativeSchedulerInit", "Bingwa scheduler work queued")
}
