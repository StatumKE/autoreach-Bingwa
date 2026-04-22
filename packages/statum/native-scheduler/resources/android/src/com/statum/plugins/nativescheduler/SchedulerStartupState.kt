package com.statum.plugins.nativescheduler

object SchedulerStartupState {
    @Volatile
    var appBootstrapComplete: Boolean = false
}
