<?php

namespace Statum\NativeScheduler;

use Illuminate\Support\ServiceProvider;
use Statum\NativeScheduler\Commands\CopyAssetsCommand;

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
        // Register plugin hook commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CopyAssetsCommand::class,
            ]);
        }
    }
}
