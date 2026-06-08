package com.statum.plugins.nativescheduler

import android.Manifest
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.provider.Telephony
import android.telephony.SubscriptionManager
import android.telephony.SmsMessage
import android.util.Log
import androidx.core.content.ContextCompat
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.workDataOf
import org.json.JSONObject

class BingwaIncomingSmsReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) {
            return
        }

        Log.d(TAG, "Incoming SMS broadcast received")

        if (!BingwaIncomingSmsSettings.isEnabled(context)) {
            Log.d(TAG, "Incoming SMS ignored because feature is disabled")
            return
        }

        if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECEIVE_SMS) != PackageManager.PERMISSION_GRANTED) {
            Log.w(TAG, "Incoming SMS ignored because RECEIVE_SMS is not granted")
            return
        }

        val messages = Telephony.Sms.Intents.getMessagesFromIntent(intent)
        if (messages.isEmpty()) {
            Log.d(TAG, "Incoming SMS ignored because no messages were decoded")
            return
        }

        val sender = messages.firstNotNullOfOrNull { it.displayOriginatingAddress ?: it.originatingAddress }?.trim().orEmpty()
        val body = messages.joinToString(separator = "") { it.messageBody ?: "" }.trim()

        if (sender.isBlank() || body.isBlank()) {
            Log.d(TAG, "Incoming SMS ignored because sender or body was blank")
            return
        }

        val allowAllSenders = BingwaIncomingSmsSettings.allowAllSenders(context)
        if (!BingwaIncomingSmsSettings.isTrustedSender(sender, allowAllSenders)) {
            Log.d(TAG, "Incoming SMS ignored because sender was not trusted")
            return
        }

        if (!BingwaIncomingSmsSettings.isCandidateBody(body)) {
            Log.d(TAG, "Incoming SMS ignored because body did not match a candidate pattern")
            return
        }

        val subscriptionId = subscriptionId(intent.extras)
        val simSlot = simSlot(intent.extras) ?: simSlotFromSubscriptionId(context, subscriptionId)
        val configuredSimSlot = BingwaIncomingSmsSettings.simSlot(context)

        if (!BingwaIncomingSmsSettings.isAllowedSimSlot(configuredSimSlot, simSlot)) {
            Log.i(TAG, "Incoming SMS ignored because received SIM slot is not enabled")
            return
        }

        val payload = JSONObject()
            .put("sender", sender)
            .put("body", body)
            .put("received_at_ms", receivedAtMs(messages))

        if (subscriptionId != null) {
            payload.put("subscription_id", subscriptionId)
        }

        if (simSlot != null) {
            payload.put("sim_slot", simSlot)
        }

        val workRequest = OneTimeWorkRequestBuilder<BingwaIncomingSmsWorker>()
            .setInputData(workDataOf(BingwaIncomingSmsWorker.KEY_PAYLOAD to payload.toString()))
            .build()

        WorkManager.getInstance(context.applicationContext).enqueue(workRequest)
        Log.i(TAG, "Incoming M-Pesa SMS queued for local processing")
    }

    private fun receivedAtMs(messages: Array<SmsMessage>): Long {
        return messages.firstOrNull()?.timestampMillis?.takeIf { it > 0 } ?: System.currentTimeMillis()
    }

    private fun subscriptionId(extras: Bundle?): Int? {
        if (extras == null) return null

        val keys = arrayOf(
            "subscription",
            "subscription_id",
            "subId",
            "sub_id",
            "subscriptionId",
            "android.telephony.extra.SUBSCRIPTION_INDEX"
        )

        for (key in keys) {
            val raw = extras.get(key)
            val subId = when (raw) {
                is Number -> raw.toInt()
                is String -> raw.toIntOrNull()
                else -> null
            }
            if (subId != null && subId != SubscriptionManager.INVALID_SUBSCRIPTION_ID && subId >= 0) {
                return subId
            }
        }

        return null
    }

    private fun simSlot(extras: Bundle?): String? {
        if (extras == null) return null

        val keys = arrayOf(
            "slot",
            "simId",
            "sim_id",
            "simSlot",
            "slot_id",
            "slotId",
            "slot_index",
            "phone",
            "phone_id",
            "phoneId",
            "android.telephony.extra.SLOT_INDEX"
        )

        for (key in keys) {
            val raw = extras.get(key)
            val slotIndex = when (raw) {
                is Number -> raw.toInt()
                is String -> raw.toIntOrNull()
                else -> null
            }

            if (slotIndex == 0 || slotIndex == 1) {
                return slotFromIndex(slotIndex)
            }
        }

        return null
    }

    private fun simSlotFromSubscriptionId(context: Context, subscriptionId: Int?): String? {
        if (subscriptionId == null) {
            return null
        }

        if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE) != PackageManager.PERMISSION_GRANTED) {
            return null
        }

        return try {
            val subscriptionManager = context.getSystemService(Context.TELEPHONY_SUBSCRIPTION_SERVICE) as? SubscriptionManager
            slotFromIndex(subscriptionManager?.getActiveSubscriptionInfo(subscriptionId)?.simSlotIndex)
        } catch (exception: Exception) {
            Log.w(TAG, "Unable to resolve incoming SMS subscription slot")
            null
        }
    }

    private fun slotFromIndex(slotIndex: Int?): String? {
        return when (slotIndex) {
            0 -> "slot_1"
            1 -> "slot_2"
            else -> null
        }
    }

    companion object {
        private const val TAG = "BingwaIncomingSms"
    }
}
