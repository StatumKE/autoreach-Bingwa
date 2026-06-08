package com.statum.plugins.nativescheduler

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.IBinder
import android.util.Log
import androidx.core.app.NotificationCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import java.util.concurrent.atomic.AtomicInteger

/**
 * Foreground service that executes USSD codes in the **main process**.
 *
 * ## Why this service exists
 *
 * The app runs in two Android processes:
 *   - **Main process** (`com.autoreach.bingwa`): hosts [UssdAccessibilityService] and the
 *     persistent PHP WebView runtime.
 *   - **Queue process** (`:queue`): hosts [com.nativephp.mobile.bridge.PHPQueueService] and
 *     runs Artisan background jobs (e.g. `bingwa:process-ussd`).
 *
 * `UssdAccessibilityService.responseChannel` is a static companion-object Channel.
 * Static objects are **not shared across Android processes** — each process has its own JVM
 * and heap. When [BingwaFunctions.ExecuteUssd] was called from the `:queue` process it
 * used a [GlobalScope] coroutine that waited on `responseChannel` in the queue process,
 * while the accessibility service was writing to `responseChannel` in the main process.
 * Those are two different channels — the queue-process channel was always empty → 90-second
 * timeout on every async USSD job.
 *
 * ## Solution
 *
 * Instead of running USSD inline in whichever process calls [BingwaFunctions.executeUssd],
 * we fire a [startForegroundService] Intent targeting this service. Android always starts the
 * service in the process that declared it in the manifest — which is the **main process**
 * (no `android:process` attribute). [UssdExecutor] then runs a coroutine in the main process
 * where [UssdAccessibilityService.responseChannel] is alive and the persistent PHP runtime is
 * warm, so the callback via runPersistentArtisan completes in ~5 ms instead of ~200 ms.
 *
 * ## Concurrency
 *
 * Multiple USSD intents may arrive while one is in flight (e.g. a queue burst). Each
 * `onStartCommand` call launches a new coroutine. The shared [modemMutex] serialises actual
 * modem access — concurrent jobs queue behind the lock and execute sequentially. The service
 * only stops itself when [activeJobs] reaches zero.
 */
