package com.statum.plugins.nativescheduler

import android.content.Context
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

internal data class PendingUssdCallback(
    val id: Int,
    val success: Boolean,
    val message: String,
    val callbackToken: String,
    val attempts: Int = 0,
)

internal object UssdCallbackOutbox {
    private const val PREFS = "bingwa_ussd_callback_outbox"
    private const val KEY_QUEUE = "queue"
    private const val KEY_LAST_REPLAY_AT = "last_replay_at"
    private const val MAX_BUFFERED_CALLBACKS = 50
    private const val MAX_REPLAY_BATCH = 10
    private const val TAG = "UssdCallbackOutbox"

    fun enqueue(context: Context, id: Int, success: Boolean, message: String): String {
        val queue = readQueue(context).toMutableList()
        val token = UUID.randomUUID().toString()
        val tokenizedCallback = PendingUssdCallback(
            id = id,
            success = success,
            message = message,
            callbackToken = token,
        )

        if (queue.any { it.id == id }) {
            queue.removeAll { it.id == id }
        }

        queue.add(tokenizedCallback)

        if (queue.size > MAX_BUFFERED_CALLBACKS) {
            queue.removeAt(0)
        }

        writeQueue(context, queue)

        Log.i(TAG, "Buffered USSD callback for transaction #$id token=$token queue_size=${queue.size}")

        return token
    }

    fun replay(context: Context, sender: (PendingUssdCallback) -> Boolean): Int {
        val queue = readQueue(context)
        if (queue.isEmpty()) {
            Log.d(TAG, "Replay skipped because the outbox is empty")
            return 0
        }

        val remaining = mutableListOf<PendingUssdCallback>()
        var delivered = 0

        queue.take(MAX_REPLAY_BATCH).forEach { callback ->
            val deliveredNow = try {
                sender(callback)
            } catch (throwable: Throwable) {
                Log.w(TAG, "Replay delivery threw for transaction #${callback.id}", throwable)
                false
            }

            if (deliveredNow) {
                delivered++
            } else {
                remaining.add(callback.copy(attempts = callback.attempts + 1))
            }
        }

        remaining.addAll(queue.drop(MAX_REPLAY_BATCH))
        writeQueue(context, remaining)
        touchReplayTimestamp(context)

        Log.i(TAG, "Replayed USSD callback batch delivered=$delivered remaining=${remaining.size}")

        return delivered
    }

    fun markDelivered(context: Context, id: Int): Boolean {
        val queue = readQueue(context).filterNot { it.id == id }
        writeQueue(context, queue)
        Log.d(TAG, "Marked USSD callback delivered for transaction #$id remaining=${queue.size}")
        return true
    }

    fun lastReplayAt(context: Context): Long {
        return context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getLong(KEY_LAST_REPLAY_AT, 0L)
    }

    private fun readQueue(context: Context): List<PendingUssdCallback> {
        val raw = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(KEY_QUEUE, "[]")
            ?: "[]"

        return try {
            val jsonArray = JSONArray(raw)
            buildList {
                for (index in 0 until jsonArray.length()) {
                    val entry = jsonArray.optJSONObject(index) ?: continue
                    val id = entry.optInt("id", -1)
                    if (id <= 0) {
                        continue
                    }

                    add(
                    PendingUssdCallback(
                        id = id,
                        success = entry.optBoolean("success", false),
                        message = entry.optString("message", ""),
                        callbackToken = entry.optString("callback_token", ""),
                        attempts = entry.optInt("attempts", 0),
                    )
                )
                }
            }
        } catch (_: Exception) {
            emptyList()
        }
    }

    private fun writeQueue(context: Context, queue: List<PendingUssdCallback>) {
        val jsonArray = JSONArray()
        queue.forEach { callback ->
            jsonArray.put(
                JSONObject()
                    .put("id", callback.id)
                    .put("success", callback.success)
                    .put("message", callback.message)
                    .put("callback_token", callback.callbackToken)
                    .put("attempts", callback.attempts)
            )
        }

        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_QUEUE, jsonArray.toString())
            .apply()
    }

    private fun touchReplayTimestamp(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putLong(KEY_LAST_REPLAY_AT, System.currentTimeMillis())
            .apply()
    }
}
