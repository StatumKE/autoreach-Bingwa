package com.nativephp.mobile.runtime

import android.app.job.JobInfo
import android.app.job.JobScheduler
import android.content.ComponentName
import android.content.Context
import android.os.Build
import android.util.Log

object BackgroundRuntimeSyncScheduler {
    private const val TAG = "BackgroundRuntimeSync"
    private const val PERIODIC_JOB_ID = 48_601
    private const val IMMEDIATE_JOB_ID = 48_602
    private const val NETWORK_TYPE = JobInfo.NETWORK_TYPE_ANY

    const val INTERVAL_MILLIS = 15 * 60 * 1000L
    const val FLEX_MILLIS = 5 * 60 * 1000L

    fun schedule(context: Context) {
        val scheduler = context.getSystemService(JobScheduler::class.java) ?: return
        val service = ComponentName(context, BackgroundRuntimeSyncJobService::class.java)
        val jobInfo = JobInfo.Builder(PERIODIC_JOB_ID, service)
            .setRequiredNetworkType(NETWORK_TYPE)
            .setPersisted(true)
            .setPeriodic(INTERVAL_MILLIS, FLEX_MILLIS)
            .build()

        scheduler.schedule(jobInfo)
        Log.i(TAG, "Scheduled background runtime sync job every 15 minutes.")
    }

    fun scheduleImmediate(context: Context) {
        val scheduler = context.getSystemService(JobScheduler::class.java) ?: return
        val service = ComponentName(context, BackgroundRuntimeSyncJobService::class.java)
        val jobInfo = immediateJobInfo(service)

        if (scheduler.schedule(jobInfo) == JobScheduler.RESULT_FAILURE && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            Log.w(TAG, "Expedited background runtime sync scheduling failed; falling back to a regular immediate job.")
            scheduler.schedule(regularImmediateJobInfo(service))
            return
        }

        Log.i(TAG, "Scheduled immediate background runtime sync job.")
    }

    private fun immediateJobInfo(service: ComponentName): JobInfo {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            JobInfo.Builder(IMMEDIATE_JOB_ID, service)
                .setRequiredNetworkType(NETWORK_TYPE)
                .setExpedited(true)
                .build()
        } else {
            regularImmediateJobInfo(service)
        }
    }

    private fun regularImmediateJobInfo(service: ComponentName): JobInfo {
        return JobInfo.Builder(IMMEDIATE_JOB_ID, service)
            .setRequiredNetworkType(NETWORK_TYPE)
            .setMinimumLatency(0L)
            .build()
    }
}
