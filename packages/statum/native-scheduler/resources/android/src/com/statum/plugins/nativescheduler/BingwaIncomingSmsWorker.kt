package com.statum.plugins.nativescheduler

import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Base64
import android.util.Log
import androidx.work.Worker
import androidx.work.WorkerParameters

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

        Log.i(TAG, "Executing incoming SMS processing command via PHPQueueService: $command")

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
        const val KEY_PAYLOAD = "payload"
        private const val TAG = "BingwaIncomingSmsWorker"
    }
}
