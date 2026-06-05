<?php

use App\Http\Controllers\Api\NativeRuntimeTickController;
use App\Http\Controllers\Api\UssdCallbackController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:240,1')->post('/native/runtime/tick', NativeRuntimeTickController::class);
    Route::post('/native/ussd/callback', UssdCallbackController::class);
});
