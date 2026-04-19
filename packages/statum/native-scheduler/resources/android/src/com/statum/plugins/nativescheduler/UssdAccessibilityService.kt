package com.statum.plugins.nativescheduler

import android.accessibilityservice.AccessibilityService
import android.accessibilityservice.AccessibilityServiceInfo
import android.graphics.PixelFormat
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.Gravity
import android.view.WindowManager
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo
import android.widget.TextView
import kotlinx.coroutines.channels.Channel

/**
 * Accessibility service that intercepts the system USSD dialog for "advanced" mode offers.
 *
 * **Architecture**:
 * - [UssdExecutor] dials the code via ACTION_CALL → system dialer opens a USSD session.
 * - This service detects the USSD dialog via TYPE_WINDOW_STATE_CHANGED events.
 * - It sends the dialog text to [responseChannel] → UssdExecutor reads it.
 * - UssdExecutor calls [injectInput] directly on [instance] → service clicks OK/Send.
 * - A full-screen overlay hides the raw USSD dialog from the user.
 *
 * **Required XML config**: res/xml/accessibility_service_config.xml must exist (see companion).
 * **Manifest**: service entry must declare BIND_ACCESSIBILITY_SERVICE permission and
 *               reference the XML config via <meta-data android:name="android.accessibilityservice">.
 *
 * **User action**: User must enable "Bingwa USSD Automation" once in Android Settings →
 *                  Accessibility → Installed services.
 */
class UssdAccessibilityService : AccessibilityService() {

    companion object {
        private const val TAG = "UssdAccessibility"

        /**
         * Live singleton set on connect, cleared on destroy.
         * [UssdExecutor] uses this to call [injectInput] and [dismiss] directly.
         */
        @Volatile
        var instance: UssdAccessibilityService? = null

        /**
         * One-way channel: Service → UssdExecutor.
         * Sends the USSD dialog text when detected. Unlimited capacity prevents drops.
         */
        val responseChannel = Channel<String>(Channel.UNLIMITED)

        /** Dialer packages monitored across OEMs (AOSP, Samsung, Google, generic). */
        private val DIALER_PACKAGES = setOf(
            "com.android.phone",
            "com.samsung.android.dialer",
            "com.google.android.dialer",
            "com.android.dialer",
        )
    }

    private var overlayView: TextView? = null
    private val handler = Handler(Looper.getMainLooper())
    private val windowManager by lazy { getSystemService(WINDOW_SERVICE) as WindowManager }

    /**
     * Track whether we have already sent the current dialog's text to [responseChannel].
     * This prevents duplicate sends when the dialog fires multiple events for the same state.
     */
    @Volatile
    private var lastSentDialogText: String? = null

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    override fun onServiceConnected() {
        instance = this
        // Configure programmatically so we can cover all dialer package name variants
        serviceInfo = AccessibilityServiceInfo().apply {
            eventTypes = AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED or
                    AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED
            feedbackType = AccessibilityServiceInfo.FEEDBACK_GENERIC
            flags = AccessibilityServiceInfo.FLAG_RETRIEVE_INTERACTIVE_WINDOWS
            notificationTimeout = 100
            packageNames = DIALER_PACKAGES.toTypedArray()
        }
        Log.i(TAG, "Accessibility service connected")
    }

    override fun onInterrupt() {
        handler.post { hideOverlay() }
        Log.i(TAG, "Accessibility service interrupted")
    }

    override fun onDestroy() {
        handler.post { hideOverlay() }
        instance = null
        super.onDestroy()
    }

