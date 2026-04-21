<?php

use App\Providers\NativeServiceProvider;
use Native\Mobile\Providers\DeviceServiceProvider;

test('native device plugin is registered for native builds', function () {
    $plugins = (new NativeServiceProvider(app()))->plugins();

    expect($plugins)->toContain(DeviceServiceProvider::class);
});
