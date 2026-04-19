package com.statum.plugins.nativescheduler

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * Listens for [Intent.ACTION_BOOT_COMPLETED]. The scheduler is intentionally
 * started only after the app opens and Laravel has finished bootstrapping.
 */
class SchedulerBootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            Log.i("SchedulerBootReceiver", "Boot completed; scheduler starts after the next app launch")
        }
    }
}