    // -------------------------------------------------------------------------
    // Event handling
    // -------------------------------------------------------------------------

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event == null) return
        if (event.packageName?.toString() !in DIALER_PACKAGES) return
        if (event.eventType != AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED &&
            event.eventType != AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED
        ) return

        val root = rootInActiveWindow ?: return

        // A USSD dialog is identifiable by the presence of a Send/OK button.
        // We don't require an EditText — confirmation dialogs often have none.
        val sendButton = findSendButton(root)
        if (sendButton == null) {
            root.recycle()
            return
        }

        // Extract the dialog message text
        val textNodes = findNodesByClass(root, "android.widget.TextView")
        val dialogText = textNodes
            .mapNotNull { node -> node.text?.toString()?.trim()?.takeIf { it.isNotBlank() } }
            .joinToString("\n")
            .trim()

        root.recycle()

        if (dialogText.isBlank()) return

        // Deduplicate: only send once per unique dialog text to avoid flooding the channel
        if (dialogText == lastSentDialogText) return
        lastSentDialogText = dialogText

        Log.d(TAG, "USSD dialog detected: $dialogText")

        showOverlay()
        responseChannel.trySend(dialogText)
    }

    // -------------------------------------------------------------------------
    // Public API — called directly by UssdExecutor
    // -------------------------------------------------------------------------

    /**
     * Inject [input] text (may be empty for confirmation-only prompts) and click Send/OK.
     * Runs on the main thread via [handler] — safe to call from any coroutine.
     */
    fun injectInput(input: String) {
        handler.post {
            val root = rootInActiveWindow ?: run {
                Log.w(TAG, "injectInput: no active window")
                return@post
            }

            // If the dialog has an input field, populate it
            if (input.isNotEmpty()) {
                findNodesByClass(root, "android.widget.EditText").firstOrNull()?.let { editNode ->
                    val args = Bundle()
                    args.putCharSequence(
                        AccessibilityNodeInfo.ACTION_ARGUMENT_SET_TEXT_CHARSEQUENCE,
                        input
                    )
                    editNode.performAction(AccessibilityNodeInfo.ACTION_SET_TEXT, args)
                }
            }

            // Click the Send/OK button to submit
            findSendButton(root)?.performAction(AccessibilityNodeInfo.ACTION_CLICK)
            root.recycle()

            Log.d(TAG, "injectInput: submitted '$input'")
        }
    }

    /**
     * Remove the processing overlay. Call after the USSD session is complete.
     */
    fun dismiss() {
        lastSentDialogText = null
        handler.post { hideOverlay() }
    }

    // -------------------------------------------------------------------------
    // Node traversal helpers
    // -------------------------------------------------------------------------

    /**
     * BFS traversal to find all nodes matching a given [className].
     * Avoids hardcoded resource IDs which differ across OEMs and Android versions.
     */
    private fun findNodesByClass(
        root: AccessibilityNodeInfo,
        className: String,
    ): List<AccessibilityNodeInfo> {
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

    /**
     * Finds the Send/OK button in a USSD dialog.
     *
     * Strategy: Match known localized labels first, then fall back to the last button in the
     * tree (which is typically the "confirm" button by dialog layout convention).
     */
    private fun findSendButton(root: AccessibilityNodeInfo): AccessibilityNodeInfo? {
        val buttons = findNodesByClass(root, "android.widget.Button")
        val confirmLabels = setOf("send", "ok", "okay", "reply", "tuma", "confirm", "yes")
        return buttons.firstOrNull { node ->
            node.text?.toString()?.lowercase()?.trim() in confirmLabels
        } ?: buttons.lastOrNull()
    }

    // -------------------------------------------------------------------------
    // Processing overlay
    // -------------------------------------------------------------------------

    private fun showOverlay() {
        if (overlayView != null) return
        handler.post {
            if (overlayView != null) return@post // double-check after switch to main thread
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

                overlayView = TextView(applicationContext).apply {
                    text = "⚡ Processing…"
                    textSize = 20f
                    gravity = Gravity.CENTER
                    setPadding(64, 64, 64, 64)
                    setBackgroundColor(0xEE111827.toInt()) // dark navy, 93% opacity
                    setTextColor(0xFFFFFFFF.toInt())
                }

                windowManager.addView(overlayView, params)
                Log.d(TAG, "Processing overlay shown")
            } catch (e: Exception) {
                Log.e(TAG, "showOverlay failed", e)
            }
        }
    }

    private fun hideOverlay() {
        overlayView?.let {
            try {
                windowManager.removeView(it)
                Log.d(TAG, "Processing overlay hidden")
            } catch (e: Exception) {
                Log.e(TAG, "hideOverlay failed", e)
            } finally {
                overlayView = null
            }
        }
    }
}
