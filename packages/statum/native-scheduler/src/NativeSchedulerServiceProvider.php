<?php

namespace Statum\NativeScheduler;

use Illuminate\Support\ServiceProvider;

class NativeSchedulerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NativeScheduler::class, function () {
            return new NativeScheduler;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            $this->app->make(NativeScheduler::class)->start();
        } catch (\Exception $e) {
            // Ignore if native environment is not ready
        }
    }
}
