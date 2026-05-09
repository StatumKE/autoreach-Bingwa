package com.nativephp.mobile.runtime

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.IBinder
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.nativephp.mobile.bridge.LaravelRuntimeBridgeProvider
import com.nativephp.mobile.network.InternalLaravelRequestClient
import com.nativephp.mobile.ui.MainActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import org.json.JSONObject

class RuntimeTickForegroundService : Service() {
    companion object {
        private const val TAG = "RuntimeTickService"
        private const val CHANNEL_ID = "bingwa-runtime-tick"
        private const val CHANNEL_NAME = "Autoreach Bingwa"
        private const val NOTIFICATION_ID = 48_603

        const val FAST_INTERVAL_MILLIS = 15_000L
        const val IDLE_INTERVAL_MILLIS = 300_000L

        fun start(context: Context) {
            val intent = Intent(context, RuntimeTickForegroundService::class.java)
            ContextCompat.startForegroundService(context, intent)
        }
    }

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var runtimeJob: Job? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startForeground(NOTIFICATION_ID, buildNotification())

        if (runtimeJob?.isActive == true) {
            return START_STICKY
        }

        runtimeJob = serviceScope.launch {
            while (isActive) {
                try {
                    val result = runRuntimeTickWithWakeLock()

                    rescheduleBackgroundRuntime()

                    val nextTickMillis = resolveNextTickMillis(result.body)
                    Log.d(
                        TAG,
                        "Runtime tick completed [status=${result.statusCode} nextTickMillis=$nextTickMillis]",
                    )
                    scheduleNextTick(nextTickMillis)
                } catch (exception: Exception) {
                    Log.e(TAG, "Runtime tick failed: ${exception.message}", exception)
                    scheduleNextTick(FAST_INTERVAL_MILLIS)
                }
            }
        }

        return START_STICKY
    }

    override fun onDestroy() {
        runtimeJob?.cancel()
        serviceScope.cancel()
        super.onDestroy()
    }

    private suspend fun scheduleNextTick(delayMillis: Long) {
        delay(delayMillis.coerceIn(FAST_INTERVAL_MILLIS, IDLE_INTERVAL_MILLIS))
    }

    private fun rescheduleBackgroundRuntime() {
        BackgroundRuntimeSyncScheduler.schedule(this)
        BackgroundRuntimeSyncScheduler.scheduleImmediate(this)
    }

    private fun runRuntimeTickWithWakeLock(): InternalLaravelRequestClient.Result {
        return RuntimeWakeLock.withLock(this, "runtime-tick-service") {
            runRuntimeTickWithRecovery()
        }
    }

    private fun resolveNextTickMillis(body: String?): Long {
        val delayMillis = runCatching {
            val json = JSONObject(body.orEmpty())
            val tasks = json.optJSONObject("tasks")
            val nextTickSeconds = tasks?.optLong("next_tick_seconds")
                ?: json.optLong("next_tick_seconds", IDLE_INTERVAL_MILLIS / 1000L)
            nextTickSeconds * 1000L
        }.getOrDefault(IDLE_INTERVAL_MILLIS)

        return delayMillis.coerceIn(FAST_INTERVAL_MILLIS, IDLE_INTERVAL_MILLIS)
    }

    private fun runRuntimeTickWithRecovery(): InternalLaravelRequestClient.Result {
        return try {
            val bridge = LaravelRuntimeBridgeProvider.get(this)
            val result = InternalLaravelRequestClient.postJson(
                phpBridge = bridge,
                path = "/api/v1/native/runtime/tick",
                payload = "{}",
                headers = mapOf("X-Bingwa-Runtime" to "android"),
            )

            if (!result.successful) {
                LaravelRuntimeBridgeProvider.reset(shutdown = true)
            }

            result
        } catch (exception: Exception) {
            LaravelRuntimeBridgeProvider.reset(shutdown = true)
            throw exception
        }
    }

    private fun buildNotification(): Notification {
        val intent = Intent(this, MainActivity::class.java)
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.stat_notify_sync)
            .setContentTitle("Autoreach Bingwa")
            .setContentText("Keeping heartbeat and transactions in sync")
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .build()
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return
        }

        val channel = NotificationChannel(
            CHANNEL_ID,
            CHANNEL_NAME,
            NotificationManager.IMPORTANCE_LOW,
        )

        val manager = getSystemService(NotificationManager::class.java)
        manager?.createNotificationChannel(channel)
    }
}
