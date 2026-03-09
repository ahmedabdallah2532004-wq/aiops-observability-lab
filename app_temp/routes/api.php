<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AIOpsController;
use App\Http\Controllers\MetricsController;

Route::get('/normal', [AIOpsController::class, 'normal'])->name('api.normal');
Route::get('/slow', [AIOpsController::class, 'slow'])->name('api.slow');
Route::get('/error', [AIOpsController::class, 'error'])->name('api.error');
Route::get('/random', [AIOpsController::class, 'random'])->name('api.random');
Route::get('/db', [AIOpsController::class, 'db'])->name('api.db');
Route::post('/validate', [AIOpsController::class, 'validateData'])->name('api.validate');

Route::get('/metrics', [MetricsController::class, 'metrics'])->name('metrics');
