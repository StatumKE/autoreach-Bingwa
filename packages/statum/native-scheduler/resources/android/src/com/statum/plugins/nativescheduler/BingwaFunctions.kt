package com.statum.plugins.nativescheduler

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import android.util.Log
import android.telephony.SmsManager
import android.telephony.SubscriptionManager
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withTimeoutOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody



object BingwaFunctions {
    private const val SETUP_PERMISSION_REQUEST_CODE = 5107
    private const val PERMISSION_SETUP_PREFS = "bingwa_permission_setup"
    private const val PERMISSION_SETUP_REQUESTED = "requested"
    private const val TAG = "BingwaPermissions"
    private const val USSD_LOCK_WAIT_MS = 2_000L
    internal val ussdMutex = Mutex()

    class TriggerSambaza(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return executeUssd(context, parameters, defaultMode = "express", defaultIsSambaza = true, runAsync = false)
        }
    }

    class ExecuteUssd(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            replayBufferedCallbacks(context)
            val runAsyncVal = parameters["runAsync"] ?: parameters["run_async"]
            val runAsync = when (runAsyncVal) {
                is Boolean -> runAsyncVal
                is String -> runAsyncVal.trim().lowercase() == "true" || runAsyncVal.trim() == "1"
                is Number -> runAsyncVal.toInt() != 0
                else -> true
            }
            return executeUssd(context, parameters, defaultMode = "express", defaultIsSambaza = false, runAsync = runAsync)
        }
    }

    class SendSms(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val destination = (parameters["destination"] as? String ?: parameters["phone"] as? String ?: "").trim()
            val message = (parameters["message"] as? String ?: parameters["body"] as? String ?: "").trim()
            val simSlot = (parameters["simSlot"] as? Number)?.toInt() ?: 0

            if (destination.isBlank() || message.isBlank()) {
                return mapOf(
                    "success" to false,
                    "message" to "Missing destination or message",
                )
            }

            if (ContextCompat.checkSelfPermission(context, Manifest.permission.SEND_SMS)
                != PackageManager.PERMISSION_GRANTED
            ) {
                return mapOf(
                    "success" to false,
                    "message" to "SEND_SMS permission not granted",
                )
            }

            if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE)
                != PackageManager.PERMISSION_GRANTED
            ) {
                return mapOf(
                    "success" to false,
                    "message" to "READ_PHONE_STATE permission not granted",
                )
            }

            if (!context.packageManager.hasSystemFeature(PackageManager.FEATURE_TELEPHONY_MESSAGING)) {
                return mapOf(
                    "success" to false,
                    "message" to "Device does not support telephony messaging",
                )
            }

            val subscriptionId = resolveSubscriptionId(context, simSlot)

            if (subscriptionId == SubscriptionManager.INVALID_SUBSCRIPTION_ID) {
                return mapOf(
                    "success" to false,
                    "message" to "No active SIM found for slot $simSlot",
                )
            }

            val smsManager = SmsManager.getSmsManagerForSubscriptionId(subscriptionId)

            return try {
                if (message.length > 160) {
                    val parts = smsManager.divideMessage(message)
                    smsManager.sendMultipartTextMessage(destination, null, parts, null, null)
                } else {
                    smsManager.sendTextMessage(destination, null, message, null, null)
                }

                mapOf(
                    "success" to true,
                    "message" to "SMS submitted successfully.",
                    "simSlot" to simSlot,
                    "subscriptionId" to subscriptionId,
                )
            } catch (exception: Exception) {
                Log.e(TAG, "Failed to send SMS", exception)
                mapOf(
                    "success" to false,
                    "message" to (exception.message ?: "Failed to send SMS"),
                    "simSlot" to simSlot,
                    "subscriptionId" to subscriptionId,
                )
            }
        }
    }

    class CheckSetupStatus(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            replayBufferedCallbacks(context)
            return mapOf(
                "phoneGranted" to isPhoneGranted(context),
                "smsGranted" to isSmsGranted(context),
                "incomingSmsGranted" to isIncomingSmsGranted(context),
                "outboundSmsGranted" to isOutboundSmsGranted(context),
                "notificationsGranted" to isNotificationsGranted(context),
                "batteryUnrestricted" to isBatteryUnrestricted(context),
                "accessibilityEnabled" to isAccessibilityServiceEnabled(context),
                "overlayGranted" to canDrawOverlays(context),
            )
        }
    }

    class UpdateIncomingSmsSettings(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val enabled = parameters["enabled"] as? Boolean ?: true
            val allowAllSenders = parameters["allowAllSenders"] as? Boolean ?: false
            val simSlot = (parameters["simSlot"] as? String)
                ?.takeIf { it == "all" || it == "slot_1" || it == "slot_2" }
                ?: "all"

            BingwaIncomingSmsSettings.update(
                context = context,
                enabled = enabled,
                allowAllSenders = allowAllSenders,
                simSlot = simSlot,
            )

            return mapOf(
                "success" to true,
                "enabled" to enabled,
                "allowAllSenders" to allowAllSenders,
                "simSlot" to simSlot,
            )
        }
    }

    class RequestRuntimePermissions(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val missing = linkedSetOf<String>()

            if (!isPhoneGranted(activity)) {
                missing.add(Manifest.permission.CALL_PHONE)
                missing.add(Manifest.permission.READ_PHONE_STATE)
            }
            if (!isSmsGranted(activity)) {
                missing.add(Manifest.permission.RECEIVE_SMS)
                missing.add(Manifest.permission.READ_PHONE_STATE)
            }
            if (!isNotificationsGranted(activity)) {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                    missing.add(Manifest.permission.POST_NOTIFICATIONS)
                }
            }

            if (missing.isEmpty()) {
                return mapOf(
                    "requested" to false,
                    "phoneGranted" to true,
                    "smsGranted" to true,
                    "notificationsGranted" to true,
                )
            }

            activity.runOnUiThread {
                ActivityCompat.requestPermissions(
                    activity,
                    missing.toTypedArray(),
                    SETUP_PERMISSION_REQUEST_CODE
                )
            }

            return mapOf(
                "requested" to true,
                "phoneGranted" to isPhoneGranted(activity),
                "smsGranted" to isSmsGranted(activity),
                "notificationsGranted" to isNotificationsGranted(activity),
            )
        }
    }

    /**
     * Request SEND_SMS permission on demand — only needed when the user activates
     * an auto-reply rule. Not requested during initial app setup.
     */
    class RequestOutboundSmsPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (isOutboundSmsGranted(activity)) {
                return mapOf(
                    "requested" to false,
                    "outboundSmsGranted" to true,
                )
            }

            val sharedPrefs = activity.getSharedPreferences("bingwa_prefs", Context.MODE_PRIVATE)
            val hasRequestedSendSms = sharedPrefs.getBoolean("has_requested_send_sms", false)

            var permanentlyDenied = false
            if (hasRequestedSendSms) {
                permanentlyDenied = !ActivityCompat.shouldShowRequestPermissionRationale(
                    activity,
                    Manifest.permission.SEND_SMS,
                )
            } else {
                sharedPrefs.edit().putBoolean("has_requested_send_sms", true).apply()
            }

            if (permanentlyDenied) {
                val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                    data = Uri.fromParts("package", activity.packageName, null)
                }
                activity.runOnUiThread {
                    activity.startActivity(intent)
                }
                Log.i(TAG, "SEND_SMS permanently denied — opened App Settings")
            } else {
                activity.runOnUiThread {
                    ActivityCompat.requestPermissions(
                        activity,
                        arrayOf(Manifest.permission.SEND_SMS),
                        SETUP_PERMISSION_REQUEST_CODE,
                    )
                }
                Log.i(TAG, "Requested SEND_SMS permission for outbound auto-reply")
            }

            return mapOf(
                "requested" to true,
                "outboundSmsGranted" to isOutboundSmsGranted(activity),
                "openedSettings" to permanentlyDenied,
            )
        }
    }

    class WakeQueueWorker(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            Log.i("WakeQueueWorker", "WakeQueueWorker bridge function executed, sending intent to PHPQueueService")
            val intent = Intent(context, com.nativephp.mobile.bridge.PHPQueueService::class.java).apply {
                action = "WAKE_WORKER"
            }
            return try {
                context.startService(intent)
                mapOf("success" to true)
            } catch (e: Exception) {
                Log.e("WakeQueueWorker", "Failed to start PHPQueueService with WAKE_WORKER", e)
                mapOf("success" to false, "error" to (e.message ?: "Unknown error"))
            }
        }
    }

    class OpenBatterySettings(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (isBatteryUnrestricted(activity)) {
                return mapOf("alreadyUnrestricted" to true, "opened" to false)
            }

            activity.runOnUiThread {
                try {
                    val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                        data = Uri.parse("package:${activity.packageName}")
                    }
                    activity.startActivity(intent)
                } catch (e: Exception) {
                    Log.w(TAG, "ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS not available, opening battery settings", e)
                    activity.startActivity(Intent(Settings.ACTION_BATTERY_SAVER_SETTINGS))
                }
            }

            Log.i(TAG, "Opened battery optimization settings")
            return mapOf("alreadyUnrestricted" to false, "opened" to true)
        }
    }

    class OpenAccessibilitySettings(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val alreadyEnabled = isAccessibilityServiceEnabled(activity)

            if (!alreadyEnabled) {
                activity.runOnUiThread {
                    activity.startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS))
                }
                Log.i(TAG, "Opened accessibility settings")
            }

            return mapOf(
                "alreadyEnabled" to alreadyEnabled,
                "opened" to !alreadyEnabled,
            )
        }
    }

    class OpenAppInfo(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                data = Uri.fromParts("package", activity.packageName, null)
            }

            activity.runOnUiThread {
                activity.startActivity(intent)
            }

            Log.i(TAG, "Opened app details settings")

            return mapOf(
                "opened" to true,
                "packageName" to activity.packageName,
            )
        }
    }

    class OpenOverlaySettings(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val alreadyGranted = canDrawOverlays(activity)

            if (!alreadyGranted) {
                val intent = Intent(
                    Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                    Uri.parse("package:${activity.packageName}")
                )
                activity.runOnUiThread {
                    activity.startActivity(intent)
                }
                Log.i(TAG, "Opened overlay (draw over other apps) settings")
            }

            return mapOf(
                "alreadyGranted" to alreadyGranted,
                "opened" to !alreadyGranted,
            )
        }
    }

    class RequestSetupPermissions(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val force = parameters["force"] as? Boolean ?: false
            val openSpecialSettings = parameters["openSpecialSettings"] as? Boolean ?: false
            val prefs = activity.getSharedPreferences(PERMISSION_SETUP_PREFS, Context.MODE_PRIVATE)
            val alreadyRequested = prefs.getBoolean(PERMISSION_SETUP_REQUESTED, false)
            val missingPermissions = missingRuntimePermissions(activity)
            var requestedRuntimePermissions = false
            var openedOverlaySettings = false
            var openedAccessibilitySettings = false

            if ((force || !alreadyRequested) && missingPermissions.isNotEmpty()) {
                requestedRuntimePermissions = true
                
                var permanentlyDenied = false
                if (alreadyRequested) {
                    for (permission in missingPermissions) {
                        if (!ActivityCompat.shouldShowRequestPermissionRationale(activity, permission)) {
                            permanentlyDenied = true
                            break
                        }
                    }
                }

                prefs.edit().putBoolean(PERMISSION_SETUP_REQUESTED, true).apply()

                if (permanentlyDenied) {
                    val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
                    val uri = Uri.fromParts("package", activity.packageName, null)
                    intent.data = uri
                    activity.runOnUiThread {
                        activity.startActivity(intent)
                    }
                    Log.i(TAG, "Permissions permanently denied. Opened App Settings.")
                } else {
                    activity.runOnUiThread {
                        ActivityCompat.requestPermissions(
                            activity,
                            missingPermissions.toTypedArray(),
                            SETUP_PERMISSION_REQUEST_CODE
                        )
                    }
                    Log.i(TAG, "Requested setup runtime permissions: ${missingPermissions.joinToString()}")
                }
            } else if (!alreadyRequested) {
                prefs.edit().putBoolean(PERMISSION_SETUP_REQUESTED, true).apply()
            }

            if (openSpecialSettings && missingPermissions.isEmpty()) {
                openedOverlaySettings = openOverlaySettingsIfNeeded(activity)
                openedAccessibilitySettings = !openedOverlaySettings && openAccessibilitySettingsIfNeeded(activity)
            }

            return mapOf(
                "runtimePermissionsGranted" to missingRuntimePermissions(activity).isEmpty(),
                "requestedRuntimePermissions" to requestedRuntimePermissions,
                "missingRuntimePermissions" to missingPermissions,
                "overlayGranted" to canDrawOverlays(activity),
                "accessibilityEnabled" to isAccessibilityServiceEnabled(activity),
                "openedOverlaySettings" to openedOverlaySettings,
                "openedAccessibilitySettings" to openedAccessibilitySettings,
            )
        }
    }

    private fun executeUssd(
        context: Context,
        parameters: Map<String, Any>,
        defaultMode: String,
        defaultIsSambaza: Boolean,
        runAsync: Boolean = true
    ): Map<String, Any> {
        val idVal = parameters["id"]
        val id = when (idVal) {
            is Number -> idVal.toInt()
            is String -> idVal.toIntOrNull()
            else -> null
        }
        if (runAsync && id == null) {
            throw BridgeError.InvalidParameters("Missing 'id' parameter for async execution")
        }

        val code = parameters["code"] as? String
            ?: throw BridgeError.InvalidParameters("Missing 'code' parameter")

        val simSlotVal = parameters["simSlot"] ?: parameters["sim_slot"]
        val simSlot = when (simSlotVal) {
            is Number -> simSlotVal.toInt()
            is String -> simSlotVal.toIntOrNull() ?: 0
            else -> 0
        }

        val mode = (parameters["mode"] as? String)
            ?.takeIf { it == "express" || it == "advanced" }
            ?: defaultMode

        val isSambazaVal = parameters["isSambaza"] ?: parameters["is_sambaza"]
        val isSambaza = when (isSambazaVal) {
            is Boolean -> isSambazaVal
            is String -> isSambazaVal.trim().lowercase() == "true" || isSambazaVal.trim() == "1"
            is Number -> isSambazaVal.toInt() != 0
            else -> defaultIsSambaza
        }

        val timeoutVal = parameters["timeoutSeconds"] ?: parameters["timeout_seconds"] ?: parameters["timeout"]
        val timeoutSeconds = when (timeoutVal) {
            is Number -> timeoutVal.toInt()
            is String -> timeoutVal.toIntOrNull() ?: 30
            else -> 30
        }.takeIf { it > 0 } ?: 30

        if (runAsync) {
            // Dispatch USSD execution to UssdExecutionService, which runs in the MAIN process.
            // This is critical: UssdAccessibilityService (and its responseChannel) live in the
            // main process. Running UssdExecutor in the :queue process (this process when called
            // from PHPQueueService) means responseChannel is always empty — causing 90-second
            // timeouts on every async USSD job. The service intent crosses process boundaries
            // safely; Android starts the service in the process that declared it (main process).
            Log.i(TAG, "USSD async: dispatching to UssdExecutionService (main process) id=${id} mode=$mode simSlot=$simSlot")
            UssdExecutionService.start(
                context = context,
                id = id!!,
                code = code,
                mode = mode,
                simSlot = simSlot,
                isSambaza = isSambaza,
                timeoutSeconds = timeoutSeconds,
            )
            return mapOf(
                "success" to true,
                "message" to "Dispatched to hardware"
            )
        } else {
            // Synchronous execution (old blocking behavior for frontend interactive flows like Sambaza)
            val executor = UssdExecutor(context)
            val lockAcquired = runBlocking {
                withTimeoutOrNull(USSD_LOCK_WAIT_MS) {
                    ussdMutex.lock()
                    true
                } ?: false
            }

            if (!lockAcquired) {
                Log.w(TAG, "USSD bridge busy (sync): mode=$mode simSlot=$simSlot")
                return mapOf(
                    "success" to false,
                    "message" to "Another USSD session is already in progress. Try again shortly."
                )
            }

            val result = try {
                Log.i(TAG, "USSD bridge acquired modem guard (sync): mode=$mode simSlot=$simSlot timeoutSeconds=$timeoutSeconds")
                runBlocking {
                    executor.execute(
                        code = code,
                        mode = mode,
                        simSlot = simSlot,
                        isSambaza = isSambaza,
                        timeoutSeconds = timeoutSeconds,
                    )
                }
            } finally {
                ussdMutex.unlock()
                Log.i(TAG, "USSD bridge released modem guard (sync): mode=$mode simSlot=$simSlot")
            }

            return mapOf(
                "success" to result.success,
                "message" to result.message
            )
        }
    }

    internal fun postCallback(context: Context, id: Int, success: Boolean, message: String) {
        val status = if (success) "completed" else "failed"
        val encodedMessage = android.util.Base64.encodeToString(
            message.toByteArray(Charsets.UTF_8),
            android.util.Base64.URL_SAFE or android.util.Base64.NO_WRAP or android.util.Base64.NO_PADDING
        )
        val token = UssdCallbackOutbox.enqueue(context, id, success, message)
        val command = "bingwa:complete-transaction --transaction-id=$id --result=$status --message-base64=$encodedMessage --callback-token=$token"

        var executedInstantly = false

        val phpBridge = com.nativephp.mobile.bridge.PHPBridge(context.applicationContext)

        // ── Tier 1: Persistent runtime ─────────────────────────────────────────────────
        // Fastest path (~5–30 ms). Reuses already-warm PHP kernel.
        // Thread-safe: runPersistentArtisan() dispatches through phpExecutor (single-thread executor),
        // so no TSRM collisions.
        if (phpBridge.isPersistentMode()) {
            try {
                val output = phpBridge.runPersistentArtisan(command)
                Log.i(TAG, "USSD callback via persistent runtime (~5ms): ${output.take(200)}")
                executedInstantly = true
                UssdCallbackOutbox.markDelivered(context, id)
                // Wake the queue worker immediately so the next job is dispatched without delay.
                wakeQueueWorker(context)
            } catch (e: Exception) {
                Log.w(TAG, "Persistent runtime execution failed for USSD callback: ${e.message}")
            }
        }

        // ── Tier 2: Ephemeral runtime ──────────────────────────────────────────────────
        // Medium path (~200 ms cold boot). Works even when app is fully backgrounded.
        // Uses a dedicated TSRM context (separate from persistent runtime) so it never
        // blocks or interferes with WebView/Livewire requests.
        // Only run this if persistent mode is NOT active to avoid Zend engine thread conflict.
        if (!executedInstantly && !phpBridge.isPersistentMode()) {
            Thread {
                synchronized(com.nativephp.mobile.bridge.PHPBridge.phpLock) {
                    try {
                        if (!com.nativephp.mobile.bridge.BridgeFunctionRegistry.shared.exists("ExecuteUssd")) {
                            com.nativephp.mobile.bridge.plugins.registerContextOnlyBridgeFunctions(context.applicationContext)
                        }

                        val environment = com.nativephp.mobile.bridge.LaravelEnvironment(context.applicationContext)
                        environment.initializeForBackground()

                        val booted = phpBridge.nativeEphemeralBoot(
                            "${phpBridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
                        )

                        if (booted == 0) {
                            try {
                                val output = phpBridge.nativeEphemeralArtisan(command)
                                Log.i(TAG, "USSD callback via ephemeral runtime (~200ms): ${output.take(200)}")
                                UssdCallbackOutbox.markDelivered(context, id)
                                // Wake the queue worker immediately so the next job is dispatched without delay.
                                wakeQueueWorker(context)
                            } finally {
                                phpBridge.nativeEphemeralShutdown()
                            }
                        }
                    } catch (e: Exception) {
                        Log.e(TAG, "Ephemeral PHP bridge callback failed", e)
                    }
                }
            }.start()
        }

        // ── Tier 3: Background Fallback ────────────────────────────────────────────────────────
        // Guaranteed path. Persists to SQLite, survives process death & device reboot.
        // Retries with exponential backoff until it succeeds.
        if (!executedInstantly) {
            Log.w(TAG, "Both instant tiers failed — enqueuing background fallback for transaction #$id")
            UssdCallbackWorker.enqueueFallback(context, id, success, message)
        }
    }

    /**
     * Wake the PHPQueueWorker immediately so it picks up the next queued job without
     * waiting for the idle sleep to expire. Sends a WAKE_WORKER intent directly to
     * PHPQueueService in the :queue process — no PHP bridge required.
     */
    private fun wakeQueueWorker(context: Context) {
        try {
            val intent = android.content.Intent(context, com.nativephp.mobile.bridge.PHPQueueService::class.java).apply {
                action = "WAKE_WORKER"
            }
            context.startService(intent)
            Log.i(TAG, "WAKE_WORKER intent sent to PHPQueueService")
        } catch (e: Exception) {
            Log.w(TAG, "Failed to send WAKE_WORKER intent: ${e.message}")
        }
    }

    private fun replayBufferedCallbacks(context: Context) {
        Thread {
            try {
                val delivered = UssdCallbackOutbox.replay(context) { callback ->
                    postCallbackDelivery(context, callback.id, callback.success, callback.message)
                }

                if (delivered > 0) {
                    Log.i(TAG, "Replayed $delivered buffered USSD callbacks")
                }
            } catch (e: Exception) {
                Log.w(TAG, "Error in replayBufferedCallbacks thread", e)
            }
        }.start()
    }

    private fun postCallbackDelivery(context: Context, id: Int, success: Boolean, message: String): Boolean {
        val status = if (success) "completed" else "failed"
        val encodedMessage = android.util.Base64.encodeToString(
            message.toByteArray(Charsets.UTF_8),
            android.util.Base64.URL_SAFE or android.util.Base64.NO_WRAP or android.util.Base64.NO_PADDING
        )
        val token = UssdCallbackOutbox.enqueue(context, id, success, message)
        val command = "bingwa:complete-transaction --transaction-id=$id --result=$status --message-base64=$encodedMessage --callback-token=$token"

        try {
            val phpBridge = com.nativephp.mobile.bridge.PHPBridge(context.applicationContext)

            if (phpBridge.isPersistentMode()) {
                phpBridge.runPersistentArtisan(command)
                UssdCallbackOutbox.markDelivered(context, id)
                return true
            }

            synchronized(com.nativephp.mobile.bridge.PHPBridge.phpLock) {
                if (!com.nativephp.mobile.bridge.BridgeFunctionRegistry.shared.exists("ExecuteUssd")) {
                    com.nativephp.mobile.bridge.plugins.registerContextOnlyBridgeFunctions(context.applicationContext)
                }

                val environment = com.nativephp.mobile.bridge.LaravelEnvironment(context.applicationContext)
                environment.initializeForBackground()

                val booted = phpBridge.nativeEphemeralBoot(
                    "${phpBridge.getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
                )

                if (booted == 0) {
                    try {
                        phpBridge.nativeEphemeralArtisan(command)
                        UssdCallbackOutbox.markDelivered(context, id)
                        return true
                    } finally {
                        phpBridge.nativeEphemeralShutdown()
                    }
                }
            }
        } catch (e: Exception) {
            Log.w(TAG, "Buffered USSD callback replay failed for transaction #$id", e)
        }

        return false
    }





    private fun resolveSubscriptionId(context: Context, simSlot: Int): Int {
        val subscriptionManager = context.getSystemService(Context.TELEPHONY_SUBSCRIPTION_SERVICE) as? SubscriptionManager
            ?: return SubscriptionManager.INVALID_SUBSCRIPTION_ID

        val subscriptionInfo = subscriptionManager.getActiveSubscriptionInfoForSimSlotIndex(simSlot)
            ?: return SubscriptionManager.INVALID_SUBSCRIPTION_ID

        return subscriptionInfo.subscriptionId
    }

    private fun missingRuntimePermissions(context: Context): List<String> {
        return requiredRuntimePermissions()
            .filter { permission ->
                ContextCompat.checkSelfPermission(context, permission) != PackageManager.PERMISSION_GRANTED
            }
    }

    private fun requiredRuntimePermissions(): List<String> {
        return buildList {
            add(Manifest.permission.CALL_PHONE)
            add(Manifest.permission.RECEIVE_SMS)
            add(Manifest.permission.READ_PHONE_STATE)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                add(Manifest.permission.POST_NOTIFICATIONS)
            }
        }
    }

    private fun isPhoneGranted(context: Context): Boolean {
        return ContextCompat.checkSelfPermission(context, Manifest.permission.CALL_PHONE) == PackageManager.PERMISSION_GRANTED &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED
    }

    private fun isSmsGranted(context: Context): Boolean {
        return ContextCompat.checkSelfPermission(context, Manifest.permission.RECEIVE_SMS) == PackageManager.PERMISSION_GRANTED &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED
    }

    private fun isIncomingSmsGranted(context: Context): Boolean {
        return ContextCompat.checkSelfPermission(context, Manifest.permission.RECEIVE_SMS) == PackageManager.PERMISSION_GRANTED
    }

    private fun isOutboundSmsGranted(context: Context): Boolean {
        return ContextCompat.checkSelfPermission(context, Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED
    }

    private fun isNotificationsGranted(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) {
            return true // Auto-granted on Android < 13
        }
        return ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED
    }

    private fun isBatteryUnrestricted(context: Context): Boolean {
        val pm = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        return pm.isIgnoringBatteryOptimizations(context.packageName)
    }

    private fun canDrawOverlays(context: Context): Boolean {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.M || Settings.canDrawOverlays(context)
    }

    private fun openOverlaySettingsIfNeeded(activity: FragmentActivity): Boolean {
        if (canDrawOverlays(activity)) {
            return false
        }

        val intent = Intent(
            Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
            Uri.parse("package:${activity.packageName}")
        )

        activity.runOnUiThread {
            activity.startActivity(intent)
        }
        Log.i(TAG, "Opened overlay permission settings")

        return true
    }

    private fun openAccessibilitySettingsIfNeeded(activity: FragmentActivity): Boolean {
        if (isAccessibilityServiceEnabled(activity)) {
            return false
        }

        activity.runOnUiThread {
            activity.startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS))
        }
        Log.i(TAG, "Opened accessibility service settings")

        return true
    }

    private fun isAccessibilityServiceEnabled(context: Context): Boolean {
        val expectedServiceName = "${context.packageName}/${UssdAccessibilityService::class.java.name}"
        
        // 1. Check if the setting is turned on in the settings database
        val enabledServices = Settings.Secure.getString(
            context.contentResolver,
            Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES
        ) ?: return false

        val settingsEnabled = enabledServices.split(':').any { enabledService ->
            enabledService.equals(expectedServiceName, ignoreCase = true)
        }

        if (!settingsEnabled) {
            return false
        }

        // 2. Check if the service is actively running/bound by the OS (detects suspended state after reinstall/updates)
        val am = context.getSystemService(Context.ACCESSIBILITY_SERVICE) as? android.view.accessibility.AccessibilityManager
        val runningServices = am?.getEnabledAccessibilityServiceList(android.view.accessibility.AccessibilityEvent.TYPES_ALL_MASK)
        return runningServices?.any { service ->
            service.id.equals(expectedServiceName, ignoreCase = true)
        } ?: false
    }
}
