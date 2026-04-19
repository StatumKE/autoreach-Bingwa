<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Statum\NativeContacts\NativeContactsServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * The NativePHP plugins to enable.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            NativeContactsServiceProvider::class,
        ];
    }
}
