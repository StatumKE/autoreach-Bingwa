package com.statum.plugins.nativescheduler

import android.content.Context
import android.util.Base64
import android.util.Log
import androidx.work.Worker
import androidx.work.WorkerParameters
import com.nativephp.mobile.bridge.BridgeFunctionRegistry
import com.nativephp.mobile.bridge.LaravelEnvironment
import com.nativephp.mobile.bridge.PHPBridge
import com.nativephp.mobile.bridge.plugins.registerContextOnlyBridgeFunctions

class BingwaIncomingSmsWorker(
    context: Context,
    params: WorkerParameters
) : Worker(context, params) {
    override fun doWork(): Result {
        val payload = inputData.getString(KEY_PAYLOAD)
        if (payload.isNullOrBlank()) {
            Log.e(TAG, "Incoming SMS worker received no payload")
            return Result.failure()
        }

        val encodedPayload = Base64.encodeToString(
            payload.toByteArray(Charsets.UTF_8),
            Base64.URL_SAFE or Base64.NO_WRAP or Base64.NO_PADDING
        )
        val command = "bingwa:process-incoming-sms --payload=$encodedPayload"

        val phpBridge = PHPBridge(applicationContext)
        if (phpBridge.isPersistentMode()) {
            try {
                val output = phpBridge.runPersistentArtisan(command)
                Log.i(TAG, "Incoming SMS processing via persistent runtime completed: ${output.take(200)}")
                return Result.success()
            } catch (e: Exception) {
                Log.w(TAG, "Persistent runtime execution failed for incoming SMS: ${e.message}")
                return Result.retry()
            }
        }

        // Cold boot fallback
        return synchronized(PHPBridge.phpLock) {
            try {
                if (!BridgeFunctionRegistry.shared.exists("ExecuteUssd")) {
                    registerContextOnlyBridgeFunctions(applicationContext)
                }

                val environment = LaravelEnvironment(applicationContext)
                environment.initializeForBackground()

                val booted = phpBridge.nativeEphemeralBoot(
                    "${phpBridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
                )

                if (booted != 0) {
                    Log.e(TAG, "Failed to boot PHP for incoming SMS processing")
                    return@synchronized Result.retry()
                }

                try {
                    val output = phpBridge.nativeEphemeralArtisan(command)
                    Log.i(TAG, "Incoming SMS processing completed: ${output.take(200)}")
                } finally {
                    phpBridge.nativeEphemeralShutdown()
                }

                Result.success()
            } catch (exception: Exception) {
                Log.e(TAG, "Incoming SMS processing failed", exception)
                Result.retry()
            }
        }
    }

    companion object {
        const val KEY_PAYLOAD = "payload"
        private const val TAG = "BingwaIncomingSmsWorker"
    }
}
