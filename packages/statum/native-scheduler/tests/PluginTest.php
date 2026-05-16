<?php

/**
 * Plugin validation tests for NativeScheduler.
 *
 * Run with: ./vendor/bin/pest
 */
beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifestPath = $this->pluginPath.'/nativephp.json';
});

describe('Plugin Manifest', function () {
    it('has a valid nativephp.json file', function () {
        expect(file_exists($this->manifestPath))->toBeTrue();

        $content = file_get_contents($this->manifestPath);
        $manifest = json_decode($content, true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest)->toHaveKeys(['name', 'namespace', 'bridge_functions']);
        expect($manifest['name'])->toBe('statum/native-scheduler');
        expect($manifest['namespace'])->toBe('BingwaEngine');
    });

    it('has valid bridge functions', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest['bridge_functions'])->toBeArray();
        expect(collect($manifest['bridge_functions'])->pluck('name'))->toContain('SendSms');

        foreach ($manifest['bridge_functions'] as $function) {
            expect($function)->toHaveKeys(['name']);
            expect($function)->toHaveKey('android');
            expect($function['android'])->toBeString();
            expect($function['android'])->not->toBeEmpty();
        }
    });

    it('has valid marketplace metadata', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest)->not->toHaveKeys(['keywords', 'category', 'platforms']);
    });
});

describe('Native Code', function () {
    it('has Android Kotlin file', function () {
        $kotlinFile = $this->pluginPath.'/resources/android/src/com/statum/plugins/nativescheduler/BingwaFunctions.kt';

        expect(file_exists($kotlinFile))->toBeTrue();

        $content = file_get_contents($kotlinFile);
        expect($content)->toContain('package com.statum.plugins.nativescheduler');
        expect($content)->toContain('object BingwaFunctions');
        expect($content)->toContain('BridgeFunction');
    });

    it('has matching bridge function classes in native code', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        $kotlinFile = $this->pluginPath.'/resources/android/src/com/statum/plugins/nativescheduler/BingwaFunctions.kt';

        $kotlinContent = file_get_contents($kotlinFile);

        foreach ($manifest['bridge_functions'] as $function) {
            $parts = explode('.', $function['android']);
            $className = end($parts);

            expect($kotlinContent)->toContain("class {$className}");
        }
    });
});

describe('PHP Classes', function () {
    it('has service provider', function () {
        $file = $this->pluginPath.'/src/NativeSchedulerServiceProvider.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Statum\NativeScheduler');
        expect($content)->toContain('class NativeSchedulerServiceProvider');
    });

    it('does not ship the removed scheduler facade or PHP implementation class', function () {
        expect(file_exists($this->pluginPath.'/src/Facades/NativeScheduler.php'))->toBeFalse();
        expect(file_exists($this->pluginPath.'/src/NativeScheduler.php'))->toBeFalse();
    });
});

describe('Composer Configuration', function () {
    it('has valid composer.json', function () {
        $composerPath = $this->pluginPath.'/composer.json';
        expect(file_exists($composerPath))->toBeTrue();

        $content = file_get_contents($composerPath);
        $composer = json_decode($content, true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
        expect($composer['type'])->toBe('nativephp-plugin');
        expect($composer['extra']['nativephp']['manifest'])->toBe('nativephp.json');
    });
});

describe('Lifecycle Hooks', function () {
    it('does not patch generated NativePHP framework files', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest)->not->toHaveKey('hooks');
        expect(file_exists($this->pluginPath.'/src/Commands/CopyAssetsCommand.php'))->toBeFalse();
    });

    it('has valid assets configuration', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        // Assets are at top level with android/ios nested inside
        if (isset($manifest['assets'])) {
            expect($manifest['assets'])->toBeArray();

            if (isset($manifest['assets']['android'])) {
                expect($manifest['assets']['android'])->toBeArray();
            }

            if (isset($manifest['assets']['ios'])) {
                expect($manifest['assets']['ios'])->toBeArray();
            }
        }
    });

    it('declares the sms permission required by the bridge', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest['android']['permissions'] ?? [])
            ->toContain('android.permission.SEND_SMS');
    });
});
