<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\AdminController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);

    Route::prefix('admin')->group(function () {
        // 不需要登入
        Route::post('/login', [AuthController::class, 'login']);
        // 需要管理員 Sanctum Bearer Token；停用帳號仍可登出。
        Route::middleware(['auth:sanctum', 'admin.token'])->group(function () {
            Route::get('/admins', [AdminController::class, 'index'])
                ->middleware(['admin.active', 'admin.permission:admins.read']);
            Route::post('/admins', [AdminController::class, 'store'])
                ->middleware(['admin.active', 'admin.permission:admins.create']);
            Route::get('/admins/{id}', [AdminController::class, 'show'])
                ->middleware(['admin.active', 'admin.permission:admins.read']);
            Route::put('/admins/{id}', [AdminController::class, 'update'])
                ->middleware(['admin.active', 'admin.permission:admins.update']);
            Route::get('/me', [AuthController::class, 'me'])->middleware('admin.active');
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });
});
