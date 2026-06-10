package com.statum.plugins.nativescheduler

import android.Manifest
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.telecom.PhoneAccountHandle
import android.telecom.TelecomManager
import android.telephony.SubscriptionManager
import android.telephony.TelephonyManager
import android.util.Log
import androidx.core.content.ContextCompat
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.delay
import kotlinx.coroutines.selects.select
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull
import kotlin.coroutines.resume

private val SUCCESS_KEYWORDS = listOf(
    "successfully purchased",
    "recommendation submitted successfully",
    "you have received",
    "transaction successful",
    "successful"
)

internal fun isPurchaseSuccessMessage(message: String): Boolean {
    return SUCCESS_KEYWORDS.any { message.contains(it, ignoreCase = true) }
}

/**
 * Handles USSD execution for both modes:
 *
 * - **express**: Single-shot USSD string sent via [TelephonyManager.sendUssdRequest].
 *   Silent, no UI, callback-driven. Verified approach for Android 8.0+.
 *
 * - **advanced**: Interactive USSD session driven by [UssdAccessibilityService].
 *   The accessibility service detects the system USSD dialog and submits each
 *   parsed reply step programmatically.
 */
class UssdExecutor(private val context: Context) {

    companion object {
        private const val TAG = "UssdExecutor"
        private const val MIN_DIALOG_TIMEOUT_MS = 10_000L
        private const val MAX_DIALOG_TIMEOUT_MS = 45_000L
    }

    /**
     * Result of a USSD execution attempt.
     */
    data class UssdResult(val success: Boolean, val message: String = "")

