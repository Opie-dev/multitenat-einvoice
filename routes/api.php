<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\BuyerController;
use App\Http\Controllers\Api\V1\IssuerCertificateController;
use App\Http\Controllers\Api\V1\IssuerController;
use App\Http\Controllers\Api\V1\IssuerCredentialsController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\TenantController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']))->name('health');

Route::middleware('auth.api')->group(function () {
    Route::post('/tenants', [TenantController::class, 'store'])->middleware('ability:tenants:manage');
    Route::get('/reference/{set}', [ReferenceController::class, 'show']);

    Route::middleware('tenant')->group(function () {
        Route::get('/me', MeController::class);

        Route::middleware('ability:issuers:manage')->group(function () {
            Route::get('/api-keys', [ApiKeyController::class, 'index']);
            Route::post('/api-keys', [ApiKeyController::class, 'store']);
            Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);
        });

        Route::middleware('ability:read')->group(function () {
            Route::get('/issuers', [IssuerController::class, 'index']);
            Route::get('/issuers/{issuer}', [IssuerController::class, 'show']);
        });
        Route::middleware('ability:issuers:manage')->group(function () {
            Route::post('/issuers', [IssuerController::class, 'store']);
            Route::patch('/issuers/{issuer}', [IssuerController::class, 'update']);
            Route::put('/issuers/{issuer}/credentials', [IssuerCredentialsController::class, 'update']);
            Route::put('/issuers/{issuer}/certificate', [IssuerCertificateController::class, 'update']);
        });

        Route::middleware('ability:read')->group(function () {
            Route::get('/buyers', [BuyerController::class, 'index']);
            Route::get('/buyers/{buyer}', [BuyerController::class, 'show']);
        });
        Route::middleware('ability:documents:write')->group(function () {
            Route::post('/buyers', [BuyerController::class, 'store']);
            Route::patch('/buyers/{buyer}', [BuyerController::class, 'update']);
        });
    });
});
