<?php

use App\Http\Controllers\ApiAuthController;
use App\Http\Controllers\UsageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [ApiAuthController::class, 'login'])
    ->middleware('throttle:api')
    ->name('api.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [ApiAuthController::class, 'logout'])
        ->name('api.logout');

    Route::post('/usage', [UsageController::class, 'store'])
        ->middleware('throttle:usage')
        ->name('api.usage.store');

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
