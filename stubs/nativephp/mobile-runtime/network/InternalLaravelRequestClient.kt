package com.nativephp.mobile.network

import android.util.Log
import com.nativephp.mobile.bridge.PHPBridge

object InternalLaravelRequestClient {
    private const val TAG = "InternalLaravelRequest"

    data class Result(
        val statusCode: Int,
        val successful: Boolean,
        val body: String?,
        val headers: Map<String, String> = emptyMap(),
    )

    fun postJson(
        phpBridge: PHPBridge,
        path: String,
        payload: String,
        headers: Map<String, String> = emptyMap(),
    ): Result {
        val requestHeaders = linkedMapOf(
            "Accept" to "application/json",
            "Content-Type" to "application/json",
            "Content-Length" to payload.toByteArray().size.toString(),
            "Host" to "127.0.0.1",
            "User-Agent" to "NativePHP Android Internal Client",
        )
        requestHeaders.putAll(headers)

        val rawResponse = phpBridge.handleLaravelRequest(
            PHPRequest(
                url = path,
                method = "POST",
                body = payload,
                headers = requestHeaders,
            ),
        )

        val result = parseRawResponse(rawResponse)
        Log.d(
            TAG,
            "Internal Laravel request completed [status=${result.statusCode} successful=${result.successful}]",
        )

        return result
    }

    private fun parseRawResponse(rawResponse: String): Result {
        val parts = rawResponse.split("\r\n\r\n", limit = 2)
        val headerBlock = parts.getOrNull(0).orEmpty()
        val body = parts.getOrNull(1)
        val headerLines = headerBlock.split("\r\n").filter { it.isNotBlank() }
        val statusLine = headerLines.firstOrNull().orEmpty()
        val statusCode = statusLine.split(" ")
            .getOrNull(1)
            ?.toIntOrNull()
            ?: 200

        val parsedHeaders = buildMap {
            headerLines.drop(1).forEach { line ->
                val separator = line.indexOf(':')
                if (separator <= 0) {
                    return@forEach
                }

                val key = line.substring(0, separator).trim()
                val value = line.substring(separator + 1).trim()
                put(key, value)
            }
        }

        return Result(
            statusCode = statusCode,
            successful = statusCode in 200..299,
            body = body,
            headers = parsedHeaders,
        )
    }
}
