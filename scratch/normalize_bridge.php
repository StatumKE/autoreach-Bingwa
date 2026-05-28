<?php

$bridgeFile = 'nativephp/android/app/src/main/cpp/php_bridge.c';
if (! file_exists($bridgeFile)) {
    echo "File not found: $bridgeFile\n";
    exit(1);
}

$contents = file_get_contents($bridgeFile);

// 1. Remove all redundant unlocks (multiple consecutive unlocks of g_php_request_mutex)
$contents = preg_replace('/(\s+pthread_mutex_unlock\(&g_php_request_mutex\);){2,}/', "\n        pthread_mutex_unlock(&g_php_request_mutex);", $contents);

// 2. Fix ephemeral_embed_init hot path (remove module startup)
$hotPathStartup = <<<'C'
        if (php_embed_module.startup(&php_embed_module) == FAILURE) {
            LOGE("ephemeral_embed_init: module startup failed");
            return FAILURE;
        }
C;
$contents = str_replace($hotPathStartup, '', $contents);

// 3. Fix worker_embed_init startup (it should also probably avoid startup if already done,
// but it's used differently. Let's look at the context.)
$workerStartup = <<<'C'
    // php_module_startup() is guarded by module_initialized — it won't re-init
    // but it will call sapi_activate() for this thread's context
    if (php_embed_module.startup(&php_embed_module) == FAILURE) {
        LOGE("worker_embed_init: module startup failed");
        return FAILURE;
    }
C;
$contents = str_replace($workerStartup, '', $contents);

// 4. Fix mutex leaks in early returns
$contents = str_replace(
    "if (!ephemeral_initialized) {\n        LOGE(\"ephemeral_artisan: ephemeral runtime not initialized!\");\n        pthread_mutex_unlock(&g_ephemeral_mutex);\n        return (*env)->NewStringUTF(env, \"Ephemeral runtime not initialized.\");\n    }",
    "if (!ephemeral_initialized) {\n        LOGE(\"ephemeral_artisan: ephemeral runtime not initialized!\");\n        pthread_mutex_unlock(&g_ephemeral_mutex);\n        pthread_mutex_unlock(&g_php_request_mutex);\n        return (*env)->NewStringUTF(env, \"Ephemeral runtime not initialized.\");\n    }",
    $contents
);

$contents = str_replace(
    "if (!worker_initialized) {\n        LOGE(\"worker_artisan: worker not initialized!\");\n        pthread_mutex_unlock(&g_worker_mutex);\n        return (*env)->NewStringUTF(env, \"Worker runtime not initialized.\");\n    }",
    "if (!worker_initialized) {\n        LOGE(\"worker_artisan: worker not initialized!\");\n        pthread_mutex_unlock(&g_worker_mutex);\n        pthread_mutex_unlock(&g_php_request_mutex);\n        return (*env)->NewStringUTF(env, \"Worker runtime not initialized.\");\n    }",
    $contents
);

file_put_contents($bridgeFile, $contents);
echo "Normalized $bridgeFile\n";
