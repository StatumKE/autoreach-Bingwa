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
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import kotlinx.coroutines.runBlocking

object BingwaFunctions {
    private const val SETUP_PERMISSION_REQUEST_CODE = 5107
    private const val PERMISSION_SETUP_PREFS = "bingwa_permission_setup"
    private const val PERMISSION_SETUP_REQUESTED = "requested"
    private const val TAG = "BingwaPermissions"

    class TriggerSambaza(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return executeUssd(context, parameters, defaultMode = "advanced", defaultIsSambaza = true)
        }
    }

    class ExecuteUssd(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return executeUssd(context, parameters, defaultMode = "express", defaultIsSambaza = false)
        }
    }

    class CheckSetupStatus(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return mapOf(
                "phoneGranted" to isPhoneGranted(context),
                "contactsGranted" to isContactsGranted(context),
                "notificationsGranted" to isNotificationsGranted(context),
                "batteryUnrestricted" to isBatteryUnrestricted(context),
                "accessibilityEnabled" to isAccessibilityServiceEnabled(context),
                "overlayGranted" to canDrawOverlays(context),
            )
        }
    }

    class RequestRuntimePermissions(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val missing = buildList {
                if (!isPhoneGranted(activity)) {
                    add(Manifest.permission.CALL_PHONE)
                    add(Manifest.permission.READ_PHONE_STATE)
                }
                if (!isContactsGranted(activity)) {
                    add(Manifest.permission.READ_CONTACTS)
                }
                if (!isNotificationsGranted(activity)) {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                        add(Manifest.permission.POST_NOTIFICATIONS)
                    }
                }
            }

            if (missing.isEmpty()) {
                return mapOf(
                    "requested" to false,
                    "phoneGranted" to true,
                    "contactsGranted" to true,
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
                "contactsGranted" to isContactsGranted(activity),
                "notificationsGranted" to isNotificationsGranted(activity),
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
        defaultIsSambaza: Boolean
    ): Map<String, Any> {
        val code = parameters["code"] as? String
            ?: throw BridgeError.InvalidParameters("Missing 'code' parameter")

        val simSlot = (parameters["simSlot"] as? Number)?.toInt() ?: 0
        val mode = (parameters["mode"] as? String)?.takeIf { it == "express" || it == "advanced" } ?: defaultMode
        val isSambaza = (parameters["isSambaza"] as? Boolean) ?: defaultIsSambaza
        val executor = UssdExecutor(context)

        val result = runBlocking {
            executor.execute(code, mode, simSlot, isSambaza = isSambaza)
        }

        return mapOf(
            "success" to result.success,
            "message" to result.message
        )
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
            add(Manifest.permission.READ_CONTACTS)
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

    private fun isContactsGranted(context: Context): Boolean {
        return ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) == PackageManager.PERMISSION_GRANTED
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
