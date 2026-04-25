package com.statum.plugins.nativescheduler

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.content.pm.ServiceInfo
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.work.CoroutineWorker
import androidx.work.ForegroundInfo
import androidx.work.WorkerParameters
import kotlinx.coroutines.CancellationException
import com.nativephp.mobile.R

class BingwaSchedulerWorker(
    context: Context,
    params: WorkerParameters,
) : CoroutineWorker(context, params) {
    companion object {
        private const val TAG = "BingwaSchedulerWorker"
        private const val NOTIFICATION_ID = 1201
        private const val NOTIFICATION_CHANNEL_ID = "bingwa_scheduler"
    }

    override suspend fun doWork(): Result {
        if (!SchedulerRuntimeState.claimEngine()) {
            Log.i(TAG, "Bingwa runtime is already active; skipping duplicate work")
            return Result.success()
        }

        val runtime = BingwaPhpRuntime(applicationContext)

        return try {
            setForeground(createForegroundInfo())

            if (!runtime.initialize()) {
                return Result.retry()
            }

            val processedJobs = runtime.drainQueuedJobs()
            Log.i(TAG, "Bingwa scheduler run complete: processed $processedJobs job(s)")

            Result.success()
        } catch (exception: CancellationException) {
            Log.w(TAG, "Bingwa scheduler worker cancelled", exception)
            throw exception
        } catch (exception: Exception) {
            Log.e(TAG, "Bingwa scheduler worker failed", exception)
            Result.retry()
        } finally {
            runtime.shutdown()
        }
    }

    override suspend fun getForegroundInfo(): ForegroundInfo = createForegroundInfo()

    private fun createForegroundInfo(): ForegroundInfo {
        ensureNotificationChannel()

        val notification = NotificationCompat.Builder(applicationContext, NOTIFICATION_CHANNEL_ID)
            .setContentTitle(applicationContext.getString(R.string.bingwa_scheduler_notification_title))
            .setContentText(applicationContext.getString(R.string.bingwa_scheduler_notification_text))
            .setSmallIcon(android.R.drawable.stat_notify_sync)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .build()

        return ForegroundInfo(
            NOTIFICATION_ID,
            notification,
            ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC,
        )
    }

    private fun ensureNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return
        }

        val notificationManager =
            applicationContext.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val channel = NotificationChannel(
            NOTIFICATION_CHANNEL_ID,
            applicationContext.getString(R.string.bingwa_scheduler_notification_channel_name),
            NotificationManager.IMPORTANCE_LOW,
        ).apply {
            description = applicationContext.getString(R.string.bingwa_scheduler_notification_text)
            setShowBadge(false)
        }

        notificationManager.createNotificationChannel(channel)
    }
}
