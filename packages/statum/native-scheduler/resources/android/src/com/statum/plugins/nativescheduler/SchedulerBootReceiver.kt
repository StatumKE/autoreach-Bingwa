package com.statum.plugins.nativescheduler

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * Listens for [Intent.ACTION_BOOT_COMPLETED] and restarts the scheduler
 * work automatically so syncing resumes after a device reboot.
 */
class SchedulerBootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            BingwaScheduler.schedule(context)
            BingwaScheduler.enqueueStartupRun(context)
            Log.i("SchedulerBootReceiver", "Boot completed — Bingwa work queued")
        }
    }
}
