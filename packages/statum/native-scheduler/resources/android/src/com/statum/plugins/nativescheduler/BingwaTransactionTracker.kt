package com.statum.plugins.nativescheduler

import android.content.Context
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject

/**
 * Lightweight SharedPreferences store that tracks the lifecycle of every USSD
 * transaction that Kotlin has accepted ownership of.
 *
 * ## Purpose
 *
 * PHP's [GetNextBingwaQueuedTransaction.recoverStuckTransactions()] can only recover
 * transactions that have been stuck for ≥ 10 minutes (time-based DB scan). If Android
 * kills the PHP process after it sets `status = "processing"` but before Kotlin ever
 * receives the transaction, PHP has no way to know the transaction is orphaned until
 * the 10-minute window expires.
 *
 * This tracker closes that gap:
 *   1. [recordClaimed] is called the moment [UssdExecutionService] accepts a transaction.
 *   2. [recordCompleted] is called after [UssdExecutionService.deliverCallback] succeeds.
 *   3. PHP calls [GetStuckTransactions] bridge function to retrieve IDs that have been
 *      claimed by Kotlin but not completed within a configurable threshold (default 2 min).
 *
 * ## Process safety
 *
 * [UssdExecutionService] runs in the **main process**. PHP's persistent runtime also runs
 * in the main process. Because both writers/readers live in the same process,
 * `Context.MODE_PRIVATE` SharedPreferences are safe — no cross-process access occurs.
 *
 * Writes are serialised via `synchronized(lock)` to prevent concurrent modification of
 * the JSON payload by multiple coroutine threads launching USSD jobs simultaneously.
 */
internal object BingwaTransactionTracker {

    private const val PREFS = "bingwa_tx_tracker"
    private const val KEY_CLAIMS = "claims"
    private const val TAG = "BingwaTransactionTracker"

    /** Opaque lock object — prevents concurrent JSON read-modify-write races. */
    private val lock = Any()

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Record that Kotlin has accepted ownership of [id] at [timestampMs].
     * Overwrites any previous entry for the same ID (e.g. retried dispatch).
     */
    fun recordClaimed(context: Context, id: Int, timestampMs: Long = System.currentTimeMillis()) {
        if (id <= 0) {
            return
        }

        synchronized(lock) {
            val claims = readClaims(context).toMutableList()

            // Remove any stale entry for the same ID before adding the new one.
            claims.removeAll { it.first == id }
            claims.add(Pair(id, timestampMs))

            writeClaims(context, claims)
        }

        Log.i(TAG, "Recorded claim for transaction #$id at $timestampMs")
    }

    /**
     * Remove [id] from the tracker after the callback has been delivered successfully.
     * Safe to call even if the ID was never recorded.
     */
    fun recordCompleted(context: Context, id: Int) {
        if (id <= 0) {
            return
        }

        synchronized(lock) {
            val claims = readClaims(context).toMutableList()
            val removed = claims.removeAll { it.first == id }
            if (removed) {
                writeClaims(context, claims)
                Log.i(TAG, "Removed claim for transaction #$id (callback delivered)")
            }
        }
    }

    /**
     * Return IDs that have been in-flight (claimed but not completed) for longer
     * than [thresholdMs] milliseconds.
     *
     * The PHP bridge function [BingwaFunctions.GetStuckTransactions] calls this
     * so PHP can reset those transactions from `processing` → `queued` without
     * waiting for the standard 10-minute time-based DB scan.
     */
    fun getStuckIds(context: Context, thresholdMs: Long): List<Int> {
        val cutoff = System.currentTimeMillis() - thresholdMs

        return synchronized(lock) {
            readClaims(context).filter { it.second < cutoff }.map { it.first }
        }
    }

    // -------------------------------------------------------------------------
    // Internal storage helpers
    // -------------------------------------------------------------------------

    /**
     * Read the current list of (id, timestampMs) pairs from SharedPreferences.
     * Returns an empty list if the prefs are missing or the JSON is corrupt.
     */
    private fun readClaims(context: Context): List<Pair<Int, Long>> {
        val raw = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(KEY_CLAIMS, "[]") ?: "[]"

        return try {
            val array = JSONArray(raw)
            buildList {
                for (index in 0 until array.length()) {
                    val entry = array.optJSONObject(index) ?: continue
                    val id = entry.optInt("id", -1)
                    val ts = entry.optLong("ts", -1L)
                    if (id > 0 && ts >= 0) {
                        add(Pair(id, ts))
                    }
                }
            }
        } catch (_: Exception) {
            emptyList()
        }
    }

    /**
     * Persist [claims] back to SharedPreferences as a JSON array.
     */
    private fun writeClaims(context: Context, claims: List<Pair<Int, Long>>) {
        val array = JSONArray()
        claims.forEach { (id, ts) ->
            array.put(JSONObject().put("id", id).put("ts", ts))
        }

        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_CLAIMS, array.toString())
            .apply()
    }
}
