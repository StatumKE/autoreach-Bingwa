<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('app:native-release-package {platform=android : Target platform (android or ios)} {--build-type=release : NativePHP build type} {--env-path= : Optional .env file path}')]
#[Description('Bump the NativePHP version code and run native:package for the selected platform.')]
class NativeReleasePackageCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $envPath = $this->option('env-path') ?: base_path('.env');

        if (! File::exists($envPath)) {
            $this->error("Unable to find .env file at {$envPath}.");

            return self::FAILURE;
        }

        $contents = File::get($envPath);
        $pattern = '/^NATIVEPHP_APP_VERSION_CODE=(.*)$/m';

        if (! preg_match($pattern, $contents, $matches)) {
            $this->error('NATIVEPHP_APP_VERSION_CODE was not found in the env file.');

            return self::FAILURE;
        }

        $current = (int) trim($matches[1], "\"'");
        $next = $current + 1;

        $updated = preg_replace($pattern, 'NATIVEPHP_APP_VERSION_CODE='.$next, $contents);
        if (! is_string($updated)) {
            throw new RuntimeException('Failed to update the env file.');
        }

        File::put($envPath, $updated);

        $platform = (string) $this->argument('platform');
        $buildType = (string) $this->option('build-type');

        $this->info("NATIVEPHP_APP_VERSION_CODE bumped from {$current} to {$next}.");

        return Artisan::call('native:package', [
            'platform' => $platform,
            '--build-type' => $buildType,
        ]);
    }
}
