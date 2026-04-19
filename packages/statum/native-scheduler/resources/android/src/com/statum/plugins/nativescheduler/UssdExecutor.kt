package com.statum.plugins.nativescheduler

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.telephony.TelephonyManager
import android.util.Log
import androidx.core.content.ContextCompat
import kotlinx.coroutines.delay
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull
import kotlin.coroutines.resume
import android.telephony.SubscriptionManager

/**
 * Handles USSD execution for both modes:
 *
 * - **express**: Single-shot USSD string sent via [TelephonyManager.sendUssdRequest].
 *   Silent, no UI, callback-driven. Verified approach for Android 8.0+.
 *
 * - **advanced**: Interactive USSD session driven by [UssdAccessibilityService].
 *   The accessibility service detects the system USSD dialog and dismisses the
 *   confirmation prompt programmatically, hiding it under a processing overlay.
 */
class UssdExecutor(private val context: Context) {

    companion object {
        private const val TAG = "UssdExecutor"
        private const val USSD_TIMEOUT_MS = 30_000L      // 30s max per call (matches Laravel timeout)
        private const val DIALOG_RENDER_DELAY_MS = 1_500L // wait for dialer to render the dialog
    }

    /**
     * Result of a USSD execution attempt.
     */
    data class UssdResult(val success: Boolean, val message: String = "")

    /**
     * Entry point. Routes to the correct executor based on [mode].
     * Enforces a hard 30-second timeout on both paths.
     */
    suspend fun execute(code: String, mode: String, simSlot: Int = 0, isSambaza: Boolean = false): UssdResult {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.CALL_PHONE)
            != PackageManager.PERMISSION_GRANTED
        ) {
            Log.e(TAG, "CALL_PHONE permission not granted — skipping USSD")
            return UssdResult(success = false, message = "CALL_PHONE permission not granted")
        }

        // Try to fetch the specific Subscription ID for the given simSlot
        var subId = SubscriptionManager.getDefaultSubscriptionId()
        try {
            if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED) {
                val sm = context.getSystemService(Context.TELEPHONY_SUBSCRIPTION_SERVICE) as? SubscriptionManager
                if (sm != null) {
                    val info = sm.getActiveSubscriptionInfoForSimSlotIndex(simSlot)
                    if (info != null) {
                        subId = info.subscriptionId
                        Log.i(TAG, "Resolved SIM slot $simSlot to subId $subId")
                    } else {
                        Log.w(TAG, "No active subscription found in SIM slot $simSlot, falling back to default")
                    }
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to resolve subscription ID", e)
        }

        Log.i(TAG, "Executing USSD [$mode] on subId=$subId: $code")

        return withTimeoutOrNull(USSD_TIMEOUT_MS) {
            when (mode) {
                "advanced" -> runAdvanced(code, subId, isSambaza)
                else -> runExpress(code, subId)
            }
        } ?: UssdResult(success = false, message = "USSD timed out after ${USSD_TIMEOUT_MS / 1000}s")
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
                        continuation.resume(UssdResult(success = true, message = response.toString()))
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
            } catch (e: Exception) {
                Log.e(TAG, "sendUssdRequest threw exception", e)
                if (continuation.isActive) {
                    continuation.resume(UssdResult(success = false, message = e.message ?: "Unknown error"))
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
     * Handles USSD codes that require a carrier confirmation step (e.g. "Confirm sambaza?").
     *
     * Flow:
     * 1. Fail-fast if accessibility service is not enabled — avoids a 30s hang.
     * 2. Drain stale data from the response channel (leftover from a previous session).
     * 3. Dial the full USSD code via ACTION_CALL Intent (keeps the session interactive).
     * 4. Wait for [UssdAccessibilityService] to detect the dialog and forward the response text.
     * 5. Inject an empty confirmation (clicks OK/Send) via the accessibility service directly.
     * 6. Dismiss the processing overlay.
     *
     * Research:
     * - Only '#' must be encoded as '%23' in a tel: URI. Uri.encode() would also encode '*'
     *   which breaks USSD routing on the carrier network. Manual replacement is required.
     * - Starting an Activity from a Foreground Service is permitted by Android 10+ when the
     *   calling service is actively running (exception to background start restrictions).
     * - The injectInput() call goes to the main handler inside UssdAccessibilityService,
     *   so it is safe to call it from a coroutine running on Dispatchers.IO.
     */
    private suspend fun runAdvanced(code: String, subId: Int, isSambaza: Boolean): UssdResult {
        val service = UssdAccessibilityService.instance
            ?: return UssdResult(
                success = false,
                message = "Accessibility service not active — user must enable Bingwa USSD Automation in Settings"
            )

        // Drain any stale responses left over from a previous session
        while (UssdAccessibilityService.responseChannel.tryReceive().isSuccess) { /* drain */ }

        // Dial the USSD code — keeps the carrier session open for interactive steps
        dialUssd(code, subId)

        // Allow the dialer to load and the USSD dialog to render
        delay(DIALOG_RENDER_DELAY_MS)

        // Block until the accessibility service detects and forwards the dialog text
        val dialogText = UssdAccessibilityService.responseChannel.receiveCatching().getOrNull()
            ?: return UssdResult(success = false, message = "No USSD dialog detected by accessibility service")

        Log.d(TAG, "Advanced USSD dialog text: $dialogText")

        if (isSambaza) {
            // Sambaza Validation specific check
            val isSambazaSuccess = dialogText.contains("You have transferred", ignoreCase = true) 
                    && dialogText.contains("KSH", ignoreCase = true)
            
            // Clean up: inject empty input to click 'OK' and clear the pop-up
            service.injectInput("")
            delay(DIALOG_RENDER_DELAY_MS)
            
            UssdAccessibilityService.responseChannel.tryReceive()
            service.dismiss()
            
            return if (isSambazaSuccess) {
                UssdResult(success = true, message = dialogText)
            } else {
                UssdResult(success = false, message = dialogText)
            }
        }

        // --- Standard Background Worker Flow ---

        // Inject confirmation — for Kenyan telco flows the confirmation step just needs
        // the OK/Send button pressed (no text input required). injectInput("") triggers
        // the findSendButton() logic in UssdAccessibilityService.
        service.injectInput("")

        // Allow the click to process and any follow-up dialog to appear/dismiss
        delay(DIALOG_RENDER_DELAY_MS)

        // Drain any follow-up response and hide the overlay
        UssdAccessibilityService.responseChannel.tryReceive()
        service.dismiss()

        return UssdResult(success = true, message = dialogText)
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
        } catch (e: Exception) {
            Log.e(TAG, "dialUssd: failed to start dialer activity", e)
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
