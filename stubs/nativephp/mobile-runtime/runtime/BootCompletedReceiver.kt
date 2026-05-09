package com.nativephp.mobile.runtime

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

class BootCompletedReceiver : BroadcastReceiver() {
    companion object {
        private const val TAG = "BootCompletedReceiver"
    }

    override fun onReceive(context: Context, intent: Intent?) {
        val action = intent?.action ?: return

        if (action != Intent.ACTION_BOOT_COMPLETED && action != Intent.ACTION_MY_PACKAGE_REPLACED) {
            return
        }

        Log.i(TAG, "Scheduling runtime sync after boot action=$action")
        BackgroundRuntimeSyncScheduler.schedule(context)
        BackgroundRuntimeSyncScheduler.scheduleImmediate(context)
    }
}
