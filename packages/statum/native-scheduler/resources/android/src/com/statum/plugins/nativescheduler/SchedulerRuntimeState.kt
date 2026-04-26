package com.statum.plugins.nativescheduler

/**
 * Shared scheduler runtime state used to guard the NativePHP bridge while
 * the Bingwa worker is active.
 */
object SchedulerRuntimeState {
    private val stateLock = Any()

    @Volatile
    var engineActive: Boolean = false
        private set

    fun claimEngine(): Boolean = synchronized(stateLock) {
        if (engineActive) {
            false
        } else {
            engineActive = true
            true
        }
    }

    fun releaseEngine() = synchronized(stateLock) {
        engineActive = false
    }
}
