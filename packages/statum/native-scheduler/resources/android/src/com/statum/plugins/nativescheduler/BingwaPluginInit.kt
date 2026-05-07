package com.statum.plugins.nativescheduler

import android.content.Context
import android.util.Log
import com.nativephp.mobile.runtime.BingwaHeartbeatScheduler
import com.nativephp.mobile.runtime.BingwaSyncTransactionsScheduler

object BingwaPluginInit {
    private const val TAG = "NativeSchedulerInit"

    @JvmStatic
    fun initialize(context: Context) {
        Log.i(TAG, "Initializing Bingwa heartbeat scheduler from NativePHP init function")
        BingwaHeartbeatScheduler.register(context)
        Log.i(TAG, "Bingwa heartbeat scheduler registered from NativePHP init function")

        Log.i(TAG, "Initializing Bingwa transaction sync scheduler from NativePHP init function")
        BingwaSyncTransactionsScheduler.register(context)
        Log.i(TAG, "Bingwa transaction sync scheduler registered from NativePHP init function")
    }
}
