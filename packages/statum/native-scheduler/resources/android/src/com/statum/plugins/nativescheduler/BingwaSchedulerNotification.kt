package com.statum.plugins.nativescheduler

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build
import androidx.core.app.NotificationCompat

internal object BingwaSchedulerNotification {
    const val CHANNEL_ID = "bingwa_scheduler"
    const val FOREGROUND_NOTIFICATION_ID = 5108

    private const val CHANNEL_NAME = "Bingwa scheduler"

    fun create(context: Context): Notification {
        createChannel(context)

        return NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(notificationIcon(context))
            .setContentTitle("Autoreach Bingwa")
            .setContentText("Polling transactions and processing USSD jobs")
            .setOngoing(true)
            .setSilent(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
    }

    private fun createChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return
        }

        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val channel = NotificationChannel(
            CHANNEL_ID,
            CHANNEL_NAME,
            NotificationManager.IMPORTANCE_LOW,
        ).apply {
            description = "Keeps transaction polling and USSD processing active."
            setShowBadge(false)
        }

        manager.createNotificationChannel(channel)
    }

    private fun notificationIcon(context: Context): Int {
        return context.applicationInfo.icon.takeIf { it != 0 } ?: android.R.drawable.stat_notify_sync
    }
}
