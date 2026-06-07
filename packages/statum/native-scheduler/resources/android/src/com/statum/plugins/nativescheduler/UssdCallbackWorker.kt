package com.statum.plugins.nativescheduler

import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Base64
import android.util.Log
import androidx.work.Worker
import androidx.work.WorkerParameters

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

        Log.i(TAG, "Executing USSD callback command via PHPQueueService: $command")

        val serviceIntent = Intent(applicationContext, com.nativephp.mobile.bridge.PHPQueueService::class.java).apply {
            action = "RUN_COMMAND"
            putExtra("command", command)
        }
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                applicationContext.startForegroundService(serviceIntent)
            } else {
                applicationContext.startService(serviceIntent)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to start PHPQueueService for command: $command", e)
            return Result.failure()
        }
        return Result.success()
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
