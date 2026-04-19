<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('offers', 'offers')->name('offers');
    Route::livewire('transactions', 'transactions')->name('transactions');
    Route::livewire('plans', 'plans')->name('plans');
    Route::livewire('quick-dials', 'quick-dials')->name('quick-dials');
});

require __DIR__.'/settings.php';
