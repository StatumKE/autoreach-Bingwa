<?php

namespace App\Providers;

use App\Console\Commands\NativeTailCommand;
use App\Services\OperationalStatusService;
use App\Support\NativeRuntime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View as ViewContract;
use Native\Mobile\Commands\TailCommand as VendorTailCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->shareOperationalStatus();
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

    private function shareOperationalStatus(): void
    {
        View::composer('*', function (ViewContract $view): void {
            $nativeRuntime = app(NativeRuntime::class);
            $request = request();

            $view->with('isNativeApp', $nativeRuntime->isNativeRequest($request));
            $view->with('isAndroidNativeApp', $nativeRuntime->isAndroidWebView($request));
        });

        View::composer('layouts.app', function (ViewContract $view): void {
            if (! Auth::check()) {
                $view->with('globalOperationalStatus', null);

                return;
            }

            $view->with('globalOperationalStatus', app(OperationalStatusService::class)->summary());
        });
    }
}
