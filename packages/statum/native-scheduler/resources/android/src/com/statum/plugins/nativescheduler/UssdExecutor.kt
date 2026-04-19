package com.statum.plugins.nativescheduler

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.telephony.TelephonyManager
import android.util.Log
import androidx.core.content.ContextCompat
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull
import kotlin.coroutines.resume

/**
 * Handles USSD execution for both modes:
 *
 * - **express**: Single-shot USSD string sent directly via [TelephonyManager.sendUssdRequest].
 *   No UI interaction required. Fast, clean, silent on modern Android.
 *
 * - **advanced**: Multi-step interactive USSD session driven by [UssdAccessibilityService].
 *   The accessibility service intercepts the system USSD dialog and steps through it
 *   programmatically while hiding it under a processing overlay.
 */
class UssdExecutor(private val context: Context) {

    companion object {
        private const val TAG = "UssdExecutor"
        private const val USSD_TIMEOUT_MS = 30_000L // 30 seconds max per USSD session
    }

    /**
     * Result of a USSD execution attempt.
     */
    data class UssdResult(val success: Boolean, val message: String = "")

    /**
     * Execute a USSD code. Routes to [runExpress] or [runAdvanced] based on [mode].
     */
    suspend fun execute(code: String, mode: String): UssdResult {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.CALL_PHONE)
            != PackageManager.PERMISSION_GRANTED
        ) {
            Log.e(TAG, "CALL_PHONE permission not granted — cannot execute USSD")
            return UssdResult(success = false, message = "CALL_PHONE permission not granted")
        }

        Log.i(TAG, "Executing USSD [$mode]: $code")

        return withTimeoutOrNull(USSD_TIMEOUT_MS) {
            when (mode) {
                "advanced" -> runAdvanced(code)
                else -> runExpress(code)
            }
        } ?: UssdResult(success = false, message = "USSD timed out after ${USSD_TIMEOUT_MS / 1000}s")
    }

    // -------------------------------------------------------------------------
    // Express Mode
    // -------------------------------------------------------------------------

    /**
     * Sends the full USSD code in one shot using [TelephonyManager.sendUssdRequest].
     * Suspends the coroutine until the network callback returns (or times out).
     */
    private suspend fun runExpress(code: String): UssdResult =
        suspendCancellableCoroutine { continuation ->
            val telephonyManager = context.getSystemService(Context.TELEPHONY_SERVICE) as TelephonyManager
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
                    Log.w(TAG, "Express USSD failed: code=$failureCode ($reason)")
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
        }

    // -------------------------------------------------------------------------
    // Advanced Mode
    // -------------------------------------------------------------------------

    /**
     * Drives a multi-step USSD session via [UssdAccessibilityService].
     *
     * Flow:
     * 1. Trigger the initial USSD dial via a tel: Intent
     * 2. Wait for the accessibility service to detect the dialog and send the response text
     * 3. The USSD code itself encodes the full menu path (*180*5*7*PN#), so we only need
     *    one send for the initial dial — advanced mode uses this path for complex codes where
     *    the telco may prompt for confirmation, which we handle by clicking OK.
     */
    private suspend fun runAdvanced(code: String): UssdResult {
        // Dial the initial USSD code via Intent — this opens the system dialer USSD session
        dialUssd(code)

        // Wait for the accessibility service to pick up the dialog
        val response = UssdAccessibilityService.responseChannel.receiveCatching().getOrNull()
            ?: return UssdResult(success = false, message = "No USSD response received")

        Log.d(TAG, "Advanced USSD response: $response")

        // If the dialog is a confirmation/OK screen (no further input needed), dismiss it
        // by injecting an empty string — the service's findSendButton will click OK/Send
        UssdAccessibilityService.inputChannel.trySend("")

        // Small pause for the service to process the click
        kotlinx.coroutines.delay(500)
        UssdAccessibilityService.responseChannel.tryReceive() // drain any followup

        UssdAccessibilityService::class.java.getDeclaredMethod("dismiss")
        val service = getAccessibilityService()
        service?.dismiss()

        return UssdResult(success = true, message = response)
    }

    /**
     * Opens a USSD code through the system phone dialer via Intent.
     * Used as the entry-point for advanced mode sessions.
     */
    private fun dialUssd(code: String) {
        val encodedCode = Uri.encode(code)
        val intent = android.content.Intent(
            android.content.Intent.ACTION_CALL,
            Uri.parse("tel:$encodedCode")
        ).apply {
            addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        context.startActivity(intent)
    }

    /**
     * Retrieves the active [UssdAccessibilityService] instance.
     * Returns null if the service is not running (not granted by user).
     */
    private fun getAccessibilityService(): UssdAccessibilityService? {
        return UssdAccessibilityService.instance
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private fun ussdFailureReason(code: Int): String = when (code) {
        TelephonyManager.USSD_RETURN_FAILURE -> "Network returned a general failure"
        TelephonyManager.USSD_ERROR_SERVICE_UNAVAIL -> "USSD service unavailable on this network"
        else -> "Unknown failure code: $code"
    }
}
