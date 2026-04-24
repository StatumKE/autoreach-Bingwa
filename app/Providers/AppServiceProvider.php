<?php

namespace App\Providers;

use App\Console\Commands\NativeTailCommand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Native\Mobile\Commands\TailCommand as VendorTailCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VendorTailCommand::class, NativeTailCommand::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(5));
    }
}
