<?php

use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\TenantController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']))->name('health');

Route::middleware('auth.api')->group(function () {
    Route::post('/tenants', [TenantController::class, 'store'])->middleware('ability:tenants:manage');

    Route::middleware('tenant')->group(function () {
        Route::get('/me', MeController::class);
    });
});
