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
        .filter { it.isNotEmpty() }

    if (tokens.isEmpty()) {
        val fallback = code.trim()
        return AdvancedUssdFlow(baseDialCode = fallback, replies = emptyList())
    }

    val baseDialCode = "*${tokens.first()}#"
    val replies = tokens.drop(1)

    return AdvancedUssdFlow(
        baseDialCode = baseDialCode,
        replies = replies,
    )
}
