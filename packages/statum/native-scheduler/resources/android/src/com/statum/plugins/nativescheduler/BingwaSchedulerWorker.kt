package com.statum.plugins.nativescheduler

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import kotlinx.coroutines.CancellationException

class BingwaSchedulerWorker(
    context: Context,
    params: WorkerParameters,
) : CoroutineWorker(context, params) {
    companion object {
        private const val TAG = "BingwaSchedulerWorker"
    }

    override suspend fun doWork(): Result {
        val engineOwner = "worker-$id"

        if (!SchedulerRuntimeState.claimEngine(engineOwner)) {
            Log.i(TAG, "Bingwa runtime is already active; skipping duplicate work")
            return Result.success()
        }

        val runtime = BingwaPhpRuntime(applicationContext, engineOwner)

        return try {
            if (!runtime.initialize()) {
                return Result.retry()
            }

            val processedJobs = runtime.runSingleCycle()
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
}
