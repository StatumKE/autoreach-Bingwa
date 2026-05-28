<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\NativeServiceProvider;
use App\Providers\SqlitePerformanceServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    NativeServiceProvider::class,
    SqlitePerformanceServiceProvider::class,
];
