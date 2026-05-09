package com.nativephp.mobile.runtime

import android.content.Context
import android.os.PowerManager
import android.util.Log

object RuntimeWakeLock {
    private const val TAG = "RuntimeWakeLock"
    private const val TIMEOUT_MILLIS = 30_000L

    fun <T> withLock(context: Context, tag: String, block: () -> T): T {
        val powerManager = context.getSystemService(PowerManager::class.java)
        val wakeLock = powerManager?.newWakeLock(
            PowerManager.PARTIAL_WAKE_LOCK,
            "bingwa:$tag",
        )

        wakeLock?.acquire(TIMEOUT_MILLIS)

        return try {
            block()
        } finally {
            if (wakeLock?.isHeld == true) {
                runCatching {
                    wakeLock.release()
                }.onFailure { exception ->
                    Log.w(TAG, "Wake lock release failed for $tag: ${exception.message}")
                }
            }
        }
    }
}
