package com.statum.plugins.nativescheduler

import android.content.Context
import android.util.Log
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.NetworkType
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

object BingwaScheduler {
    private const val TAG = "BingwaScheduler"
    private const val PERIODIC_WORK_NAME = "bingwa-scheduler-periodic"
    private const val KICKOFF_WORK_NAME = "bingwa-scheduler-kickoff"
    private const val PERIODIC_INTERVAL_MINUTES = 15L
    private const val PERIODIC_FLEX_MINUTES = 15L
    private const val STARTUP_DELAY_SECONDS = 15L
    private val workConstraints = Constraints.Builder()
        .setRequiredNetworkType(NetworkType.CONNECTED)
        .build()

    fun schedule(context: Context): Unit {
        val workManager = WorkManager.getInstance(context.applicationContext)
        val periodicWork = PeriodicWorkRequestBuilder<BingwaSchedulerWorker>(
            PERIODIC_INTERVAL_MINUTES,
            TimeUnit.MINUTES,
            PERIODIC_FLEX_MINUTES,
            TimeUnit.MINUTES,
        ).setConstraints(workConstraints)
            .addTag(PERIODIC_WORK_NAME)
            .build()

        workManager.enqueueUniquePeriodicWork(
            PERIODIC_WORK_NAME,
            ExistingPeriodicWorkPolicy.UPDATE,
            periodicWork,
        )

        Log.i(TAG, "Scheduled periodic Bingwa scheduler work")
    }

    fun enqueueStartupRun(context: Context): Unit {
        val workManager = WorkManager.getInstance(context.applicationContext)
        val request = OneTimeWorkRequestBuilder<BingwaSchedulerWorker>()
            .setConstraints(workConstraints)
            .setInitialDelay(STARTUP_DELAY_SECONDS, TimeUnit.SECONDS)
            .addTag(KICKOFF_WORK_NAME)
            .build()

        // Defer the startup run long enough for Laravel extraction and bridge setup
        // to settle before the worker touches Artisan.
        workManager.enqueueUniqueWork(
            KICKOFF_WORK_NAME,
            ExistingWorkPolicy.REPLACE,
            request,
        )

        Log.i(TAG, "Enqueued startup Bingwa scheduler work")
    }
}
