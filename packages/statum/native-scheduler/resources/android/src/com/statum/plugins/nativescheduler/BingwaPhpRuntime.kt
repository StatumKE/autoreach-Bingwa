package com.statum.plugins.nativescheduler

import android.content.Context
import android.os.SystemClock
import android.util.Base64
import android.util.Log
import com.nativephp.mobile.bridge.LaravelEnvironment
import com.nativephp.mobile.bridge.PHPBridge
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
import kotlinx.coroutines.asCoroutineDispatcher
import kotlinx.coroutines.isActive
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File
import java.util.concurrent.Executors

internal data class QueuedUssdJob(
    val id: Int,
    val code: String,
    val mode: String,
    val simSlot: Int,
    val timeoutSeconds: Int,
    val backendTransactionId: String,
    val backendUrl: String,
    val deviceToken: String,
)

internal class BingwaPhpRuntime(
    private val context: Context,
) {
    companion object {
        private const val TAG = "BingwaPhpRuntime"
        private const val SYNC_COMMAND = "bingwa:sync-transactions"
        private const val HEARTBEAT_COMMAND = "bingwa:heartbeat"
        private const val NEXT_JOB_COMMAND = "bingwa:next-ussd-job"
        private const val CLAIM_JOB_COMMAND = "bingwa:claim-ussd-job"
        private const val COMPLETE_TRANSACTION_COMMAND = "bingwa:complete-transaction"
        private const val HEARTBEAT_INTERVAL_MS = 10 * 60 * 1000L
        private const val POLL_INTERVAL_MS = 5_000L
        private const val CLAIM_RETRY_BACKOFF_MS = 2_000L
        private const val PAYLOAD_FILENAME = "bingwa_ussd_payload.json"
    }

    private val applicationContext = context.applicationContext
    private val phpDispatcher = Executors.newSingleThreadExecutor().asCoroutineDispatcher()
    private val statusReporter = UssdStatusReporter()
    private val ussdExecutor = UssdExecutor(applicationContext)

    private var phpBridge: PHPBridge? = null
    private var initialized: Boolean = false

    suspend fun initialize(): Boolean = withContext(phpDispatcher) {
        if (initialized) {
            return@withContext true
        }

        try {
            Log.i(TAG, "Initializing Laravel environment for Bingwa scheduling")
            LaravelEnvironment(applicationContext).initialize()
            phpBridge = PHPBridge(applicationContext)
            if (!phpBridge!!.bootEphemeralRuntime()) {
                Log.e(TAG, "Failed to boot the Bingwa ephemeral PHP runtime")
                return@withContext false
            }
            initialized = true
            Log.i(TAG, "Bingwa scheduler runtime initialized")
            true
        } catch (exception: Exception) {
            Log.e(TAG, "Failed to initialize Bingwa scheduler runtime", exception)
            false
        }
    }

    suspend fun drainQueuedJobs(): Int = withContext(phpDispatcher) {
        val bridge = requireBridge()
        drainQueuedJobs(bridge)
    }

    suspend fun shutdown(): Unit {
        try {
            phpBridge?.shutdownEphemeralRuntime()
        } catch (exception: Exception) {
            Log.e(TAG, "Failed to shut down Bingwa scheduler runtime", exception)
        } finally {
            phpBridge = null
            initialized = false
            SchedulerRuntimeState.releaseEngine()
            phpDispatcher.close()
            Log.i(TAG, "Bingwa scheduler runtime shut down")
        }
    }

    private fun requireBridge(): PHPBridge {
        return phpBridge ?: throw IllegalStateException("Bingwa scheduler runtime is not initialized")
    }

    private suspend fun runSyncCommand(bridge: PHPBridge): String {
        val output = bridge.runEphemeralArtisan(SYNC_COMMAND)

        Log.d(TAG, "Sync result: ${output.take(200)}")
        return output
    }

    private suspend fun drainQueuedJobs(bridge: PHPBridge): Int {
        var processedJobs = 0
        var lastHeartbeatTime = 0L

        while (currentCoroutineContext().isActive) {
            val cycleStartedAt = SystemClock.elapsedRealtime()
            runSyncCommand(bridge)

            while (currentCoroutineContext().isActive) {
                val now = SystemClock.elapsedRealtime()
                if (now - lastHeartbeatTime >= HEARTBEAT_INTERVAL_MS) {
                    sendHeartbeat(bridge)
                    lastHeartbeatTime = now
                }

                val job = fetchNextJob(bridge) ?: break
                if (!claimJob(bridge, job.id)) {
                    Log.w(TAG, "USSD job #${job.id} was not claimable")
                    delay(CLAIM_RETRY_BACKOFF_MS)
                    continue
                }

                Log.i(
                    TAG,
                    "Executing USSD job #${job.id} [${job.mode}] on SIM ${job.simSlot} (timeout ${job.timeoutSeconds}s): ${job.code}",
                )

                val startedAt = SystemClock.elapsedRealtime()
                val result = ussdExecutor.execute(
                    code = job.code,
                    mode = job.mode,
                    simSlot = job.simSlot,
                    timeoutSeconds = job.timeoutSeconds,
                )
                val executionTimeMs = SystemClock.elapsedRealtime() - startedAt
                val status = if (result.success) "completed" else "failed"
                val message = result.message.take(200)
                val encodedMessage = Base64.encodeToString(
                    message.toByteArray(Charsets.UTF_8),
                    Base64.NO_WRAP,
                )

                completeTransaction(
                    bridge = bridge,
                    transactionId = job.id,
                    result = status,
                    encodedMessage = encodedMessage,
                )

                if (job.backendUrl.isNotBlank() && job.backendTransactionId.isNotBlank() && job.deviceToken.isNotBlank()) {
                    statusReporter.report(
                        UssdStatusCallback(
                            backendUrl = job.backendUrl,
                            backendTransactionId = job.backendTransactionId,
                            deviceToken = job.deviceToken,
                            successful = result.success,
                            ussdResponse = result.message,
                            executionTimeMs = executionTimeMs,
                        )
                    )
                }

                Log.i(TAG, "USSD job #${job.id} -> $status: ${result.message}")
                processedJobs += 1
            }

            sleepUntilNextPoll(cycleStartedAt)
        }

        return processedJobs
    }

    private suspend fun sleepUntilNextPoll(
        cycleStartedAt: Long,
    ): Unit {
        val elapsedSincePollStart = SystemClock.elapsedRealtime() - cycleStartedAt
        val remainingDelayMs = POLL_INTERVAL_MS - elapsedSincePollStart

        if (remainingDelayMs > 0 && currentCoroutineContext().isActive) {
            delay(remainingDelayMs)
        }
    }

    private suspend fun sendHeartbeat(bridge: PHPBridge): Unit {
        bridge.runEphemeralArtisan(HEARTBEAT_COMMAND)
        Log.d(TAG, "Heartbeat sent.")
    }

    private suspend fun fetchNextJob(bridge: PHPBridge): QueuedUssdJob? {
        val payloadFile = File(applicationContext.cacheDir, PAYLOAD_FILENAME)
        if (payloadFile.exists()) {
            payloadFile.delete()
        }

        val rawOutput = bridge.runEphemeralArtisan("$NEXT_JOB_COMMAND --output=${payloadFile.absolutePath}").trim()

        val jobJson = when {
            payloadFile.exists() -> {
                val payload = payloadFile.readText()
                payloadFile.delete()
                extractJsonPayload(payload)
            }
            else -> extractJsonPayload(rawOutput)
        }

        if (jobJson == null) {
            if (rawOutput.isNotBlank()) {
                Log.w(TAG, "Ignoring non-JSON USSD job response: ${rawOutput.take(200)}")
            }

            return null
        }

        if (rawOutput.isNotBlank() && rawOutput != jobJson) {
            Log.d(TAG, "Recovered JSON payload from bridge output: ${rawOutput.take(200)}")
        }

        val job = JSONObject(jobJson)
        return QueuedUssdJob(
            id = job.getInt("id"),
            code = job.getString("code"),
            mode = job.getString("mode"),
            simSlot = job.optInt("sim_slot", 0),
            timeoutSeconds = job.optInt("timeout", 30),
            backendTransactionId = job.optString("backend_transaction_id", ""),
            backendUrl = job.optString("backend_url", ""),
            deviceToken = job.optString("device_token", ""),
        )
    }

    private suspend fun claimJob(bridge: PHPBridge, id: Int): Boolean {
        val payloadFile = File(applicationContext.cacheDir, PAYLOAD_FILENAME)
        if (payloadFile.exists()) {
            payloadFile.delete()
        }

        val rawOutput = bridge.runEphemeralArtisan("$CLAIM_JOB_COMMAND --id=$id --output=${payloadFile.absolutePath}").trim()

        val claimJson = when {
            payloadFile.exists() -> {
                val payload = payloadFile.readText()
                payloadFile.delete()
                extractJsonPayload(payload)
            }
            else -> extractJsonPayload(rawOutput)
        }

        if (claimJson == null) {
            Log.w(TAG, "Failed to claim USSD job #$id: ${rawOutput.take(200)}")
            return false
        }

        val claim = JSONObject(claimJson)
        if (!claim.optBoolean("claimed", false)) {
            Log.w(TAG, "USSD job #$id was not claimable: ${claimJson.take(200)}")
            return false
        }

        return true
    }

    private suspend fun completeTransaction(
        bridge: PHPBridge,
        transactionId: Int,
        result: String,
        encodedMessage: String,
    ): Unit {
        bridge.runEphemeralArtisan(
            "$COMPLETE_TRANSACTION_COMMAND --transaction-id=$transactionId --result=$result --finalize-once --message-base64=$encodedMessage",
        )
    }

    private fun extractJsonPayload(rawOutput: String): String? {
        val trimmed = rawOutput.trim()
        if (trimmed.startsWith("{") && trimmed.endsWith("}")) {
            return trimmed
        }

        val startIndex = trimmed.indexOf('{')
        val endIndex = trimmed.lastIndexOf('}')

        if (startIndex >= 0 && endIndex > startIndex) {
            return trimmed.substring(startIndex, endIndex + 1)
        }

        return null
    }
}
