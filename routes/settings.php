<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::redirect('settings', 'settings/profile');

Route::livewire('settings/profile', 'pages::settings.profile')->middleware(['auth'])->name('profile.edit');
Route::livewire('settings/device', 'pages::settings.device')->middleware(['auth'])->name('device.edit');

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
