package com.statum.plugins.nativescheduler

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.IBinder
import android.util.Log
import com.nativephp.mobile.bridge.PHPBridge
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
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

        fun start(context: Context) {
            val intent = Intent(context, ArtisanSchedulerService::class.java)
            context.startForegroundService(intent)
        }

        fun stop(context: Context) {
            val intent = Intent(context, ArtisanSchedulerService::class.java)
            context.stopService(intent)
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
        startDecoupledEngine()
        return START_STICKY
    }

    override fun onDestroy() {
        masterJob?.cancel()
        phpBridge?.nativeEphemeralShutdown()
        Log.i(TAG, "Scheduler service destroyed — ephemeral runtime shut down")
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    // -------------------------------------------------------------------------
    // Engine Orchestration
    // -------------------------------------------------------------------------

    private fun startDecoupledEngine() {
        if (masterJob?.isActive == true) return

        masterJob = serviceScope.launch(phpDispatcher) {
            Log.i(TAG, "Booting ephemeral PHP runtime for dual-loop engine")
            val bridge = PHPBridge(applicationContext)
            phpBridge = bridge

            bridge.ensureRuntimeInitialized()
            val bootstrapScript = "${bridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"

            val booted = bridge.nativeEphemeralBoot(bootstrapScript)
            if (booted != 0) {
                Log.e(TAG, "Failed to boot ephemeral runtime — engine aborting (code=$booted)")
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
                // 1. Ask PHP for the next local job (Thread-safe lock)
                val jobJson = phpMutex.withLock {
                    bridge.nativeEphemeralArtisan("bingwa:next-ussd-job").trim()
                }

                if (jobJson.isNotEmpty()) {
                    if (!jobJson.startsWith("{")) {
                        Log.w(TAG, "Ignoring non-JSON USSD job response: ${jobJson.take(200)}")
                        delay(FAST_POLL_INTERVAL_MS)
                        continue
                    }

                    val job = org.json.JSONObject(jobJson)
                    val id = job.getInt("id")
                    val code = job.getString("code")
                    val mode = job.getString("mode")
                    val simSlot = job.optInt("sim_slot", 0)
                    val timeout = job.optInt("timeout", 30)

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
            } catch (e: Exception) {
                Log.e(TAG, "USSD dispatch error", e)
            }

            // No jobs found (or an error occurred). Sleep briefly before checking local DB again.
            delay(FAST_POLL_INTERVAL_MS)
        }
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
