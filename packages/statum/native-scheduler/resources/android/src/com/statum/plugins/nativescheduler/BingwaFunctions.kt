package com.statum.plugins.nativescheduler

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
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
                prefs.edit().putBoolean(PERMISSION_SETUP_REQUESTED, true).apply()
                activity.runOnUiThread {
                    ActivityCompat.requestPermissions(
                        activity,
                        missingPermissions.toTypedArray(),
                        SETUP_PERMISSION_REQUEST_CODE
                    )
                }
                Log.i(TAG, "Requested setup runtime permissions: ${missingPermissions.joinToString()}")
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
