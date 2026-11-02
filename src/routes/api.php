<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\AdminRoleController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);

    Route::prefix('admin')->group(function () {
        // 不需要登入
        Route::post('/login', [AuthController::class, 'login']);
        // 需要管理員 Sanctum Bearer Token；停用帳號仍可登出。
        Route::middleware(['auth:sanctum', 'admin.token'])->group(function () {
            Route::get('/audit-logs', [AuditLogController::class, 'index'])
                ->middleware(['admin.active', 'admin.permission:audit-logs.view']);
            Route::get('/admins/{id}/roles', [AdminRoleController::class, 'index'])
                ->middleware(['admin.active', 'admin.permission:admins.roles.read']);
            Route::post('/admins/{id}/roles', [AdminRoleController::class, 'store'])
                ->middleware(['admin.active', 'admin.permission:admins.roles.assign']);
            Route::delete('/admins/{id}/roles/{role}', [AdminRoleController::class, 'destroy'])
                ->middleware(['admin.active', 'admin.permission:admins.roles.revoke']);
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
