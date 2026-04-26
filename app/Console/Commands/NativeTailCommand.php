<?php

namespace App\Console\Commands;

use Native\Mobile\Commands\TailCommand as BaseNativeTailCommand;
use Symfony\Component\Process\Process;

class NativeTailCommand extends BaseNativeTailCommand
{
    public function handle(): void
    {
        $appId = config('nativephp.app_id');

        if (empty($appId)) {
            $this->error('🚫 NATIVEPHP_APP_ID is not set');
            $this->line('Please add a NATIVEPHP_APP_ID to your .env file (e.g. com.example.myapp).');

            return;
        }

        $this->tailAndroid($appId);
    }

    protected function tailAndroid(string $appId): void
    {
        $this->info("🤖 Tailing Android logs for app: {$appId}");
        $this->line("Press Ctrl+C to stop...\n");

        $logPath = $this->androidLogPath();

        if (! $this->prepareAndroidLogFile($appId, $logPath)) {
            $this->line('Make sure the app is installed, the device is connected, and the package can be opened with `run-as`.');

            return;
        }

        $process = new Process($this->buildTailCommand($appId, $logPath));
        $process->setTimeout(null);

        try {
            $process->start();

            foreach ($process as $type => $data) {
                $this->output->write($data, false);
            }
        } catch (\Exception $e) {
            $this->error("❌ Error running tail command: {$e->getMessage()}");
            $this->line('Make sure:');
            $this->line('• ADB is installed and in your PATH');
            $this->line('• An Android device/emulator is connected');
            $this->line('• The app is installed and running');
        }
    }

    protected function androidLogPath(): string
    {
        return 'app_storage/persisted_data/storage/logs/laravel.log';
    }

    protected function prepareAndroidLogFile(string $appId, string $logPath): bool
    {
        $prepareDirectoryProcess = new Process($this->buildPrepareLogCommand($appId, $logPath));
        $prepareDirectoryProcess->setTimeout(null);
        $prepareDirectoryProcess->run();

        if (! $prepareDirectoryProcess->isSuccessful()) {
            $errorOutput = $prepareDirectoryProcess->getErrorOutput() ?: $prepareDirectoryProcess->getOutput();
            $this->error('❌ Unable to prepare Android log directory: '.$errorOutput);

            return false;
        }

        $prepareFileProcess = new Process($this->buildTouchLogCommand($appId, $logPath));
        $prepareFileProcess->setTimeout(null);
        $prepareFileProcess->run();

        if ($prepareFileProcess->isSuccessful()) {
            return true;
        }

        $errorOutput = $prepareFileProcess->getErrorOutput() ?: $prepareFileProcess->getOutput();
        $this->error('❌ Unable to prepare Android log file: '.$errorOutput);

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function buildPrepareLogCommand(string $appId, string $logPath): array
    {
        return [
            'adb', 'shell', 'run-as', $appId, 'mkdir', '-p', '/data/data/'.$appId.'/'.dirname($logPath),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function buildTouchLogCommand(string $appId, string $logPath): array
    {
        return [
            'adb', 'shell', 'run-as', $appId, 'touch', '/data/data/'.$appId.'/'.$logPath,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function buildTailCommand(string $appId, string $logPath): array
    {
        return [
            'adb', 'shell', 'run-as', $appId, 'tail', '-f',
            $logPath,
        ];
    }
}
