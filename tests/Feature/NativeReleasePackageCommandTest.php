<?php

use App\Console\Commands\NativeReleasePackageCommand;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Tester\CommandTester;

it('bumps the NativePHP version code before packaging', function () {
    $envPath = tempnam(sys_get_temp_dir(), 'bingwa-native-env-');
    file_put_contents($envPath, "APP_NAME=Bingwa\nNATIVEPHP_APP_VERSION_CODE=41\n");

    Artisan::shouldReceive('call')
        ->once()
        ->with('native:package', Mockery::on(function (array $parameters): bool {
            return ($parameters['platform'] ?? null) === 'android'
                && ($parameters['--build-type'] ?? null) === 'release';
        }))
        ->andReturn(0);

    $command = new NativeReleasePackageCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute([
        'platform' => 'android',
        '--env-path' => $envPath,
    ]);

    expect($tester->getDisplay())->toContain('NATIVEPHP_APP_VERSION_CODE bumped from 41 to 42.');
    expect(file_get_contents($envPath))->toContain('NATIVEPHP_APP_VERSION_CODE=42');

    @unlink($envPath);
});
