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

/**
 * A persistent Android Foreground Service that executes the Laravel Artisan
 * "bingwa:sync-transactions" command every 5 seconds using the dedicated
 * PHPBridge worker runtime (independent TSRM context).
 *
 * This service starts automatically when the app launches and re-starts after
 * device reboot via [SchedulerBootReceiver]. It survives the app being
 * backgrounded and runs even when the user is not logged in, because it
 * operates at the PHP/Artisan layer, not at the WebView/session layer.
 */
class ArtisanSchedulerService : Service() {

    companion object {
        private const val TAG = "ArtisanSchedulerService"
        private const val CHANNEL_ID = "bingwa_scheduler"
        private const val NOTIFICATION_ID = 1001
        private const val SYNC_INTERVAL_MS = 5_000L
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

    private var syncJob: Job? = null
    private val serviceScope = CoroutineScope(Dispatchers.IO)

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
        startSyncLoop()
        // Restart the service automatically if killed by the system
        return START_STICKY
    }

    override fun onDestroy() {
        syncJob?.cancel()
        Log.i(TAG, "Scheduler service destroyed")
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    // -------------------------------------------------------------------------
    // Sync loop
    // -------------------------------------------------------------------------

    private fun startSyncLoop() {
        if (syncJob?.isActive == true) return

        syncJob = serviceScope.launch {
            Log.i(TAG, "Booting ephemeral PHP runtime for scheduler")
            val phpBridge = PHPBridge(applicationContext)

            // Make sure the base PHP environment is initialized
            phpBridge.ensureRuntimeInitialized()

            val bootstrapScript = "${phpBridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"

            val booted = phpBridge.nativeEphemeralBoot(bootstrapScript)
            if (booted != 0) {
                Log.e(TAG, "Failed to boot ephemeral runtime — scheduler aborting (code=$booted)")
                stopSelf()
                return@launch
            }

            Log.i(TAG, "Ephemeral runtime ready. Starting sync loop every ${SYNC_INTERVAL_MS}ms")

            val ussdExecutor = UssdExecutor(applicationContext)
            var heartbeatIntervalMs = 600_000L // 10 minutes
            var lastHeartbeatTime = 0L

            while (isActive) {
                val currentTime = System.currentTimeMillis()

                // 1. Send the heartbeat if the interval has passed
                if (currentTime - lastHeartbeatTime >= heartbeatIntervalMs) {
                    try {
                        phpBridge.nativeEphemeralArtisan("bingwa:heartbeat")
                        Log.d(TAG, "Heartbeat sent.")
                    } catch (e: Exception) {
                        Log.e(TAG, "Heartbeat error", e)
                    }
                    lastHeartbeatTime = currentTime
                }

                // 2. Poll for new transactions from the backend
                try {
                    val output = phpBridge.nativeEphemeralArtisan(ARTISAN_COMMAND)
                    Log.d(TAG, "Sync result: ${output.take(200)}")
                } catch (e: Exception) {
                    Log.e(TAG, "Sync error", e)
                }

                // 3. Execute one pending USSD job (serial — one at a time)
                try {
                    val jobJson = phpBridge.nativeEphemeralArtisan("bingwa:next-ussd-job").trim()

                    if (jobJson.isNotEmpty()) {
                        val job = org.json.JSONObject(jobJson)
                        val id = job.getInt("id")
                        val code = job.getString("code")
                        val mode = job.getString("mode")

                        Log.i(TAG, "Executing USSD job #$id [$mode]: $code")

                        val result = ussdExecutor.execute(code, mode)

                        val status = if (result.success) "completed" else "failed"
                        val message = result.message.replace("'", "\\'").take(200)

                        phpBridge.nativeEphemeralArtisan(
                            "bingwa:complete-transaction $id $status --message='$message'"
                        )

                        Log.i(TAG, "USSD job #$id → $status: ${result.message}")
                    }
                } catch (e: Exception) {
                    Log.e(TAG, "USSD dispatch error", e)
                }

                delay(SYNC_INTERVAL_MS)
            }

            phpBridge.nativeEphemeralShutdown()
            Log.i(TAG, "Sync loop ended — ephemeral runtime shut down")
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
            .setContentText("Syncing transactions in the background…")
            .setSmallIcon(android.R.drawable.ic_popup_sync)
            .setOngoing(true)
            .build()
}
