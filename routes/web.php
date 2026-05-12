<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
})->name('home');

Route::livewire('dashboard', 'dashboard')->middleware(['auth', 'verified'])->name('dashboard');
Route::livewire('offers', 'offers')->middleware(['auth', 'verified'])->name('offers');
Route::livewire('transactions', 'transactions')->middleware(['auth', 'verified'])->name('transactions');
Route::livewire('plans', 'plans')->middleware(['auth', 'verified'])->name('plans');
Route::livewire('quick-dials', 'quick-dials')->middleware(['auth', 'verified'])->name('quick-dials');
Route::livewire('auto-renewals', 'auto-renewals')->middleware(['auth', 'verified'])->name('auto-renewals');
Route::livewire('auto-replies', 'auto-replies')->middleware(['auth', 'verified'])->name('auto-replies');

// Permission onboarding — auth only, no email-verification gate
Route::livewire('setup', 'setup')->middleware(['auth'])->name('setup');

require __DIR__.'/settings.php';
