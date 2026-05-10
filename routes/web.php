<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('offers', 'offers')->name('offers');
    Route::livewire('transactions', 'transactions')->name('transactions');
    Route::livewire('plans', 'plans')->name('plans');
    Route::livewire('quick-dials', 'quick-dials')->name('quick-dials');
    Route::livewire('auto-renewals', 'auto-renewals')->name('auto-renewals');
    Route::livewire('auto-replies', 'auto-replies')->name('auto-replies');
});

// Permission onboarding — auth only, no email-verification gate
Route::middleware(['auth'])->group(function () {
    Route::livewire('setup', 'setup')->name('setup');
});

require __DIR__.'/settings.php';
