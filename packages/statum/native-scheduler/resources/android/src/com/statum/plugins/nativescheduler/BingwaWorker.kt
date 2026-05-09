package com.statum.plugins.nativescheduler

import android.content.Context
import android.util.Log
import androidx.work.Worker
import androidx.work.WorkerParameters
import com.nativephp.mobile.bridge.PHPBridge

class BingwaWorker(
    private val context: Context,
    workerParams: WorkerParameters
) : Worker(context, workerParams) {

    companion object {
        @Volatile
        private var isRuntimeGloballyInitialized = false
    }

    override fun doWork(): Result {
        Log.i("BingwaWorker", "Starting scheduled background work")
        val phpBridge = PHPBridge(context)
        
        try {
            if (!isRuntimeGloballyInitialized) {
                phpBridge.ensureRuntimeInitialized()
                isRuntimeGloballyInitialized = true
            }
            
            // Boot ephemeral runtime
            val laravelPath = phpBridge.getLaravelPath()
            val bootstrapScript = "$laravelPath/vendor/nativephp/mobile/bootstrap/android/persistent.php"
            
            val bootResult = phpBridge.nativeEphemeralBoot(bootstrapScript)
            if (bootResult != 0) {
                Log.e("BingwaWorker", "Failed to boot ephemeral runtime: $bootResult")
                return Result.retry()
            }
            
            // Run commands
            Log.i("BingwaWorker", "Running bingwa:heartbeat")
            phpBridge.nativeEphemeralArtisan("bingwa:heartbeat")
            
            Log.i("BingwaWorker", "Running bingwa:sync-transactions")
            phpBridge.nativeEphemeralArtisan("bingwa:sync-transactions")
            
            // Shutdown
            phpBridge.nativeEphemeralShutdown()
            Log.i("BingwaWorker", "Finished scheduled background work")
            
            return Result.success()
        } catch (e: Exception) {
            Log.e("BingwaWorker", "Error in background work", e)
            return Result.failure()
        }
    }
}