internal class UssdExecutionService : Service() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val activeJobs = AtomicInteger(0)

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        ensureNotificationChannel()
        startForeground(NOTIFICATION_ID, buildNotification("Processing USSD…"))
        Log.i(TAG, "UssdExecutionService started (main process)")
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val id = intent?.getIntExtra(KEY_ID, -1) ?: -1
        val code = intent?.getStringExtra(KEY_CODE)

        if (code.isNullOrBlank()) {
            Log.e(TAG, "onStartCommand: missing code — stopping start id $startId")
            stopSelf(startId)
            return START_NOT_STICKY
        }

        val mode = intent.getStringExtra(KEY_MODE) ?: "advanced"
        val simSlot = intent.getIntExtra(KEY_SIM_SLOT, 0)
        val isSambaza = intent.getBooleanExtra(KEY_IS_SAMBAZA, false)
        val timeoutSeconds = intent.getIntExtra(KEY_TIMEOUT_SECONDS, 30)

        Log.i(TAG, "onStartCommand: id=$id mode=$mode simSlot=$simSlot timeoutSeconds=$timeoutSeconds code=$code")

        activeJobs.incrementAndGet()

        scope.launch {
            try {
                modemMutex.withLock {
                    Log.i(TAG, "USSD modem lock acquired: id=$id mode=$mode simSlot=$simSlot")
                    val executor = UssdExecutor(applicationContext)
                    val result = executor.execute(
                        code = code,
                        mode = mode,
                        simSlot = simSlot,
                        isSambaza = isSambaza,
                        timeoutSeconds = timeoutSeconds,
                    )
                    Log.i(TAG, "USSD complete: id=$id success=${result.success} message=${result.message.take(100)}")
                    if (id > 0) {
                        deliverCallback(applicationContext, id, result.success, result.message)
                    }
                }
            } catch (e: Exception) {
                Log.e(TAG, "USSD execution failed: id=$id", e)
                if (id > 0) {
                    deliverCallback(
                        applicationContext,
                        id,
                        success = false,
                        message = e.message ?: "USSD execution failed unexpectedly",
                    )
                }
            } finally {
                val remaining = activeJobs.decrementAndGet()
                Log.d(TAG, "USSD job done: id=$id remaining=$remaining")
                if (remaining <= 0) {
                    Log.i(TAG, "No more USSD jobs — stopping service (startId=$startId)")
                    stopSelf(startId)
                }
            }
        }

        return START_NOT_STICKY
    }

    override fun onDestroy() {
        scope.cancel()
        Log.i(TAG, "UssdExecutionService destroyed")
        super.onDestroy()
    }

    // -------------------------------------------------------------------------
    // Callback delivery — runs in main process where persistent PHP is warm
    // -------------------------------------------------------------------------

    private fun deliverCallback(context: Context, id: Int, success: Boolean, message: String) {
        val status = if (success) "completed" else "failed"
        val encodedMessage = android.util.Base64.encodeToString(
            message.toByteArray(Charsets.UTF_8),
            android.util.Base64.URL_SAFE or android.util.Base64.NO_WRAP or android.util.Base64.NO_PADDING,
        )
        val token = UssdCallbackOutbox.enqueue(context, id, success, message)
        val command = "bingwa:complete-transaction --transaction-id=$id --result=$status --message-base64=$encodedMessage --callback-token=$token"

        try {
            val phpBridge = com.nativephp.mobile.bridge.PHPBridge(context.applicationContext)

            if (phpBridge.isPersistentMode()) {
                Log.i(TAG, "USSD callback via persistent runtime: id=$id status=$status")
                phpBridge.runPersistentArtisan(command)
                UssdCallbackOutbox.markDelivered(context, id)
                return
            }

            // Persistent bridge not warm — fall back to ephemeral
            Log.w(TAG, "USSD callback via ephemeral runtime (persistent not ready): id=$id")
            synchronized(com.nativephp.mobile.bridge.PHPBridge.phpLock) {
                if (!com.nativephp.mobile.bridge.BridgeFunctionRegistry.shared.exists("ExecuteUssd")) {
                    com.nativephp.mobile.bridge.plugins.registerContextOnlyBridgeFunctions(context.applicationContext)
                }
                val environment = com.nativephp.mobile.bridge.LaravelEnvironment(context.applicationContext)
                environment.initializeForBackground()
                val booted = phpBridge.nativeEphemeralBoot(
                    "${phpBridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php",
                )
                if (booted == 0) {
                    try {
                        phpBridge.nativeEphemeralArtisan(command)
                        UssdCallbackOutbox.markDelivered(context, id)
                    } finally {
                        phpBridge.nativeEphemeralShutdown()
                    }
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "USSD callback delivery failed for id=$id", e)
        }
    }

    // -------------------------------------------------------------------------
    // Notification helpers
    // -------------------------------------------------------------------------

    private fun ensureNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "USSD Execution",
                NotificationManager.IMPORTANCE_LOW,
            ).apply {
                description = "Shows while a USSD code is being processed"
                setShowBadge(false)
            }
            getSystemService(NotificationManager::class.java)?.createNotificationChannel(channel)
        }
    }

    private fun buildNotification(text: String) =
        NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("Autoreach Bingwa")
            .setContentText(text)
            .setSmallIcon(android.R.drawable.ic_popup_sync)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setOngoing(true)
            .build()

    // -------------------------------------------------------------------------
    // Companion — static helpers called by BingwaFunctions
    // -------------------------------------------------------------------------

    companion object {
        private const val TAG = "UssdExecutionService"
        private const val NOTIFICATION_ID = 9_001
        private const val CHANNEL_ID = "bingwa_ussd_execution"

        const val KEY_ID = "id"
        const val KEY_CODE = "code"
        const val KEY_MODE = "mode"
        const val KEY_SIM_SLOT = "simSlot"
        const val KEY_IS_SAMBAZA = "isSambaza"
        const val KEY_TIMEOUT_SECONDS = "timeoutSeconds"

        /**
         * Dedicated modem mutex owned by this service.
         * Serialises concurrent USSD jobs within the main process.
         */
        val modemMutex = Mutex()

        /**
         * Starts the service (or delivers a new intent to a running instance)
         * from any process. Android guarantees the service runs in the process
         * that registered it in the manifest — the main process.
         */
        fun start(
            context: Context,
            id: Int,
            code: String,
            mode: String,
            simSlot: Int,
            isSambaza: Boolean,
            timeoutSeconds: Int,
        ) {
            val intent = Intent(context, UssdExecutionService::class.java).apply {
                putExtra(KEY_ID, id)
                putExtra(KEY_CODE, code)
                putExtra(KEY_MODE, mode)
                putExtra(KEY_SIM_SLOT, simSlot)
                putExtra(KEY_IS_SAMBAZA, isSambaza)
                putExtra(KEY_TIMEOUT_SECONDS, timeoutSeconds)
            }

            try {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    context.startForegroundService(intent)
                } else {
                    context.startService(intent)
                }
                Log.i(TAG, "Dispatched USSD to main-process service: id=$id mode=$mode")
            } catch (e: Exception) {
                Log.e(TAG, "Failed to start UssdExecutionService: id=$id", e)
            }
        }
    }
}
