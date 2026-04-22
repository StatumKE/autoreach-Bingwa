package com.statum.plugins.nativescheduler

import android.app.AlarmManager
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.IBinder
import android.os.SystemClock
import android.util.Log
import com.nativephp.mobile.bridge.LaravelEnvironment
import com.nativephp.mobile.bridge.PHPBridge
import com.nativephp.mobile.ui.MainActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.coroutines.asCoroutineDispatcher
import java.util.concurrent.Executors

/**
 * A persistent Android Foreground Service that runs the Bingwa background engine.
 *
 * This service implements a fully decoupled concurrency model using two independent loops:
 * 1. The Sync Poller: Polls the backend for new transactions every 5 seconds.
 * 2. The USSD Worker: Continuously checks the local SQLite DB for queued transactions
 *    and executes them.
 *
 * Because the underlying NativePHP Ephemeral JNI runtime is NOT thread-safe, all calls to
 * `phpBridge.nativeEphemeralArtisan` are synchronized using [phpMutex]. This guarantees
 * the JNI layer never crashes from concurrent PHP execution, while still allowing the USSD
 * call itself (which suspends for up to 30s) to run concurrently without blocking the Sync Poller.
 */
class ArtisanSchedulerService : Service() {

    companion object {
        private const val TAG = "ArtisanSchedulerService"
        private const val CHANNEL_ID = "bingwa_scheduler"
        private const val NOTIFICATION_ID = 1001
        private const val SYNC_INTERVAL_MS = 5_000L
        private const val FAST_POLL_INTERVAL_MS = 2_000L
        private const val ARTISAN_COMMAND = "bingwa:sync-transactions"

        @Volatile
        var engineActive: Boolean = false
            private set

        fun start(context: Context) {
            val intent = Intent(context, ArtisanSchedulerService::class.java)
            context.startForegroundService(intent)
        }

        fun stop(context: Context) {
            val intent = Intent(context, ArtisanSchedulerService::class.java)
            context.stopService(intent)
        }

        fun cancelWatchdog(context: Context) {
            val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            val restartIntent = Intent(context, ArtisanSchedulerService::class.java)

            val watchdogIntent = PendingIntent.getForegroundService(
                context, 9001, restartIntent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            alarmManager.cancel(watchdogIntent)

            val taskRemovedIntent = PendingIntent.getForegroundService(
                context, 9002, restartIntent,
                PendingIntent.FLAG_ONE_SHOT or PendingIntent.FLAG_IMMUTABLE
            )
            alarmManager.cancel(taskRemovedIntent)
        }

        /**
         * Schedule an AlarmManager wakeup to restart the service if Android kills it.
         * Uses ELAPSED_REALTIME_WAKEUP to fire even in Doze mode.
         */
        fun scheduleWatchdog(context: Context) {
            val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            val intent = Intent(context, ArtisanSchedulerService::class.java)
            val pendingIntent = PendingIntent.getForegroundService(
                context, 9001, intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            // Fire every 10 minutes as a watchdog
            alarmManager.setRepeating(
                AlarmManager.ELAPSED_REALTIME_WAKEUP,
                SystemClock.elapsedRealtime() + 10 * 60 * 1000L,
                10 * 60 * 1000L,
                pendingIntent
            )
            Log.i(TAG, "Watchdog alarm scheduled (10 min interval)")
        }
    }

    private var masterJob: Job? = null
    private val serviceScope = CoroutineScope(Dispatchers.IO)
    
    // Dedicated single-thread dispatcher for the PHP runtime.
    // The PHP JNI layer is NOT thread-safe and requires all calls (boot + artisan)
    // to occur on the SAME background thread to avoid SIGSEGV.
    private val phpDispatcher = Executors.newSingleThreadExecutor().asCoroutineDispatcher()
    
    // Mutex to protect the single-threaded PHP TSRM context from concurrent access
    private val phpMutex = Mutex()
    private var phpBridge: PHPBridge? = null

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
        
        val notification = buildNotification()
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.Q) {
            startForeground(
                NOTIFICATION_ID, 
                notification, 
                android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC
            )
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
        
        Log.i(TAG, "Scheduler service created")
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (MainActivity.instance != null && !SchedulerStartupState.appBootstrapComplete) {
            Log.i(TAG, "MainActivity bootstrap in progress; deferring scheduler startup")
            stopSelf()
            return START_NOT_STICKY
        }

        startDecoupledEngine()
        scheduleWatchdog(applicationContext)
        return START_STICKY
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        Log.w(TAG, "Task removed — scheduling immediate restart via AlarmManager")
        val restartIntent = Intent(applicationContext, ArtisanSchedulerService::class.java)
        val pendingIntent = PendingIntent.getForegroundService(
            applicationContext, 9002, restartIntent,
            PendingIntent.FLAG_ONE_SHOT or PendingIntent.FLAG_IMMUTABLE
        )
        val alarmManager = getSystemService(Context.ALARM_SERVICE) as AlarmManager
        alarmManager.set(
            AlarmManager.ELAPSED_REALTIME_WAKEUP,
            SystemClock.elapsedRealtime() + 2_000L,
            pendingIntent
        )
        super.onTaskRemoved(rootIntent)
    }

    override fun onDestroy() {
        masterJob?.cancel()
        shutdownEphemeralRuntimeOnDestroy()
        engineActive = false
        cancelWatchdog(applicationContext)
        phpDispatcher.close()
        Log.i(TAG, "Scheduler service destroyed — ephemeral runtime shut down")
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun shutdownEphemeralRuntimeOnDestroy() {
        val bridge = phpBridge ?: return

        try {
            runBlocking(phpDispatcher) {
                phpMutex.withLock {
                    bridge.nativeEphemeralShutdown()
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to shut down ephemeral runtime during service destroy", e)
        } finally {
            phpBridge = null
        }
    }

    // -------------------------------------------------------------------------
    // Engine Orchestration
    // -------------------------------------------------------------------------

    private fun startDecoupledEngine() {
        if (masterJob?.isActive == true) return

        masterJob = serviceScope.launch(phpDispatcher) {
            engineActive = true

            if (MainActivity.instance != null && !SchedulerStartupState.appBootstrapComplete) {
                Log.i(TAG, "MainActivity bootstrap in progress; skipping scheduler engine boot")
                engineActive = false
                stopSelf()
                return@launch
            }

            Log.i(TAG, "Booting ephemeral PHP runtime for dual-loop engine")

            try {
                Log.i(TAG, "Initializing background Laravel environment for scheduler")
                LaravelEnvironment(applicationContext).initializeForBackground()
            } catch (e: Exception) {
                Log.e(TAG, "Failed to initialize background Laravel environment", e)
                engineActive = false
                stopSelf()
                return@launch
            }

            val bridge = PHPBridge(applicationContext)
            phpBridge = bridge

            bridge.ensureRuntimeInitialized()
            val bootstrapScript = "${bridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"

            val booted = bridge.nativeEphemeralBoot(bootstrapScript)
            if (booted != 0) {
                Log.e(TAG, "Failed to boot ephemeral runtime — engine aborting (code=$booted)")
                engineActive = false
                stopSelf()
                return@launch
            }

            Log.i(TAG, "Ephemeral runtime ready. Launching decoupled Sync and USSD workers.")

            val ussdExecutor = UssdExecutor(applicationContext)

            // Launch Loop A: Sync Poller (Pinned to PHP thread)
            launch(phpDispatcher) { runSyncPoller(bridge) }

            // Launch Loop B: USSD Worker (Pinned to PHP thread)
            launch(phpDispatcher) { runUssdWorker(bridge, ussdExecutor) }
        }
    }

    // -------------------------------------------------------------------------
    // Loop A: Sync Poller (Backend -> SQLite)
    // -------------------------------------------------------------------------

    private suspend fun runSyncPoller(bridge: PHPBridge) {
        val heartbeatIntervalMs = 600_000L // 10 minutes
        var lastHeartbeatTime = 0L

        while (kotlinx.coroutines.currentCoroutineContext().isActive) {
            val currentTime = System.currentTimeMillis()

            if (currentTime - lastHeartbeatTime >= heartbeatIntervalMs) {
                try {
                    phpMutex.withLock {
                        bridge.nativeEphemeralArtisan("bingwa:heartbeat")
                    }
                    Log.d(TAG, "Heartbeat sent.")
                    lastHeartbeatTime = currentTime
                } catch (e: Exception) {
                    Log.e(TAG, "Heartbeat error", e)
                }
            }

            try {
                val output = phpMutex.withLock {
                    bridge.nativeEphemeralArtisan(ARTISAN_COMMAND)
                }
                Log.d(TAG, "Sync result: ${output.take(200)}")
            } catch (e: Exception) {
                Log.e(TAG, "Sync error", e)
            }

            delay(SYNC_INTERVAL_MS)
        }
    }

    // -------------------------------------------------------------------------
    // Loop B: USSD Worker (SQLite -> Modem)
    // -------------------------------------------------------------------------

    private suspend fun runUssdWorker(bridge: PHPBridge, ussdExecutor: UssdExecutor) {
        while (kotlinx.coroutines.currentCoroutineContext().isActive) {
            try {
                // Temporary file to safely receive JSON payload from PHP, bypassing JNI output buffer issues
                val tmpFile = java.io.File(applicationContext.cacheDir, "ussd_payload.json")
                if (tmpFile.exists()) tmpFile.delete()

                // 1. Ask PHP for the next local job (Thread-safe lock)
                val rawJobOutput = phpMutex.withLock {
                    bridge.nativeEphemeralArtisan("bingwa:next-ussd-job --output=${tmpFile.absolutePath}").trim()
                }

                val jobJson = if (tmpFile.exists()) {
                    val content = tmpFile.readText()
                    tmpFile.delete()
                    extractJsonPayload(content)
                } else {
                    extractJsonPayload(rawJobOutput)
                }

                if (jobJson != null) {
                    if (rawJobOutput.isNotBlank() && rawJobOutput != jobJson) {
                        Log.d(TAG, "Recovered JSON payload from bridge output: ${rawJobOutput.take(200)}")
                    }

                    val job = org.json.JSONObject(jobJson)
                    val id = job.getInt("id")
                    val code = job.getString("code")
                    val mode = job.getString("mode")
                    val simSlot = job.optInt("sim_slot", 0)
                    val timeout = job.optInt("timeout", 30)

                    if (tmpFile.exists()) tmpFile.delete()
                    val claimOutput = phpMutex.withLock {
                        bridge.nativeEphemeralArtisan("bingwa:claim-ussd-job --id=$id --output=${tmpFile.absolutePath}").trim()
                    }
                    val claimJson = if (tmpFile.exists()) {
                        val content = tmpFile.readText()
                        tmpFile.delete()
                        extractJsonPayload(content)
                    } else {
                        extractJsonPayload(claimOutput)
                    }
                    
                    if (claimJson == null) {
                        Log.w(TAG, "Failed to claim USSD job #$id: ${claimOutput.take(200)}")
                        delay(FAST_POLL_INTERVAL_MS)
                        continue
                    }

                    val claim = org.json.JSONObject(claimJson)
                    if (!claim.optBoolean("claimed", false)) {
                        Log.w(TAG, "USSD job #$id was not claimable: ${claimJson.take(200)}")
                        delay(FAST_POLL_INTERVAL_MS)
                        continue
                    }

                    Log.i(TAG, "Executing USSD job #$id [$mode] on SIM $simSlot (timeout ${timeout}s): $code")

                    // 2. Execute USSD.
                    // IMPORTANT: This suspends for up to 30s+. Because it is OUTSIDE the phpMutex,
                    // Loop A (Sync Poller) can continue running normally in the background!
                    val result = ussdExecutor.execute(code, mode, simSlot, timeoutSeconds = timeout)

                    val status = if (result.success) "completed" else "failed"
                    val message = result.message.replace("'", "\\'").take(200)

                    // 3. Mark the job completed in PHP (Thread-safe lock)
                    phpMutex.withLock {
                        bridge.nativeEphemeralArtisan(
                            "bingwa:complete-transaction $id $status --message='$message'"
                        )
                    }

                    Log.i(TAG, "USSD job #$id → $status: ${result.message}")

                    // Proceed immediately to the next job if we just finished one
                    continue
                }

                if (rawJobOutput.isNotBlank()) {
                    Log.w(TAG, "Ignoring non-JSON USSD job response: ${rawJobOutput.take(200)}")
                }

                if (rawJobOutput.isNotBlank()) {
                    delay(FAST_POLL_INTERVAL_MS)
                    continue
                }
            } catch (e: Exception) {
                Log.e(TAG, "USSD dispatch error", e)
            }

            // No jobs found (or an error occurred). Sleep briefly before checking local DB again.
            delay(FAST_POLL_INTERVAL_MS)
        }
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

    // -------------------------------------------------------------------------
    // Notification helpers (required for Foreground Service on Android 8+)
    // -------------------------------------------------------------------------

    private fun createNotificationChannel() {
        val channel = NotificationChannel(
            CHANNEL_ID,
            "Bingwa Background Sync",
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = "Keeps Bingwa transaction sync running in the background"
            setShowBadge(false)
        }
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(channel)
    }

    private fun buildNotification(): Notification =
        Notification.Builder(this, CHANNEL_ID)
            .setContentTitle("Bingwa is running")
            .setContentText("Syncing and processing transactions…")
            .setSmallIcon(android.R.drawable.ic_popup_sync)
            .setOngoing(true)
            .build()
}
