package com.statum.plugins.nativescheduler

import android.accessibilityservice.AccessibilityService
import android.accessibilityservice.AccessibilityServiceInfo
import android.graphics.PixelFormat
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.Gravity
import android.view.LayoutInflater
import android.view.View
import android.view.WindowManager
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo
import android.widget.TextView
import kotlinx.coroutines.channels.Channel

/**
 * Accessibility service that intercepts system USSD dialogs from the Android dialer and
 * automates multi-step ("advanced") USSD sessions invisibly.
 *
 * The service communicates with [UssdExecutor] via a shared [Channel]. When a USSD dialog
 * appears, it sends the response text back and waits for the next input instruction.
 *
 * An overlay window displays "Processing…" to the user, hiding the raw USSD dialog.
 */
class UssdAccessibilityService : AccessibilityService() {

    companion object {
        private const val TAG = "UssdAccessibility"

        /** Live instance — set when the accessibility service connects. */
        @Volatile
        var instance: UssdAccessibilityService? = null

        /**
         * Shared channel: UssdExecutor sends input steps, this service receives them
         * and injects them into the live USSD dialog.
         */
        val inputChannel = Channel<String>(Channel.UNLIMITED)

        /**
         * Shared channel: this service sends the USSD dialog response text back to UssdExecutor.
         */
        val responseChannel = Channel<String>(Channel.UNLIMITED)

        // Known dialer packages across OEMs
        private val DIALER_PACKAGES = setOf(
            "com.android.phone",
            "com.samsung.android.dialer",
            "com.google.android.dialer",
            "com.android.dialer",
        )
    }

    private var overlayView: View? = null
    private val windowManager by lazy { getSystemService(WINDOW_SERVICE) as WindowManager }
    private val handler = Handler(Looper.getMainLooper())

    override fun onServiceConnected() {
        instance = this
        val info = AccessibilityServiceInfo().apply {
            eventTypes = AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED or
                    AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED
            feedbackType = AccessibilityServiceInfo.FEEDBACK_GENERIC
            flags = AccessibilityServiceInfo.FLAG_RETRIEVE_INTERACTIVE_WINDOWS
            notificationTimeout = 100
            packageNames = DIALER_PACKAGES.toTypedArray()
        }
        serviceInfo = info
        Log.i(TAG, "Accessibility service connected")
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event == null) return
        if (event.eventType != AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED &&
            event.eventType != AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED
        ) return
        if (event.packageName?.toString() !in DIALER_PACKAGES) return

        val root = rootInActiveWindow ?: return

        // Check for a USSD dialog with an EditText (input field) or just a message
        val editNodes = findNodesByClass(root, "android.widget.EditText")
        val textNodes = findNodesByClass(root, "android.widget.TextView")
        val sendButton = findSendButton(root)

        if (editNodes.isEmpty() && sendButton == null) {
            // This isn't a USSD dialog we can interact with
            return
        }

        // Read the dialog's message text (everything except the input field)
        val responseText = textNodes
            .filter { it.text != null && it.text.isNotBlank() }
            .joinToString("\n") { it.text.toString() }
            .trim()

        if (responseText.isBlank()) return

        Log.d(TAG, "USSD dialog detected: $responseText")

        // Show the processing overlay
        showOverlay()

        // Send the response text to UssdExecutor
        responseChannel.trySend(responseText)
    }

    /**
     * Called by [UssdExecutor] to inject text into the open USSD dialog and press Send.
     * Must be called from the handler thread.
     */
    fun injectInput(input: String) {
        handler.post {
            val root = rootInActiveWindow ?: run {
                Log.w(TAG, "No active window to inject input into")
                return@post
            }

            val editNode = findNodesByClass(root, "android.widget.EditText").firstOrNull()
            editNode?.let {
                val args = android.os.Bundle()
                args.putCharSequence(
                    AccessibilityNodeInfo.ACTION_ARGUMENT_SET_TEXT_CHARSEQUENCE,
                    input
                )
                it.performAction(AccessibilityNodeInfo.ACTION_SET_TEXT, args)
            }

            // Click the send/ok button
            findSendButton(root)?.performAction(AccessibilityNodeInfo.ACTION_CLICK)
        }
    }

    /**
     * Dismiss the overlay and cancel any session tracking.
     */
    fun dismiss() {
        handler.post { hideOverlay() }
    }

    // -------------------------------------------------------------------------
    // Node helpers
    // -------------------------------------------------------------------------

    private fun findNodesByClass(root: AccessibilityNodeInfo, className: String): List<AccessibilityNodeInfo> {
        val result = mutableListOf<AccessibilityNodeInfo>()
        val queue = ArrayDeque<AccessibilityNodeInfo>()
        queue.add(root)
        while (queue.isNotEmpty()) {
            val node = queue.removeFirst()
            if (node.className?.toString() == className) result.add(node)
            for (i in 0 until node.childCount) {
                node.getChild(i)?.let { queue.add(it) }
            }
        }
        return result
    }

    private fun findSendButton(root: AccessibilityNodeInfo): AccessibilityNodeInfo? {
        val buttons = findNodesByClass(root, "android.widget.Button")
        // Look for Send, OK, or Reply — localized keywords
        val sendLabels = setOf("send", "ok", "reply", "tuma", "okay")
        return buttons.firstOrNull { node ->
            node.text?.toString()?.lowercase() in sendLabels
        } ?: buttons.lastOrNull() // fallback: last button in dialog
    }

    // -------------------------------------------------------------------------
    // Processing overlay
    // -------------------------------------------------------------------------

    private fun showOverlay() {
        if (overlayView != null) return
        handler.post {
            try {
                val params = WindowManager.LayoutParams(
                    WindowManager.LayoutParams.MATCH_PARENT,
                    WindowManager.LayoutParams.MATCH_PARENT,
                    WindowManager.LayoutParams.TYPE_ACCESSIBILITY_OVERLAY,
                    WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or
                            WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE or
                            WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
                    PixelFormat.TRANSLUCENT
                ).apply {
                    gravity = Gravity.CENTER
                }

                val view = TextView(applicationContext).apply {
                    text = "⚡ Processing…"
                    textSize = 18f
                    setPadding(48, 48, 48, 48)
                    setBackgroundColor(0xDD000000.toInt())
                    setTextColor(0xFFFFFFFF.toInt())
                    gravity = Gravity.CENTER
                }

                overlayView = view
                windowManager.addView(view, params)
            } catch (e: Exception) {
                Log.e(TAG, "Failed to show overlay", e)
            }
        }
    }

    private fun hideOverlay() {
        overlayView?.let {
            try {
                windowManager.removeView(it)
            } catch (e: Exception) {
                Log.e(TAG, "Failed to remove overlay", e)
            }
            overlayView = null
        }
    }

    override fun onInterrupt() {
        hideOverlay()
        Log.i(TAG, "Accessibility service interrupted")
    }

    override fun onDestroy() {
        hideOverlay()
        instance = null
        super.onDestroy()
    }
}
