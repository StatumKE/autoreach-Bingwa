package com.statum.plugins.nativescheduler

import android.content.Context
import java.util.Locale

internal object BingwaIncomingSmsSettings {
    private const val PREFS = "bingwa_incoming_sms_settings"
    private const val KEY_ENABLED = "enabled"
    private const val KEY_ALLOW_ALL_SENDERS = "allow_all_senders"
    private const val KEY_SIM_SLOT = "sim_slot"
    private val trustedSenders = setOf("MPESA", "M-PESA")

    fun isEnabled(context: Context): Boolean {
        return prefs(context).getBoolean(KEY_ENABLED, true)
    }

    fun allowAllSenders(context: Context): Boolean {
        return prefs(context).getBoolean(KEY_ALLOW_ALL_SENDERS, false)
    }

    fun simSlot(context: Context): String {
        return prefs(context).getString(KEY_SIM_SLOT, "all")
            ?.takeIf { it == "all" || it == "slot_1" || it == "slot_2" }
            ?: "all"
    }

    fun update(context: Context, enabled: Boolean, allowAllSenders: Boolean, simSlot: String) {
        prefs(context).edit()
            .putBoolean(KEY_ENABLED, enabled)
            .putBoolean(KEY_ALLOW_ALL_SENDERS, allowAllSenders)
            .putString(KEY_SIM_SLOT, simSlot.takeIf { it == "all" || it == "slot_1" || it == "slot_2" } ?: "all")
            .apply()
    }

    fun isTrustedSender(sender: String, allowAllSenders: Boolean): Boolean {
        if (allowAllSenders) {
            return true
        }

        return sender.trim().uppercase(Locale.US) in trustedSenders
    }

    fun isAllowedSimSlot(configuredSimSlot: String, receivedSimSlot: String?): Boolean {
        if (configuredSimSlot == "all") {
            return true
        }

        return receivedSimSlot != null && configuredSimSlot == receivedSimSlot
    }

    fun isCandidateBody(body: String): Boolean {
        val normalized = body.lowercase(Locale.US)

        return normalized.contains("confirmed.") && normalized.contains("received")
    }

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
}
