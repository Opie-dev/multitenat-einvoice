<?php

use App\Http\Controllers\Web\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'billplz-einvoice-engine', 'docs' => '/v1/health']));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login/link', [AuthController::class, 'sendLink'])
        ->middleware(['throttle:login-link-email', 'throttle:login-link-ip'])
        ->name('login.link');
    Route::get('/login/{token}', [AuthController::class, 'consume'])->name('login.consume');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
