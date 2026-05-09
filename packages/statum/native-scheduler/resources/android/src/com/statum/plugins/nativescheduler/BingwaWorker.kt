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

    override fun doWork(): Result {
        Log.i("BingwaWorker", "Starting scheduled background work")
        val phpBridge = PHPBridge(context)
        
        try {
            phpBridge.ensureRuntimeInitialized()
            
            // Boot ephemeral runtime
            val laravelPath = phpBridge.getLaravelPath()
            val bootstrapScript = "$laravelPath/vendor/nativephp/mobile/bootstrap/android/persistent.php"
            
            val bootResult = synchronized(PHPBridge.phpLock) {
                phpBridge.nativeEphemeralBoot(bootstrapScript)
            }
            if (bootResult != 0) {
                Log.e("BingwaWorker", "Failed to boot ephemeral runtime: $bootResult")
                return Result.retry()
            }
            
            // Run commands
            Log.i("BingwaWorker", "Running bingwa:heartbeat")
            val heartbeatOutput = synchronized(PHPBridge.phpLock) {
                phpBridge.nativeEphemeralArtisan("bingwa:heartbeat")
            }
            Log.i("BingwaWorker", "bingwa:heartbeat output: ${heartbeatOutput.take(1000)}")
            
            Log.i("BingwaWorker", "Running bingwa:sync-transactions")
            val syncOutput = synchronized(PHPBridge.phpLock) {
                phpBridge.nativeEphemeralArtisan("bingwa:sync-transactions")
            }
            Log.i("BingwaWorker", "bingwa:sync-transactions output: ${syncOutput.take(1000)}")
            
            // Shutdown
            synchronized(PHPBridge.phpLock) {
                phpBridge.nativeEphemeralShutdown()
            }
            Log.i("BingwaWorker", "Finished scheduled background work")
            
            return Result.success()
        } catch (e: Exception) {
            Log.e("BingwaWorker", "Error in background work", e)
            return Result.failure()
        }
    }
}
