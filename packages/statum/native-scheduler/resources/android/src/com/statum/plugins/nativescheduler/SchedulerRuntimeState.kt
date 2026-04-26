package com.statum.plugins.nativescheduler

import android.os.SystemClock
import android.util.Log

/**
 * Shared scheduler runtime state used to guard the NativePHP bridge while
 * the Bingwa worker is active.
 */
object SchedulerRuntimeState {
    private const val TAG = "SchedulerRuntimeState"
    private const val DEFAULT_STALE_TIMEOUT_MS = 30_000L

    private val stateLock = Any()

    @Volatile
    var engineActive: Boolean = false
        private set

    private var activeOwner: String? = null
    private var lastActiveAtMs: Long = 0L

    fun claimEngine(owner: String, staleTimeoutMs: Long = DEFAULT_STALE_TIMEOUT_MS): Boolean = synchronized(stateLock) {
        val now = SystemClock.elapsedRealtime()
        val owner = owner.takeIf { it.isNotBlank() } ?: "unknown"

        if (engineActive && activeOwner == owner) {
            lastActiveAtMs = now

            return@synchronized true
        }

        if (engineActive && now - lastActiveAtMs <= staleTimeoutMs) {
            return@synchronized false
        }

        if (engineActive) {
            Log.w(TAG, "Reclaiming stale Bingwa scheduler engine owned by $activeOwner")
        }

        activeOwner = owner
        lastActiveAtMs = now
        engineActive = true
        true
    }

    fun markEngineActive(owner: String) = synchronized(stateLock) {
        if (engineActive && activeOwner == owner) {
            lastActiveAtMs = SystemClock.elapsedRealtime()
        }
    }

    fun releaseEngine(owner: String) = synchronized(stateLock) {
        if (activeOwner != owner) {
            Log.w(TAG, "Ignoring Bingwa scheduler release from non-owner $owner; active owner is $activeOwner")

            return@synchronized
        }

        activeOwner = null
        lastActiveAtMs = 0L
        engineActive = false
    }
}
