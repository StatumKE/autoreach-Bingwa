package com.nativephp.mobile.bridge

import android.content.Context
import android.util.Log

object LaravelRuntimeBridgeProvider {
    private const val TAG = "LaravelRuntimeProvider"
    private const val WAIT_TIMEOUT_MILLIS = 3_000L
    private const val WAIT_POLL_MILLIS = 25L

    private val lock = Any()

    @Volatile
    private var sharedBridge: PHPBridge? = null

    @Volatile
    private var providerOwnsBridge = false

    @Volatile
    private var environmentInitializing = false

    @Volatile
    private var initializationFailed = false

    fun register(context: Context, bridge: PHPBridge) {
        synchronized(lock) {
            sharedBridge = bridge
            providerOwnsBridge = false
            environmentInitializing = false
            initializationFailed = false
        }

        Log.d(TAG, "Registered shared Laravel runtime bridge for ${context.packageName}")
    }

    fun get(context: Context): PHPBridge {
        sharedBridge?.let {
            return it
        }

        val deadline = System.currentTimeMillis() + WAIT_TIMEOUT_MILLIS
        while (environmentInitializing && System.currentTimeMillis() < deadline) {
            Thread.sleep(WAIT_POLL_MILLIS)
            sharedBridge?.let {
                return it
            }
        }

        synchronized(lock) {
            sharedBridge?.let {
                return it
            }
            environmentInitializing = true
            initializationFailed = false
        }

        val appContext = context.applicationContext

        return try {
            LaravelEnvironment(appContext).initialize()
            val bridge = PHPBridge(appContext)
            synchronized(lock) {
                sharedBridge = bridge
                providerOwnsBridge = true
                environmentInitializing = false
                initializationFailed = false
            }
            bridge
        } catch (exception: Exception) {
            synchronized(lock) {
                environmentInitializing = false
                initializationFailed = true
            }
            throw exception
        }
    }

    fun beginInitialization() {
        environmentInitializing = true
        initializationFailed = false
    }

    fun failInitialization() {
        environmentInitializing = false
        initializationFailed = true
    }

    fun isInitializing(): Boolean {
        return environmentInitializing
    }

    fun reset(shutdown: Boolean = false) {
        val bridgeToShutdown = synchronized(lock) {
            val bridge = sharedBridge
            val owned = providerOwnsBridge

            sharedBridge = null
            providerOwnsBridge = false
            environmentInitializing = false
            initializationFailed = false

            if (shutdown && owned) {
                bridge
            } else {
                null
            }
        }

        bridgeToShutdown?.let {
            runCatching {
                it.shutdown()
            }.onFailure { exception ->
                Log.w(TAG, "Bridge shutdown failed: ${exception.message}")
            }
        }
    }
}
