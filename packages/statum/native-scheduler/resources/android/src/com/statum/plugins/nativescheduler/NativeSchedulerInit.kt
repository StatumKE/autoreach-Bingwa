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
 */
fun initNativeScheduler(context: Context) {
    Log.i("NativeSchedulerInit", "Scheduler startup deferred until Laravel is ready")
}
