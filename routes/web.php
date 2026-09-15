<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('merchants/{merchant}/dashboard', [DashboardController::class, 'show'])
    ->middleware(['auth', 'verified'])
    ->name('merchants.dashboard');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__.'/settings.php';
