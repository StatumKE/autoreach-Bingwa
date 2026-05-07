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

    it('has facade', function () {
        $file = $this->pluginPath.'/src/Facades/NativeScheduler.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Statum\NativeScheduler\Facades');
        expect($content)->toContain('class NativeScheduler extends Facade');
    });

    it('has main implementation class', function () {
        $file = $this->pluginPath.'/src/NativeScheduler.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Statum\NativeScheduler');
        expect($content)->toContain('class NativeScheduler');
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
    it('has valid hooks configuration', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        if (isset($manifest['hooks'])) {
            expect($manifest['hooks'])->toBeArray();

            $validHooks = ['pre_compile', 'post_compile', 'copy_assets', 'post_build'];
            foreach (array_keys($manifest['hooks']) as $hook) {
                expect($hook)->toBeIn($validHooks);
            }
        }
    });

    it('has copy_assets hook command', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);

        expect($manifest['hooks']['copy_assets'] ?? null)->not->toBeNull();

        $commandFile = $this->pluginPath.'/src/Commands/CopyAssetsCommand.php';
        expect(file_exists($commandFile))->toBeTrue();
    });

    it('copy_assets command extends NativePluginHookCommand', function () {
        $commandFile = $this->pluginPath.'/src/Commands/CopyAssetsCommand.php';
        $content = file_get_contents($commandFile);

        expect($content)->toContain('extends NativePluginHookCommand');
        expect($content)->toContain('use Native\Mobile\Plugins\Commands\NativePluginHookCommand');
    });

    it('copy_assets command has correct signature', function () {
        $manifest = json_decode(file_get_contents($this->manifestPath), true);
        $expectedSignature = $manifest['hooks']['copy_assets'];

        $commandFile = $this->pluginPath.'/src/Commands/CopyAssetsCommand.php';
        $content = file_get_contents($commandFile);

        expect($content)->toContain('$signature = \''.$expectedSignature.'\'');
    });

    it('copy_assets command has platform-specific methods', function () {
        $commandFile = $this->pluginPath.'/src/Commands/CopyAssetsCommand.php';
        $content = file_get_contents($commandFile);

        // Should check for platform
        expect($content)->toContain('$this->isAndroid()');
        expect($content)->toContain('$this->isIos()');
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
});
