package com.statum.plugins.nativescheduler

import android.util.Log
import kotlinx.coroutines.delay
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.net.URLEncoder
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.concurrent.TimeUnit

internal data class UssdStatusCallback(
    val backendUrl: String,
    val backendTransactionId: String,
    val deviceToken: String,
    val successful: Boolean,
    val ussdResponse: String,
    val executionTimeMs: Long,
    val executedAt: String = ZonedDateTime.now(NAIROBI_ZONE).format(DateTimeFormatter.ISO_OFFSET_DATE_TIME),
)

private val NAIROBI_ZONE: ZoneId = ZoneId.of("Africa/Nairobi")

internal class UssdStatusReporter(
    private val client: OkHttpClient = sharedClient,
) {
    companion object {
        private const val TAG = "UssdStatusReporter"
        private const val MAX_ATTEMPTS = 3
        private val JSON = "application/json".toMediaType()
        private val sharedClient = OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .writeTimeout(10, TimeUnit.SECONDS)
            .readTimeout(20, TimeUnit.SECONDS)
            .callTimeout(30, TimeUnit.SECONDS)
            // Disabled: the repeat(MAX_ATTEMPTS) loop above already handles transient
            // failures with backoff. OkHttp's silent retry is redundant for PATCH
            // requests and risks delivering a duplicate status update to the backend,
            // because the ByteString-backed request body is repeatable (re-sendable).
            .retryOnConnectionFailure(false)
            .build()
    }

    suspend fun report(callback: UssdStatusCallback) {
        if (callback.backendUrl.isBlank() || callback.backendTransactionId.isBlank() || callback.deviceToken.isBlank()) {
            Log.w(TAG, "Skipping USSD status callback because backend URL, transaction ID, or token is missing.")

            return
        }

        val request = buildRequest(callback)

        repeat(MAX_ATTEMPTS) { attempt ->
            try {
                client.newCall(request).execute().use { response ->
                    if (response.isSuccessful) {
                        Log.i(TAG, "Reported USSD status for backend transaction #${callback.backendTransactionId}.")

                        return
                    }

                    val responsePreview = response.body?.string()?.take(200)
                    if (!response.isRetryableStatus()) {
                        Log.w(
                            TAG,
                            "USSD status callback rejected for backend transaction #${callback.backendTransactionId}: HTTP ${response.code} ${responsePreview.orEmpty()}"
                        )

                        return
                    }

                    Log.w(
                        TAG,
                        "Retryable USSD status callback failure for backend transaction #${callback.backendTransactionId}: HTTP ${response.code} ${responsePreview.orEmpty()}"
                    )
                }
            } catch (e: IOException) {
                Log.w(TAG, "USSD status callback network error for backend transaction #${callback.backendTransactionId}", e)
            } catch (e: Exception) {
                Log.e(TAG, "USSD status callback failed before reaching backend transaction #${callback.backendTransactionId}", e)

                return
            }

            if (attempt < MAX_ATTEMPTS - 1) {
                delay(500L * (attempt + 1))
            }
        }
    }

    private fun buildRequest(callback: UssdStatusCallback): Request {
        val payload = JSONObject()
            .put("status", if (callback.successful) "successful" else "failed")
            .put("execution_time_ms", callback.executionTimeMs.coerceAtLeast(0L))
            .put("executed_at", callback.executedAt)

        if (callback.ussdResponse.isNotBlank()) {
            payload.put("ussd_response", callback.ussdResponse)
        }

        if (!callback.successful) {
            payload.put("failure_code", failureCodeFor(callback.ussdResponse))
        }

        val transactionId = URLEncoder.encode(callback.backendTransactionId, "UTF-8").replace("+", "%20")
        val url = "${callback.backendUrl.trimEnd('/')}/api/v1/transactions/$transactionId/status"

        return Request.Builder()
            .url(url)
            .header("Authorization", "Bearer ${callback.deviceToken}")
            .header("Accept", "application/json")
            .header("Content-Type", "application/json")
            .patch(payload.toString().toRequestBody(JSON))
            .build()
    }

    private fun okhttp3.Response.isRetryableStatus(): Boolean {
        return code == 408 || code == 429 || code in 500..599
    }

    private fun failureCodeFor(message: String): String {
        val normalized = message.lowercase()

        return when {
            "timeout" in normalized || "timed out" in normalized -> "USSD_TIMEOUT"
            "low balance" in normalized || "insufficient" in normalized || "not enough" in normalized -> "LOW_BALANCE"
            "invalid recipient" in normalized || "invalid phone" in normalized -> "INVALID_RECIPIENT"
            "session ended" in normalized || "session closed" in normalized -> "SESSION_ENDED"
            else -> "SYSTEM_ERROR"
        }
    }
}
