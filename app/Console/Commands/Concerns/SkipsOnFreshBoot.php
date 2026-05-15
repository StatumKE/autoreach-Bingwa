<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Prevents background commands from executing during the first boot after a fresh install.
 *
 * NativePHP on Android runs both the background task scheduler (PHPSchedulerWorker)
 * and the WebView's PHP bridge (PHPBridge) on separate threads. When both attempt a
 * cold-path php_embed_init simultaneously at startup, it causes a SIGSEGV in
 * zend_map_ptr_new_static (signal 11). This trait skips execution during the initial
 * boot window so the PHP runtime can fully initialise before background tasks run.
 */
trait SkipsOnFreshBoot
{
    /**
     * The number of seconds to skip after a fresh install/first boot.
     * Must be long enough for php_embed_init in the main WebView thread to complete.
     */
    protected int $freshBootGracePeriodSeconds = 90;

    /**
     * The cache key that records when the app first booted.
     */
    protected string $firstBootCacheKey = 'native_app_first_boot_at';

    /**
     * Returns true if we are still within the grace period after a fresh install.
     * Records the first-boot timestamp on the very first call.
     */
    protected function isInFreshBootGracePeriod(): bool
    {
        $firstBootAt = Cache::get($this->firstBootCacheKey);

        if ($firstBootAt === null) {
            // First time any background command runs — record it.
            Cache::forever($this->firstBootCacheKey, now()->timestamp);

            Log::info(class_basename($this).': fresh-boot detected — skipping execution for '.$this->freshBootGracePeriodSeconds.'s grace period.');

            return true;
        }

        $secondsSinceBoot = now()->timestamp - (int) $firstBootAt;

        if ($secondsSinceBoot < $this->freshBootGracePeriodSeconds) {
            Log::info(class_basename($this).': still within fresh-boot grace period ('.$secondsSinceBoot.'s elapsed, '.$this->freshBootGracePeriodSeconds.'s required) — skipping.');

            return true;
        }

        return false;
    }
}
