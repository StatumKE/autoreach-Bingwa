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

class UssdCallbackWorker(
    context: Context,
    params: WorkerParameters
) : Worker(context, params) {
    override fun doWork(): Result {
        val id = inputData.getInt(KEY_ID, -1)
        val success = inputData.getBoolean(KEY_SUCCESS, false)
        val message = inputData.getString(KEY_MESSAGE) ?: ""

        if (id == -1) {
            Log.e(TAG, "USSD Callback worker received no transaction ID")
            return Result.failure()
        }

        val encodedMessage = Base64.encodeToString(
            message.toByteArray(Charsets.UTF_8),
            Base64.URL_SAFE or Base64.NO_WRAP or Base64.NO_PADDING
        )
        val status = if (success) "completed" else "failed"
        val command = "bingwa:complete-transaction --transaction-id=$id --result=$status --message-base64=$encodedMessage"

        val phpBridge = PHPBridge(applicationContext)
        if (phpBridge.isPersistentMode()) {
            try {
                val output = phpBridge.runPersistentArtisan(command)
                Log.i(TAG, "USSD callback processing via persistent runtime completed: ${output.take(200)}")
                return Result.success()
            } catch (e: Exception) {
                Log.w(TAG, "Persistent runtime execution failed for USSD callback: ${e.message}")
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
                    Log.e(TAG, "Failed to boot PHP for USSD callback processing")
                    return@synchronized Result.retry()
                }

                try {
                    val output = phpBridge.nativeEphemeralArtisan(command)
                    Log.i(TAG, "USSD callback processing completed: ${output.take(200)}")
                } finally {
                    phpBridge.nativeEphemeralShutdown()
                }

                Result.success()
            } catch (exception: Exception) {
                Log.e(TAG, "USSD callback processing failed", exception)
                Result.retry()
            }
        }
    }

    companion object {
        const val KEY_ID = "id"
        const val KEY_SUCCESS = "success"
        const val KEY_MESSAGE = "message"
        private const val TAG = "UssdCallbackWorker"

        fun enqueueFallback(context: Context, id: Int, success: Boolean, message: String) {
            val inputData = androidx.work.Data.Builder()
                .putInt(KEY_ID, id)
                .putBoolean(KEY_SUCCESS, success)
                .putString(KEY_MESSAGE, message)
                .build()

            val workRequest = androidx.work.OneTimeWorkRequestBuilder<UssdCallbackWorker>()
                .setInputData(inputData)
                .build()

            androidx.work.WorkManager.getInstance(context).enqueue(workRequest)
            Log.i(TAG, "Enqueued WorkManager fallback for transaction #$id")
        }
    }
}
