<?php

use App\Http\Controllers\Api\NativeRuntimeTickController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:240,1')->post('/native/runtime/tick', NativeRuntimeTickController::class);
});
