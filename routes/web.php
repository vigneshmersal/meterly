<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('usage', [UsageController::class, 'store'])
    ->middleware(['auth', 'throttle:usage'])
    ->name('usage.store');

Route::get('merchants/{merchant}/dashboard', [DashboardController::class, 'show'])
    ->middleware(['auth', 'verified'])
    ->name('merchants.dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
