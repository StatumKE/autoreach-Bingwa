<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Providers\DeviceServiceProvider;
use Native\Mobile\Providers\PushNotificationsServiceProvider;
use NativePHP\BackgroundTasks\BackgroundTasksServiceProvider;
use Statum\NativeContacts\NativeContactsServiceProvider;
use Statum\NativeScheduler\NativeSchedulerServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * The NativePHP plugins to enable.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            NativeContactsServiceProvider::class,
            NativeSchedulerServiceProvider::class,
            DeviceServiceProvider::class,
            PushNotificationsServiceProvider::class,
            BackgroundTasksServiceProvider::class,
        ];
    }
}
