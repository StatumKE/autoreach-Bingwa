<?php

namespace Statum\NativeContacts;

use Illuminate\Support\ServiceProvider;

class NativeContactsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NativeContactsPlugin::class, fn (): NativeContactsPlugin => new NativeContactsPlugin);
    }
}
