package com.statum.plugins.nativescheduler

import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.util.Log
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

class BingwaSchedulerService : Service() {
    companion object {
        private const val TAG = "BingwaSchedulerService"
        private const val RUNTIME_START_DELAY_MS = 15_000L

        fun start(context: Context) {
            val intent = Intent(context.applicationContext, BingwaSchedulerService::class.java)
            ContextCompat.startForegroundService(context.applicationContext, intent)
        }
    }

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var runtime: BingwaPhpRuntime? = null

    override fun onCreate() {
        super.onCreate()
        startInForeground()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (!SchedulerRuntimeState.claimEngine()) {
            Log.i(TAG, "Bingwa runtime is already active; foreground service start skipped")
            stopSelf(startId)

            return START_NOT_STICKY
        }

        if (runtime != null) {
            return START_STICKY
        }

        runtime = BingwaPhpRuntime(applicationContext)

        scope.launch {
            try {
                val activeRuntime = runtime ?: return@launch
                delay(RUNTIME_START_DELAY_MS)

                if (!activeRuntime.initialize()) {
                    stopSelf(startId)

                    return@launch
                }

                val processedJobs = activeRuntime.runSchedulerLoop { isActive }
                Log.i(TAG, "Bingwa foreground scheduler stopped after processing $processedJobs job(s)")
            } catch (exception: CancellationException) {
                throw exception
            } catch (exception: Exception) {
                Log.e(TAG, "Bingwa foreground scheduler failed", exception)
            } finally {
                runtime?.shutdown()
                runtime = null
            }
        }

        return START_STICKY
    }

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? {
        return null
    }

    private fun startInForeground() {
        val notification = BingwaSchedulerNotification.create(applicationContext)

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(
                BingwaSchedulerNotification.FOREGROUND_NOTIFICATION_ID,
                notification,
                ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC,
            )
        } else {
            startForeground(BingwaSchedulerNotification.FOREGROUND_NOTIFICATION_ID, notification)
        }
    }
}
