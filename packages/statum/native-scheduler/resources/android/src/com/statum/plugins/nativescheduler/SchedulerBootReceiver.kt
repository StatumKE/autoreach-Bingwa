package com.statum.plugins.nativescheduler

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log

/**
 * Listens for [Intent.ACTION_BOOT_COMPLETED] and restarts the scheduler
 * service automatically so syncing resumes after a device reboot.
 */
class SchedulerBootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            Log.i("SchedulerBootReceiver", "Boot completed — restarting ArtisanSchedulerService")
            val serviceIntent = Intent(context, ArtisanSchedulerService::class.java)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(serviceIntent)
            } else {
                context.startService(serviceIntent)
            }
        }
    }
}
