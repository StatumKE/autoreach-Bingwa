package com.statum.plugins.nativescheduler

import android.content.Context

/**
 * Called by NativePHP's generated [PluginBridgeFunctionRegistration.kt] once
 * on every app launch (via the `android.init_function` manifest key).
 *
 * Starts [ArtisanSchedulerService] as a Foreground Service so that the
 * Laravel Artisan command runs every 5 seconds even when the app is
 * backgrounded and the user is not logged in.
 */
fun initNativeScheduler(context: Context) {
    ArtisanSchedulerService.start(context)
}
