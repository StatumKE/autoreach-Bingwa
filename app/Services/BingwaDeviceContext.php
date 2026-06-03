<?php

namespace App\Services;

use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Native\Mobile\Facades\Device;

class BingwaDeviceContext
{
    public const HARDWARE_ID_CACHE_KEY = 'autoreach.hardware_id';

    public function hardwareId(): ?string
    {
        $cachedHardwareId = Cache::get(self::HARDWARE_ID_CACHE_KEY);

        if ($this->isValidHardwareId($cachedHardwareId)) {
            return $cachedHardwareId;
        }

        $hardwareId = Device::getId();

        if ($this->isValidHardwareId($hardwareId)) {
            Cache::forever(self::HARDWARE_ID_CACHE_KEY, $hardwareId);

            return $hardwareId;
        }

        return null;
    }

    public function hardwareIdOrCreate(): string
    {
        $hardwareId = $this->hardwareId();

        if ($hardwareId !== null) {
            return $hardwareId;
        }

        $generatedHardwareId = (string) Str::uuid();
        Cache::forever(self::HARDWARE_ID_CACHE_KEY, $generatedHardwareId);

        return $generatedHardwareId;
    }

    public function registration(): ?BingwaDeviceRegistration
    {
        $hardwareId = $this->hardwareId();

        if ($hardwareId === null) {
            return null;
        }

        return BingwaDeviceRegistration::query()
            ->with('user')
            ->where('hardware_id', $hardwareId)
            ->first();
    }

    public function user(): ?User
    {
        return $this->registration()?->user;
    }

    private function isValidHardwareId(mixed $hardwareId): bool
    {
        return is_string($hardwareId)
            && $hardwareId !== ''
            && $hardwareId !== 'unknown';
    }
}
