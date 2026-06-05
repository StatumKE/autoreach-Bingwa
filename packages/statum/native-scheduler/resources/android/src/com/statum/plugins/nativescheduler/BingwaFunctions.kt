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
import kotlinx.coroutines.launch
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.GlobalScope
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody



object BingwaFunctions {
    private const val SETUP_PERMISSION_REQUEST_CODE = 5107
    private const val PERMISSION_SETUP_PREFS = "bingwa_permission_setup"
    private const val PERMISSION_SETUP_REQUESTED = "requested"
    private const val TAG = "BingwaPermissions"
    private const val USSD_LOCK_WAIT_MS = 2_000L
    private val ussdMutex = Mutex()

    class TriggerSambaza(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return executeUssd(context, parameters, defaultMode = "express", defaultIsSambaza = true, runAsync = false)
        }
    }

    class ExecuteUssd(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return executeUssd(context, parameters, defaultMode = "express", defaultIsSambaza = false, runAsync = true)
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

            val permanentlyDenied = !ActivityCompat.shouldShowRequestPermissionRationale(
                activity,
                Manifest.permission.SEND_SMS,
            )

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
        val id = (parameters["id"] as? Number)?.toInt()
        if (runAsync && id == null) {
            throw BridgeError.InvalidParameters("Missing 'id' parameter for async execution")
        }

        val code = parameters["code"] as? String
            ?: throw BridgeError.InvalidParameters("Missing 'code' parameter")

        val simSlot = (parameters["simSlot"] as? Number)?.toInt() ?: 0
        val mode = (parameters["mode"] as? String)
            ?.takeIf { it == "express" || it == "advanced" }
            ?: defaultMode
        val isSambaza = (parameters["isSambaza"] as? Boolean) ?: defaultIsSambaza
        val timeoutSeconds = ((parameters["timeoutSeconds"] as? Number)?.toInt() ?: 30)
            .takeIf { it > 0 }
            ?: 30

        if (runAsync) {
            // Asynchronously run USSD on background thread pool
            kotlinx.coroutines.GlobalScope.launch(kotlinx.coroutines.Dispatchers.Default) {
                val executor = UssdExecutor(context)
                val lockAcquired = withTimeoutOrNull(USSD_LOCK_WAIT_MS) {
                    ussdMutex.lock()
                    true
                } ?: false

                if (!lockAcquired) {
                    Log.w(TAG, "USSD bridge busy: mode=$mode simSlot=$simSlot timeoutSeconds=$timeoutSeconds")
                    postCallback(context, id!!, false, "Another USSD session is already in progress. Try again shortly.")
                    return@launch
                }

                try {
                    Log.i(TAG, "USSD bridge acquired modem guard: mode=$mode simSlot=$simSlot timeoutSeconds=$timeoutSeconds")
                    val result = executor.execute(
                        code = code,
                        mode = mode,
                        simSlot = simSlot,
                        isSambaza = isSambaza,
                        timeoutSeconds = timeoutSeconds,
                    )
                    postCallback(context, id!!, result.success, result.message)
                } catch (e: Exception) {
                    Log.e(TAG, "Async USSD execution failed", e)
                    postCallback(context, id!!, false, e.message ?: "Async USSD execution failed")
                } finally {
                    ussdMutex.unlock()
                    Log.i(TAG, "USSD bridge released modem guard: mode=$mode simSlot=$simSlot")
                }
            }

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

    private fun postCallback(context: Context, id: Int, success: Boolean, message: String) {
        val inputData = androidx.work.Data.Builder()
            .putInt(UssdCallbackWorker.KEY_ID, id)
            .putBoolean(UssdCallbackWorker.KEY_SUCCESS, success)
            .putString(UssdCallbackWorker.KEY_MESSAGE, message)
            .build()

        val workRequest = androidx.work.OneTimeWorkRequestBuilder<UssdCallbackWorker>()
            .setInputData(inputData)
            .build()

        androidx.work.WorkManager.getInstance(context).enqueue(workRequest)
        Log.i(TAG, "Enqueued USSD callback for transaction #$id")
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
        val enabledServices = Settings.Secure.getString(
            context.contentResolver,
            Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES
        ) ?: return false

        return enabledServices.split(':').any { enabledService ->
            enabledService.equals(expectedServiceName, ignoreCase = true)
        }
    }
}
