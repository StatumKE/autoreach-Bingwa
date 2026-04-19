package com.statum.plugins.nativescheduler

import android.content.Context
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import kotlinx.coroutines.runBlocking

object BingwaFunctions {
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
}
