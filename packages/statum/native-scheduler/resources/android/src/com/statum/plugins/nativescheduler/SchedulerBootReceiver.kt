package com.statum.plugins.nativescheduler

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * Listens for [Intent.ACTION_BOOT_COMPLETED] and restarts [ArtisanSchedulerService]
 * so that background syncing resumes automatically after a device reboot.
 */
class SchedulerBootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            Log.i("SchedulerBootReceiver", "Boot completed — starting ArtisanSchedulerService")
            ArtisanSchedulerService.start(context)
        }
    }
}
