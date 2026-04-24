<?php

use App\Console\Commands\NativeTailCommand as AppNativeTailCommand;
use Native\Mobile\Commands\TailCommand;

test('native tail is bound to the app override and targets the android persisted log file', function () {
    $command = app(TailCommand::class);

    expect($command)->toBeInstanceOf(AppNativeTailCommand::class);

    $androidLogPath = (function (): string {
        return $this->androidLogPath();
    })->call($command);

    $buildTailCommand = (function (string $appId, string $logPath): array {
        return $this->buildTailCommand($appId, $logPath);
    })->call($command, 'com.autoreach.bingwa', $androidLogPath);

    $buildPrepareLogCommand = (function (string $appId, string $logPath): array {
        return $this->buildPrepareLogCommand($appId, $logPath);
    })->call($command, 'com.autoreach.bingwa', $androidLogPath);

    $buildTouchLogCommand = (function (string $appId, string $logPath): array {
        return $this->buildTouchLogCommand($appId, $logPath);
    })->call($command, 'com.autoreach.bingwa', $androidLogPath);

    expect($androidLogPath)->toBe('app_storage/persisted_data/storage/logs/laravel.log');
    expect($buildTailCommand)->toBe([
        'adb',
        'shell',
        'run-as',
        'com.autoreach.bingwa',
        'tail',
        '-f',
        'app_storage/persisted_data/storage/logs/laravel.log',
    ]);
    expect($buildPrepareLogCommand)->toBe([
        'adb',
        'shell',
        'run-as',
        'com.autoreach.bingwa',
        'mkdir',
        '-p',
        '/data/data/com.autoreach.bingwa/app_storage/persisted_data/storage/logs',
    ]);
    expect($buildTouchLogCommand)->toBe([
        'adb',
        'shell',
        'run-as',
        'com.autoreach.bingwa',
        'touch',
        '/data/data/com.autoreach.bingwa/app_storage/persisted_data/storage/logs/laravel.log',
    ]);
});
