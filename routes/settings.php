<?php

use App\Http\Controllers\Settings\DeviceSettingsController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::redirect('settings', 'settings/profile');

Route::livewire('settings/profile', 'pages::settings.profile')->middleware(['auth'])->name('profile.edit');
Route::get('settings/device', [DeviceSettingsController::class, 'edit'])->middleware(['auth'])->name('device.edit');
Route::get('settings/device/hardware', fn () => redirect()->route('device.edit'))->middleware(['auth'])->name('device.hardware.fallback');
Route::get('settings/device/hardware/{primaryTransactionSim}/{smsAutoReplySim}', fn () => redirect()->route('device.edit'))->middleware(['auth'])->name('device.hardware.path.fallback');
Route::post('settings/device', [DeviceSettingsController::class, 'updateIdentity'])->middleware(['auth'])->name('device.identity.update');
Route::post('settings/device/hardware/{primaryTransactionSim}/{smsAutoReplySim}', [DeviceSettingsController::class, 'updateHardware'])->middleware(['auth'])->name('device.hardware.update.path');
Route::post('settings/device/hardware', [DeviceSettingsController::class, 'updateHardware'])->middleware(['auth'])->name('device.hardware.update');
Route::post('settings/device/technical', [DeviceSettingsController::class, 'updateTechnical'])->middleware(['auth'])->name('device.technical.update');
Route::post('settings/device/permissions', [DeviceSettingsController::class, 'requestPermissions'])->middleware(['auth'])->name('device.permissions');

Route::livewire('settings/appearance', 'pages::settings.appearance')->middleware(['auth', 'verified'])->name('appearance.edit');

Route::livewire('settings/security', 'pages::settings.security')
    ->middleware(array_merge(['auth', 'verified'],
        when(
            Features::canManageTwoFactorAuthentication()
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
            ['password.confirm'],
            [],
        )
    ))
    ->name('security.edit');