    /**
     * Entry point. Routes to the correct executor based on [mode].
     * Enforces a hard 30-second timeout on both paths.
     */
    suspend fun execute(
        code: String,
        mode: String,
        simSlot: Int = 0,
        isSambaza: Boolean = false,
        timeoutSeconds: Int = 30,
    ): UssdResult {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.CALL_PHONE)
            != PackageManager.PERMISSION_GRANTED
        ) {
            Log.e(TAG, "CALL_PHONE permission not granted — skipping USSD")
            return UssdResult(success = false, message = "CALL_PHONE permission not granted")
        }

        val subId = resolveSubscriptionId(simSlot)

        Log.i(
            TAG,
            "Executing USSD: mode=$mode simSlot=$simSlot subId=$subId isSambaza=$isSambaza timeoutSeconds=$timeoutSeconds code=$code"
        )

        val timeoutMs = timeoutSeconds.coerceAtLeast(1) * 1000L
        return withTimeoutOrNull(timeoutMs) {
            when (mode) {
                "advanced" -> runAdvanced(code, subId, simSlot, timeoutMs)
                else -> runExpress(code, subId)
            }
        } ?: run {
            val isAccessibilityEnabled = isAccessibilityServiceEnabled(context)
            val hint = if (!isAccessibilityEnabled) {
                " (Accessibility service is not active; please enable 'Bingwa USSD Automation' in Settings)"
            } else {
                ""
            }
            UssdResult(success = false, message = "USSD timed out after ${timeoutSeconds}s$hint")
        }
    }

    private fun resolveSubscriptionId(simSlot: Int): Int {
        val defaultSubId = SubscriptionManager.getDefaultSubscriptionId()

        if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE)
            != PackageManager.PERMISSION_GRANTED
        ) {
            return defaultSubId
        }

        return try {
            val subscriptionManager =
                context.getSystemService(Context.TELEPHONY_SUBSCRIPTION_SERVICE) as? SubscriptionManager
            val subscriptionInfo = subscriptionManager?.getActiveSubscriptionInfoForSimSlotIndex(simSlot)

            if (subscriptionInfo == null) {
                Log.w(TAG, "No active subscription found in SIM slot $simSlot, falling back to default")
                defaultSubId
            } else {
                Log.i(TAG, "Resolved SIM slot $simSlot to subId ${subscriptionInfo.subscriptionId}")
                subscriptionInfo.subscriptionId
            }
        } catch (exception: Exception) {
            Log.e(TAG, "Failed to resolve subscription ID", exception)
            defaultSubId
        }
    }

    // -------------------------------------------------------------------------
    // Express Mode — TelephonyManager.sendUssdRequest()
    // -------------------------------------------------------------------------

    /**
     * Sends the complete USSD code in a single network request.
     * Suspends the calling coroutine until the carrier callback fires.
     *
     * Research: sendUssdRequest() is the official API for this (API 26+). It is non-interactive:
     * the carrier processes the full string (e.g. *180*5*7*0712345678#) and returns one response.
     * The Handler must run on the main looper for the callback to be delivered correctly.
     */
    private suspend fun runExpress(code: String, subId: Int): UssdResult {
        val resultChannel = Channel<UssdResult>(Channel.CONFLATED)
        var telephonyManager = context.getSystemService(Context.TELEPHONY_SERVICE) as TelephonyManager

        // Route to the specific SIM
        if (subId != SubscriptionManager.INVALID_SUBSCRIPTION_ID) {
            telephonyManager = telephonyManager.createForSubscriptionId(subId)
        }

        val handler = Handler(Looper.getMainLooper())

        val callback = object : TelephonyManager.UssdResponseCallback() {
            override fun onReceiveUssdResponse(
                tm: TelephonyManager,
                request: String,
                response: CharSequence
            ) {
                Log.d(TAG, "Express USSD callback success: $response")
                val message = response.toString()
                resultChannel.trySend(
                    UssdResult(
                        success = isPurchaseSuccessMessage(message),
                        message = message,
                    )
                )
            }

            override fun onReceiveUssdResponseFailed(
                tm: TelephonyManager,
                request: String,
                failureCode: Int
            ) {
                val reason = ussdFailureReason(failureCode)
                Log.w(TAG, "Express USSD callback failed: code=$failureCode — $reason")
                resultChannel.trySend(UssdResult(success = false, message = reason))
            }
        }

        // Set up local receiver and channel for IPC
        val executorChannel = Channel<String>(Channel.CONFLATED)
        val receiver = object : BroadcastReceiver() {
            override fun onReceive(ctx: Context?, intent: Intent?) {
                if (intent == null) return
                if (intent.action == UssdAccessibilityService.ACTION_USSD_DIALOG) {
                    val text = intent.getStringExtra(UssdAccessibilityService.EXTRA_TEXT)
                    if (text != null) {
                        Log.d(TAG, "UssdExecutor received dialog broadcast in Express: $text")
                        executorChannel.trySend(text)
                    }
                }
            }
        }

        val filter = IntentFilter(UssdAccessibilityService.ACTION_USSD_DIALOG)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            context.registerReceiver(receiver, filter, Context.RECEIVER_EXPORTED)
        } else {
            context.registerReceiver(receiver, filter)
        }

        try {
            telephonyManager.sendUssdRequest(code, callback, handler)

            // Race between the telephony callback and the accessibility service channel
            val result = select<UssdResult> {
                resultChannel.onReceive { it }

                executorChannel.onReceive { dialogText ->
                    Log.d(TAG, "Express USSD captured via Accessibility: $dialogText")
                    UssdResult(
                        success = isPurchaseSuccessMessage(dialogText),
                        message = dialogText
                    )
                }
            }

            // If telephony callback won, check if a dialog appears within 1.5 seconds to dismiss it
            if (result.message.isNotEmpty() && !executorChannel.tryReceive().isSuccess) {
                withTimeoutOrNull(1500) {
                    executorChannel.receive()
                    Log.d(TAG, "Express USSD dialog appeared after telephony callback")
                }
            }

            return result
        } catch (exception: Exception) {
            Log.e(TAG, "sendUssdRequest threw exception", exception)
            return UssdResult(success = false, message = exception.message ?: "Unknown error")
        } finally {
            // Dismiss via broadcast
            val dismissIntent = Intent(UssdAccessibilityService.ACTION_USSD_DISMISS)
            context.sendBroadcast(dismissIntent)

            try {
                context.unregisterReceiver(receiver)
            } catch (e: Exception) {
                Log.e(TAG, "Failed to unregister receiver in finally block of runExpress", e)
            }
        }
    }

    // -------------------------------------------------------------------------
    // Advanced Mode — AccessibilityService-driven interactive USSD
    // -------------------------------------------------------------------------

    /**
     * Handles USSD codes that require a carrier confirmation step or a parsed sequence of
     * interactive replies.
     *
     * Flow:
     * 1. Fail-fast if accessibility service is not enabled — avoids a 30s hang.
     * 2. Drain stale data from the response channel (leftover from a previous session).
     * 3. Dial only the parsed base USSD code via ACTION_CALL Intent (keeps the session interactive).
     * 4. Wait for [UssdAccessibilityService] to detect the first dialog and forward the text.
     * 5. Parse the code into a base dial string plus ordered reply steps, then submit each
     *    reply sequentially through the accessibility service.
     * 6. Reset accessibility session state.
     *
     * Research:
     * - Only '#' must be encoded as '%23' in a tel: URI. Uri.encode() would also encode '*'
     *   which breaks USSD routing on the carrier network. Manual replacement is required.
     * - Starting an Activity from a Foreground Service is permitted by Android 10+ when the
     *   calling service is actively running (exception to background start restrictions).
     * - The injectInput() call goes to the main handler inside UssdAccessibilityService,
     *   so it is safe to call it from a coroutine running on Dispatchers.IO.
     */
    private suspend fun runAdvanced(code: String, subId: Int, simSlot: Int, timeoutMs: Long): UssdResult {
        val hasService = awaitAccessibilityService()
        if (!hasService) {
            Log.w(TAG, "Accessibility service not active — redirecting user to Settings")
            try {
                val intent = Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS).apply {
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                }
                context.startActivity(intent)
            } catch (exception: Exception) {
                Log.e(TAG, "Failed to open accessibility settings", exception)
            }
            return UssdResult(
                success = false,
                message = "Accessibility service not active — please enable 'Bingwa USSD Automation' in the settings page that just opened."
            )
        }

        // Set up local receiver and channel for IPC
        val executorChannel = Channel<String>(Channel.CONFLATED)
        val receiver = object : BroadcastReceiver() {
            override fun onReceive(ctx: Context?, intent: Intent?) {
                if (intent == null) return
                if (intent.action == UssdAccessibilityService.ACTION_USSD_DIALOG) {
                    val text = intent.getStringExtra(UssdAccessibilityService.EXTRA_TEXT)
                    if (text != null) {
                        Log.d(TAG, "UssdExecutor received dialog broadcast: $text")
                        executorChannel.trySend(text)
                    }
                }
            }
        }

        val filter = IntentFilter(UssdAccessibilityService.ACTION_USSD_DIALOG)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            context.registerReceiver(receiver, filter, Context.RECEIVER_EXPORTED)
        } else {
            context.registerReceiver(receiver, filter)
        }

        val parsedFlow = parseAdvancedUssdFlow(code)
        Log.i(
            TAG,
            "Advanced USSD flow parsed: base=${parsedFlow.baseDialCode}, replies=${parsedFlow.replies}"
        )

        // Pass active sim slot to the accessibility service via broadcast
        val simIntent = Intent(UssdAccessibilityService.ACTION_USSD_SET_SIM).apply {
            putExtra(UssdAccessibilityService.EXTRA_SIM_SLOT, simSlot)
        }
        context.sendBroadcast(simIntent)

        // Acquire WakeLock to wake the screen and keep CPU running
        val powerManager = context.getSystemService(Context.POWER_SERVICE) as? PowerManager
        @Suppress("DEPRECATION")
        val wakeLock = powerManager?.newWakeLock(
            PowerManager.SCREEN_BRIGHT_WAKE_LOCK or PowerManager.ACQUIRE_CAUSES_WAKEUP,
            "Bingwa:UssdWakeLock"
        )
        wakeLock?.acquire(timeoutMs + 5000L) // Add 5s padding

        try {
            // Dial only the base USSD code — replies are submitted step-by-step after the dialog opens.
            dialUssd(parsedFlow.baseDialCode, subId)

            val dialogTimeoutMs = dialogTimeoutMs(timeoutMs)
            val dialogText = awaitDialogText(executorChannel, timeoutMs = dialogTimeoutMs)
                ?: run {
                    Log.w(TAG, "Advanced USSD first dialog timeout after ${dialogTimeoutMs}ms")
                    return UssdResult(success = false, message = "No USSD dialog detected by accessibility service")
                }

            Log.d(TAG, "Advanced USSD dialog text: $dialogText")

            var resolvedDialogText = dialogText
            var lastCompletedStep = 0
            var carrierTerminatedOnFinalStep = false

            for ((index, reply) in parsedFlow.replies.withIndex()) {
                val stepNum = index + 1
                val totalReplies = parsedFlow.replies.size
                Log.d(TAG, "Advanced USSD reply step $stepNum/$totalReplies: $reply")

                // Inject reply via broadcast
                val injectIntent = Intent(UssdAccessibilityService.ACTION_USSD_INJECT).apply {
                    putExtra(UssdAccessibilityService.EXTRA_INPUT, reply)
                }
                context.sendBroadcast(injectIntent)

                val nextDialogText = awaitFollowUpDialog(executorChannel, resolvedDialogText, dialogTimeoutMs)
                if (nextDialogText.isNullOrBlank()) {
                    Log.w(TAG, "No follow-up USSD dialog after reply step $stepNum/$totalReplies: $reply")
                    if (stepNum == totalReplies) {
                        carrierTerminatedOnFinalStep = true
                    }
                    break
                }

                resolvedDialogText = nextDialogText
                lastCompletedStep = index + 1
                Log.d(TAG, "Advanced USSD dialog after reply step ${index + 1}: $resolvedDialogText")
            }

            executorChannel.tryReceive()

            val finalMessage = when {
                lastCompletedStep >= parsedFlow.replies.size || carrierTerminatedOnFinalStep -> {
                    Log.d(TAG, "Advanced USSD carrier terminal response: $resolvedDialogText")
                    resolvedDialogText
                }
                else -> {
                    val replyOption = parsedFlow.replies[lastCompletedStep]
                    val replyNum = lastCompletedStep + 1
                    val totalReplies = parsedFlow.replies.size
                    val cleanMenu = cleanMenuMessage(resolvedDialogText)
                    "USSD session interrupted after selecting option '$replyOption' (Reply step $replyNum of $totalReplies). Response: $cleanMenu"
                }
            }

            return UssdResult(
                success = isPurchaseSuccessMessage(finalMessage),
                message = finalMessage,
            )
        } finally {
            // Dismiss via broadcast
            val dismissIntent = Intent(UssdAccessibilityService.ACTION_USSD_DISMISS)
            context.sendBroadcast(dismissIntent)

            try {
                context.unregisterReceiver(receiver)
            } catch (e: Exception) {
                Log.e(TAG, "Failed to unregister receiver in finally block", e)
            }

            if (wakeLock?.isHeld == true) {
                wakeLock.release()
                Log.d(TAG, "Advanced USSD released WakeLock")
            }
        }
    }

    private suspend fun awaitAccessibilityService(timeoutMs: Long = 5_000L): Boolean {
        val deadline = System.currentTimeMillis() + timeoutMs

        while (System.currentTimeMillis() < deadline) {
            if (isAccessibilityServiceEnabled(context)) {
                return true
            }
            delay(200L)
        }

        return isAccessibilityServiceEnabled(context)
    }

    private fun isAccessibilityServiceEnabled(context: Context): Boolean {
        val expectedServiceName = "${context.packageName}/${UssdAccessibilityService::class.java.name}"
        val enabledServices = Settings.Secure.getString(
            context.contentResolver,
            Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES
        ) ?: return false
        if (!enabledServices.split(':').any { it.equals(expectedServiceName, ignoreCase = true) }) {
            return false
        }
        val am = context.getSystemService(Context.ACCESSIBILITY_SERVICE) as? android.view.accessibility.AccessibilityManager
        val runningServices = am?.getEnabledAccessibilityServiceList(android.view.accessibility.AccessibilityEvent.TYPES_ALL_MASK)
        return runningServices?.any { service ->
            service.id.equals(expectedServiceName, ignoreCase = true)
        } ?: false
    }

    private fun dialogTimeoutMs(totalTimeoutMs: Long): Long {
        return (totalTimeoutMs / 2)
            .coerceAtLeast(MIN_DIALOG_TIMEOUT_MS)
            .coerceAtMost(MAX_DIALOG_TIMEOUT_MS)
    }

    private suspend fun awaitFollowUpDialog(
        responseChannel: Channel<String>,
        initialDialogText: String,
        timeoutMs: Long,
    ): String? {
        return withTimeoutOrNull(timeoutMs) {
            while (true) {
                val nextDialogText = responseChannel
                    .receiveCatching()
                    .getOrNull()
                    ?: break

                if (nextDialogText.isNotBlank() && nextDialogText != initialDialogText) {
                    return@withTimeoutOrNull nextDialogText
                }
            }

            null
        }
    }

    private suspend fun awaitDialogText(
        responseChannel: Channel<String>,
        timeoutMs: Long
    ): String? {
        return withTimeoutOrNull(timeoutMs) {
            while (true) {
                val nextDialogText = responseChannel
                    .receiveCatching()
                    .getOrNull()
                    ?: break

                if (nextDialogText.isNotBlank()) {
                    return@withTimeoutOrNull nextDialogText
                }
            }

            null
        }
    }

    /**
     * Dials a USSD code via the system phone stack using ACTION_CALL.
     *
     * Critical: Only '#' is encoded as '%23'. '*' must NOT be encoded —
     * Uri.encode() would produce '%2A' which breaks USSD menu routing.
     */
    private fun dialUssd(code: String, subId: Int) {
        val encodedCode = code.replace("#", "%23")
        val intent = Intent(Intent.ACTION_CALL, Uri.parse("tel:$encodedCode")).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            if (subId != SubscriptionManager.INVALID_SUBSCRIPTION_ID) {
                resolvePhoneAccountHandle(subId)?.let { handle ->
                    putExtra(TelecomManager.EXTRA_PHONE_ACCOUNT_HANDLE, handle)
                    Log.i(TAG, "Advanced USSD routing resolved PhoneAccountHandle for subId=$subId")
                } ?: Log.w(
                    TAG,
                    "Advanced USSD routing has no verified PhoneAccountHandle for subId=$subId; using subscription extras fallback"
                )
                putExtra("android.telephony.extra.SUBSCRIPTION_INDEX", subId)
                putExtra("subscription", subId)
            }
        }
        try {
            context.startActivity(intent)
        } catch (exception: Exception) {
            Log.e(TAG, "dialUssd: failed to start dialer activity", exception)
        }
    }

    private fun resolvePhoneAccountHandle(subId: Int): PhoneAccountHandle? {
        if (subId == SubscriptionManager.INVALID_SUBSCRIPTION_ID
            || Build.VERSION.SDK_INT < Build.VERSION_CODES.R
            || ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE)
            != PackageManager.PERMISSION_GRANTED
        ) {
            Log.i(
                TAG,
                "Advanced USSD PhoneAccountHandle lookup unavailable: subId=$subId sdk=${Build.VERSION.SDK_INT}"
            )

            return null
        }

        val telecomManager = context.getSystemService(Context.TELECOM_SERVICE) as? TelecomManager
        val telephonyManager = context.getSystemService(Context.TELEPHONY_SERVICE) as? TelephonyManager

        if (telecomManager == null || telephonyManager == null) {
            Log.w(TAG, "Advanced USSD PhoneAccountHandle lookup skipped because telecom services are unavailable")

            return null
        }

        return try {
            telecomManager.callCapablePhoneAccounts.firstOrNull { handle ->
                telephonyManager.getSubscriptionId(handle) == subId
            }
        } catch (exception: SecurityException) {
            Log.w(TAG, "Advanced USSD PhoneAccountHandle lookup denied by Android", exception)
            null
        } catch (exception: Exception) {
            Log.w(TAG, "Advanced USSD PhoneAccountHandle lookup failed", exception)
            null
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private fun ussdFailureReason(code: Int): String = when (code) {
        TelephonyManager.USSD_RETURN_FAILURE -> "Network returned a general failure"
        TelephonyManager.USSD_ERROR_SERVICE_UNAVAIL -> "USSD service unavailable on this network"
        else -> "Unknown USSD failure code: $code"
    }

    private fun cleanMenuMessage(message: String): String {
        val lines = message.lines().map { it.trim() }.filter { it.isNotEmpty() }
        if (lines.isEmpty()) return ""
        return lines.first()
    }
}
