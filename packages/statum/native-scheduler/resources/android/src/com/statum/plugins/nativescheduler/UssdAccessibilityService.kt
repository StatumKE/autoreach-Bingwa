package com.statum.plugins.nativescheduler

import android.accessibilityservice.AccessibilityService
import android.accessibilityservice.AccessibilityServiceInfo
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo
import kotlinx.coroutines.channels.Channel

/**
 * Accessibility service that intercepts the system USSD dialog for "advanced" mode offers.
 *
 * **Architecture**:
 * - [UssdExecutor] dials the code via ACTION_CALL → system dialer opens a USSD session.
 * - This service detects the USSD dialog via TYPE_WINDOW_STATE_CHANGED events.
 * - It sends the dialog text to [responseChannel] → UssdExecutor reads it.
 * - UssdExecutor calls [injectInput] directly on [instance] → service clicks OK/Send.
 *
 * **Required XML config**: res/xml/ussd_accessibility_service_config.xml must exist.
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

        @Volatile
        var activeSimSlot: Int? = null

        /**
         * One-way channel: Service → UssdExecutor.
         * CONFLATED capacity: only the latest dialog text is buffered. Since the consumer
         * (UssdExecutor) only ever needs the current dialog state, intermediate duplicate
         * events from the same dialog are automatically discarded. This prevents unbounded
         * memory accumulation under high accessibility event rates.
         */
        val responseChannel = Channel<String>(Channel.CONFLATED)

        private val CONFIRM_LABELS = setOf("send", "ok", "okay", "reply", "tuma", "confirm", "yes", "close", "dismiss", "cancel")
    }

    private val handler = Handler(Looper.getMainLooper())

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
            packageNames = null
        }
        Log.i(TAG, "Accessibility service connected")
    }

    override fun onInterrupt() {
        Log.i(TAG, "Accessibility service interrupted")
    }

    override fun onDestroy() {
        instance = null
        super.onDestroy()
    }

    // -------------------------------------------------------------------------
    // Event handling
    // -------------------------------------------------------------------------

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event == null) return
        if (event.eventType != AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED &&
            event.eventType != AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED
        ) return
        val eventPackage = event.packageName?.toString() ?: return
        if (eventPackage == packageName || !isTelephonyWindow(eventPackage, event.className?.toString())) return

        var root = event.source ?: rootInActiveWindow ?: return
        while (root.parent != null) {
            root = root.parent
        }

        // EXPLICIT SAFETY: Never capture text from our own app
        if (root.packageName?.toString() == packageName) {
            return
        }

        // A USSD dialog is identifiable by the presence of a Send/OK button.
        if (findSendButton(root) == null) {
            // Check if it's a SIM chooser dialog before ignoring the window
            findSimButton(root, activeSimSlot)?.let { simBtn ->
                Log.d(TAG, "SIM chooser detected, clicking SIM slot $activeSimSlot")
                simBtn.performAction(AccessibilityNodeInfo.ACTION_CLICK)
                return
            }
            return
        }

        val dialogText = collectDialogText(root)

        if (dialogText.isBlank()) return

        // Deduplicate: only send once per unique dialog text to avoid flooding the channel
        if (dialogText == lastSentDialogText) return
        lastSentDialogText = dialogText

        Log.d(TAG, "USSD dialog detected: $dialogText")

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

            // EXPLICIT SAFETY & ROBUSTNESS: Clear deduplication key before writing
            // to allow identical consecutive prompts (e.g. invalid inputs) to be processed
            lastSentDialogText = null

            // If the dialog has an input field, populate it
            if (input.isNotEmpty()) {
                findEditTextNode(root)?.let { editNode ->
                    // Request focus first to ensure key and text change listeners are triggered
                    editNode.performAction(AccessibilityNodeInfo.ACTION_FOCUS)

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

            Log.d(TAG, "injectInput: submitted '$input'")
        }
    }

    /**
     * Reset per-session deduplication state after the USSD session is complete.
     */
    fun dismiss() {
        lastSentDialogText = null
        closeDialog()
    }

    /**
     * Attempts to physically close the system dialog.
     */
    fun closeDialog() {
        handler.post {
            val root = rootInActiveWindow ?: run {
                Log.w(TAG, "closeDialog: no active window to dismiss")
                return@post
            }
            
            // Try to click cancel/close first
            val cancelBtn = findCancelButton(root)
            if (cancelBtn != null) {
                cancelBtn.performAction(AccessibilityNodeInfo.ACTION_CLICK)
                Log.d(TAG, "closeDialog: clicked cancel button")
            } else {
                // Fallback to global back
                performGlobalAction(GLOBAL_ACTION_BACK)
                Log.d(TAG, "closeDialog: performed global back action")
            }
        }
    }

    // -------------------------------------------------------------------------
    // Node traversal helpers
    // -------------------------------------------------------------------------

    internal fun isTelephonyWindow(eventPackage: String, className: String?): Boolean {
        val lowerPackage = eventPackage.lowercase()
        val lowerClassName = className?.lowercase().orEmpty()

        // Fast accept: package name unambiguously identifies a telephony/dialer window.
        // These covers AOSP (com.android.phone), Google Dialer, and most OEM variants.
        if (lowerPackage.contains("phone")
            || lowerPackage.contains("dialer")
            || lowerPackage.contains("telecom")
            || lowerPackage.contains("telephony")
            || lowerPackage.contains("contacts") // Xiaomi, Huawei dialer package
            || lowerPackage.contains("sim")
        ) return true

        // Accept by class name when the class is specifically USSD/MMI related.
        if (lowerClassName.contains("ussd") || lowerClassName.contains("mmi")) return true

        // Broad system packages ("android", "systemui"): only accept when the class is
        // specifically an AlertDialog — NOT permission dialogs, notification shade, etc.
        // This prevents the service from accidentally interacting with system permission
        // dialogs (e.g. com.android.packageinstaller) or the volume overlay.
        val isSystemPackage = lowerPackage.contains("systemui") || lowerPackage == "android"
        val isAlertDialogClass = lowerClassName == "android.app.alertdialog"
            || lowerClassName.endsWith(".alertdialog")
            || lowerClassName.endsWith(".ussdresponsedialog")
        return isSystemPackage && isAlertDialogClass
    }

    private fun findEditTextNode(root: AccessibilityNodeInfo): AccessibilityNodeInfo? {
        val queue = ArrayDeque<AccessibilityNodeInfo>()
        queue.add(root)
        while (queue.isNotEmpty()) {
            val node = queue.removeFirst()
            val className = node.className?.toString().orEmpty()
            if (className.contains("EditText", ignoreCase = true)) {
                return node
            }
            for (i in 0 until node.childCount) {
                node.getChild(i)?.let { queue.add(it) }
            }
        }
        return null
    }

    private fun collectDialogText(root: AccessibilityNodeInfo): String {
        val textParts = mutableListOf<String>()
        val queue = ArrayDeque<AccessibilityNodeInfo>()
        queue.add(root)

        while (queue.isNotEmpty()) {
            val node = queue.removeFirst()
            val className = node.className?.toString().orEmpty()
            if (className.contains("TextView", ignoreCase = true)) {
                node.text?.toString()?.trim()?.takeIf { it.isNotBlank() }?.let(textParts::add)
            }

            for (i in 0 until node.childCount) {
                node.getChild(i)?.let { queue.add(it) }
            }
        }

        return textParts.joinToString("\n").trim()
    }

    /**
     * Finds the Send/OK button in a USSD dialog.
     *
     * Strategy: Match known localized labels first, then fall back to the last button in the
     * tree (which is typically the "confirm" button by dialog layout convention).
     */
    private fun findSendButton(root: AccessibilityNodeInfo): AccessibilityNodeInfo? {
        var fallbackButton: AccessibilityNodeInfo? = null
        val queue = ArrayDeque<AccessibilityNodeInfo>()
        queue.add(root)

        while (queue.isNotEmpty()) {
            val node = queue.removeFirst()
            val className = node.className?.toString().orEmpty()
            val text = node.text?.toString()?.lowercase()?.trim() ?: ""
            val isButton = className.contains("Button", ignoreCase = true) || (node.isClickable && text.isNotEmpty())

            if (isButton) {
                fallbackButton = node
                if (text in CONFIRM_LABELS) {
                    return node
                }
            }

            for (i in 0 until node.childCount) {
                node.getChild(i)?.let { queue.add(it) }
            }
        }

        return fallbackButton
    }

    private fun findSimButton(root: AccessibilityNodeInfo, simSlot: Int?): AccessibilityNodeInfo? {
        if (simSlot == null) return null
        // simSlot is typically 0 or 1.
        val targetNumber = (simSlot + 1).toString()
        val queue = ArrayDeque<AccessibilityNodeInfo>()
        queue.add(root)

        while (queue.isNotEmpty()) {
            val node = queue.removeFirst()
            val text = node.text?.toString()?.lowercase()?.trim() ?: ""
            if (text.contains("sim $targetNumber") || text.contains("sim$targetNumber") || text.contains("slot $targetNumber")) {
                if (node.isClickable) {
                    return node
                } else {
                    // Try to find an ancestor that is clickable
                    var parent = node.parent
                    while (parent != null) {
                        if (parent.isClickable) return parent
                        parent = parent.parent
                    }
                }
            }
            for (i in 0 until node.childCount) {
                node.getChild(i)?.let { queue.add(it) }
            }
        }
        return null
    }

    private fun findCancelButton(root: AccessibilityNodeInfo): AccessibilityNodeInfo? {
        val cancelLabels = setOf("cancel", "close", "dismiss", "abort")
        val queue = ArrayDeque<AccessibilityNodeInfo>()
        queue.add(root)

        while (queue.isNotEmpty()) {
            val node = queue.removeFirst()
            val className = node.className?.toString().orEmpty()
            val text = node.text?.toString()?.lowercase()?.trim() ?: ""
            val isButton = className.contains("Button", ignoreCase = true) || (node.isClickable && text.isNotEmpty())

            if (isButton) {
                if (text in cancelLabels) {
                    return node
                }
            }
            for (i in 0 until node.childCount) {
                node.getChild(i)?.let { queue.add(it) }
            }
        }
        return null
    }
}
