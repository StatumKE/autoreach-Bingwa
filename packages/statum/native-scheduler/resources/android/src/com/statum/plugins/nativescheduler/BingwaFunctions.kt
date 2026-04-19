package com.statum.plugins.nativescheduler

import android.content.Context
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import kotlinx.coroutines.runBlocking

/**
 * NativePHP Bridge Function to trigger Sambaza USSD from Javascript/PHP.
 * 
 * Registered in nativephp.json: "NativeScheduler.TriggerSambaza"
 * Payload expected: {"code": "*140*50*0712345678#", "simSlot": 0}
 */
object BingwaFunctions {
    class TriggerSambaza(private val context: Context) : BridgeFunction {
        
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val code = parameters["code"] as? String
                ?: throw BridgeError.InvalidParameters("Missing 'code' parameter")
                
            val simSlot = (parameters["simSlot"] as? Number)?.toInt() ?: 0

            // Use the advanced mode specifically for Sambaza to parse the response text
            val executor = UssdExecutor(context)
            
            // Block the Javascript thread until the USSD response is fully collected
            val result = runBlocking {
                executor.execute(code, "advanced", simSlot, isSambaza = true)
            }

            return mapOf(
                "success" to result.success,
                "message" to result.message
            )
        }
    }
}
