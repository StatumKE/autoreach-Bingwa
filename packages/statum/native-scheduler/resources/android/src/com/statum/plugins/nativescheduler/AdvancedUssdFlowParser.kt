package com.statum.plugins.nativescheduler

internal data class AdvancedUssdFlow(
    val baseDialCode: String,
    val replies: List<String>,
) {
    val steps: List<String> = listOf(baseDialCode) + replies
}

internal fun parseAdvancedUssdFlow(code: String): AdvancedUssdFlow {
    val normalized = code.trim()
        .trimStart('*')
        .trimEnd('#')

    if (normalized.isBlank()) {
        val fallback = code.trim()
        return AdvancedUssdFlow(baseDialCode = fallback, replies = emptyList())
    }

    val tokens = normalized
        .split('*')
        .map { it.trim() }
    // Note: empty strings between consecutive ** (e.g. *100**1#) are preserved intentionally.
    // An empty token represents a "send nothing / confirm" step in the USSD menu tree.
    // Filtering them out would silently skip a required menu navigation step.

    if (tokens.isEmpty() || tokens.all { it.isEmpty() }) {
        val fallback = code.trim()
        return AdvancedUssdFlow(baseDialCode = fallback, replies = emptyList())
    }

    val firstNonEmpty = tokens.indexOfFirst { it.isNotEmpty() }
    val baseDialCode = "*${tokens[firstNonEmpty]}#"
    val replies = tokens.drop(firstNonEmpty + 1)

    if (replies.any { it.isEmpty() }) {
        android.util.Log.w("UssdFlowParser", "USSD code contains empty reply step (consecutive **): $code")
    }

    return AdvancedUssdFlow(
        baseDialCode = baseDialCode,
        replies = replies,
    )
}
