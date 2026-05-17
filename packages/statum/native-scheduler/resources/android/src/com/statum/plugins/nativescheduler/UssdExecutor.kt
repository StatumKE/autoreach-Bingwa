package com.statum.plugins.nativescheduler

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.telephony.SubscriptionManager
import android.telephony.TelephonyManager
import android.util.Log
import androidx.core.content.ContextCompat
import kotlinx.coroutines.delay
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
        private const val FIRST_DIALOG_TIMEOUT_MS = 10_000L
        private const val FOLLOW_UP_DIALOG_TIMEOUT_MS = 10_000L
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

        Log.i(TAG, "Executing USSD [$mode] on subId=$subId (timeout ${timeoutSeconds}s): $code")

        val timeoutMs = timeoutSeconds * 1000L
        return withTimeoutOrNull(timeoutMs) {
            when (mode) {
                "advanced" -> runAdvanced(code, subId)
                else -> runExpress(code, subId)
            }
        } ?: UssdResult(success = false, message = "USSD timed out after ${timeoutSeconds}s")
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
    private suspend fun runExpress(code: String, subId: Int): UssdResult =
        suspendCancellableCoroutine { continuation ->
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
                    Log.d(TAG, "Express USSD success: $response")
                    if (continuation.isActive) {
                        val message = response.toString()
                        continuation.resume(
                            UssdResult(
                                success = isPurchaseSuccessMessage(message),
                                message = message,
                            )
                        )
                    }
                }

                override fun onReceiveUssdResponseFailed(
                    tm: TelephonyManager,
                    request: String,
                    failureCode: Int
                ) {
                    val reason = ussdFailureReason(failureCode)
                    Log.w(TAG, "Express USSD failed: code=$failureCode — $reason")
                    if (continuation.isActive) {
                        continuation.resume(UssdResult(success = false, message = reason))
                    }
                }
            }

            try {
                telephonyManager.sendUssdRequest(code, callback, handler)
            } catch (exception: Exception) {
                Log.e(TAG, "sendUssdRequest threw exception", exception)
                if (continuation.isActive) {
                    continuation.resume(UssdResult(success = false, message = exception.message ?: "Unknown error"))
                }
            }

            // Note: TelephonyManager has no cancel mechanism once sent to the modem.
            // invokeOnCancellation only cleans up coroutine state.
            continuation.invokeOnCancellation {
                Log.w(TAG, "Express USSD coroutine cancelled — modem request may still complete")
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
    private suspend fun runAdvanced(code: String, subId: Int): UssdResult {
        val service = awaitAccessibilityService()
        if (service == null) {
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

        // Drain any stale responses left over from a previous session
        while (UssdAccessibilityService.responseChannel.tryReceive().isSuccess) { /* drain */ }

        val parsedFlow = parseAdvancedUssdFlow(code)
        Log.i(
            TAG,
            "Advanced USSD flow parsed: base=${parsedFlow.baseDialCode}, replies=${parsedFlow.replies}"
        )

        // Dial only the base USSD code — replies are submitted step-by-step after the dialog opens.
        dialUssd(parsedFlow.baseDialCode, subId)

        val dialogText = awaitDialogText(timeoutMs = FIRST_DIALOG_TIMEOUT_MS)
            ?: return UssdResult(success = false, message = "No USSD dialog detected by accessibility service")

        Log.d(TAG, "Advanced USSD dialog text: $dialogText")

        var resolvedDialogText = dialogText

        for ((index, reply) in parsedFlow.replies.withIndex()) {
            Log.d(
                TAG,
                "Advanced USSD reply step ${index + 1}/${parsedFlow.replies.size}: $reply"
            )

            service.injectInput(reply)

            val nextDialogText = awaitFollowUpDialog(resolvedDialogText)
            if (nextDialogText.isNullOrBlank()) {
                Log.w(
                    TAG,
                    "No follow-up USSD dialog detected after reply step ${index + 1}: $reply"
                )
                break
            }

            resolvedDialogText = nextDialogText
            Log.d(TAG, "Advanced USSD dialog after reply step ${index + 1}: $resolvedDialogText")
        }

        UssdAccessibilityService.responseChannel.tryReceive()
        service.dismiss()

        return UssdResult(
            success = isPurchaseSuccessMessage(resolvedDialogText),
            message = resolvedDialogText,
        )
    }

    /**
     * Wait briefly for the accessibility service singleton to become available.
     *
     * The service may already be enabled in Settings but still be reconnecting after
     * a process restart. Failing immediately turns a recoverable timing race into a false error.
     */
    private suspend fun awaitAccessibilityService(timeoutMs: Long = 5_000L): UssdAccessibilityService? {
        val deadline = System.currentTimeMillis() + timeoutMs

        while (System.currentTimeMillis() < deadline) {
            UssdAccessibilityService.instance?.let { return it }
            delay(200L)
        }

        return UssdAccessibilityService.instance
    }

    private suspend fun awaitFollowUpDialog(
        initialDialogText: String,
        timeoutMs: Long = FOLLOW_UP_DIALOG_TIMEOUT_MS,
    ): String? {
        return withTimeoutOrNull(timeoutMs) {
            while (true) {
                val nextDialogText = UssdAccessibilityService.responseChannel
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

    private suspend fun awaitDialogText(timeoutMs: Long): String? {
        return withTimeoutOrNull(timeoutMs) {
            while (true) {
                val nextDialogText = UssdAccessibilityService.responseChannel
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
                putExtra("android.telecom.extra.PHONE_ACCOUNT_HANDLE", subId)
                putExtra("android.telephony.extra.SUBSCRIPTION_INDEX", subId)
                putExtra("subscription", subId) // legacy support
            }
        }
        try {
            context.startActivity(intent)
        } catch (exception: Exception) {
            Log.e(TAG, "dialUssd: failed to start dialer activity", exception)
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
}
