package com.nativephp.mobile.runtime

import android.app.job.JobParameters
import android.app.job.JobService
import android.util.Log
import com.nativephp.mobile.bridge.LaravelRuntimeBridgeProvider
import com.nativephp.mobile.network.InternalLaravelRequestClient

class BackgroundRuntimeSyncJobService : JobService() {
    companion object {
        private const val TAG = "BackgroundRuntimeJob"
    }

    override fun onStartJob(params: JobParameters): Boolean {
        Thread {
            try {
                if (LaravelRuntimeBridgeProvider.isInitializing()) {
                    Log.i(
                        TAG,
                        "Laravel runtime is already booting in another component; deferring background runtime tick.",
                    )
                    BackgroundRuntimeSyncScheduler.scheduleImmediate(this)
                    return@Thread
                }

                val result = RuntimeWakeLock.withLock(this, "background-runtime-job") {
                    runRuntimeTickWithRecovery()
                }

                Log.d(TAG, "Background runtime tick completed [status=${result.statusCode}]")
            } catch (exception: Exception) {
                Log.e(TAG, "Background runtime tick failed: ${exception.message}", exception)
                BackgroundRuntimeSyncScheduler.scheduleImmediate(this)
            } finally {
                jobFinished(params, false)
            }
        }.start()

        return true
    }

    override fun onStopJob(params: JobParameters): Boolean {
        return true
    }

    private fun runRuntimeTickWithRecovery(): InternalLaravelRequestClient.Result {
        return try {
            val bridge = LaravelRuntimeBridgeProvider.get(this)
            val result = InternalLaravelRequestClient.postJson(
                phpBridge = bridge,
                path = "/api/v1/native/runtime/tick",
                payload = "{}",
                headers = mapOf("X-Bingwa-Runtime" to "android"),
            )

            if (!result.successful) {
                LaravelRuntimeBridgeProvider.reset(shutdown = true)
            }

            result
        } catch (exception: Exception) {
            LaravelRuntimeBridgeProvider.reset(shutdown = true)
            throw exception
        }
    }
}
