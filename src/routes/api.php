<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\AdminRoleController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\PlatformController;
use App\Http\Controllers\Api\V1\Admin\PlayerController;
use App\Http\Controllers\Api\V1\Admin\PlatformKeyController;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);

    Route::prefix('admin')->group(function () {
        // 不需要登入
        Route::post('/login', [AuthController::class, 'login']);
        // 需要管理員 Sanctum Bearer Token；停用帳號仍可登出。
        Route::middleware(['auth:sanctum', 'admin.token'])->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->middleware('admin.active');
            Route::post('/logout', [AuthController::class, 'logout']);

            Route::get('/admins', [AdminController::class, 'index'])
                ->middleware(['admin.active', 'admin.permission:admins.read']);
            Route::post('/admins', [AdminController::class, 'store'])
                ->middleware(['admin.active', 'admin.permission:admins.create']);
            Route::get('/admins/{id}', [AdminController::class, 'show'])
                ->middleware(['admin.active', 'admin.permission:admins.read']);
            Route::put('/admins/{id}', [AdminController::class, 'update'])
                ->middleware(['admin.active', 'admin.permission:admins.update']);

            Route::get('/admins/{id}/roles', [AdminRoleController::class, 'index'])
                ->middleware(['admin.active', 'admin.permission:admins.roles.read']);
            Route::post('/admins/{id}/roles', [AdminRoleController::class, 'store'])
                ->middleware(['admin.active', 'admin.permission:admins.roles.assign']);
            Route::delete('/admins/{id}/roles/{role}', [AdminRoleController::class, 'destroy'])
                ->middleware(['admin.active', 'admin.permission:admins.roles.revoke']);

            Route::get('/audit-logs', [AuditLogController::class, 'index'])
                ->middleware(['admin.active', 'admin.permission:audit-logs.view']);

            Route::get('/platforms', [PlatformController::class, 'index'])
                ->middleware(['admin.active', 'admin.permission:platforms.view']);
            Route::get('/platforms/{id}', [PlatformController::class, 'show'])
                ->middleware(['admin.active', 'admin.permission:platforms.view']);
            Route::post('/platforms', [PlatformController::class, 'store'])
                ->middleware(['admin.active', 'admin.permission:platforms.manage']);
            Route::put('/platforms/{id}', [PlatformController::class, 'update'])
                ->middleware(['admin.active', 'admin.permission:platforms.manage']);
            Route::patch('/platforms/{id}/status', [PlatformController::class, 'status'])
                ->middleware(['admin.active', 'admin.permission:platforms.manage']);

            Route::get('/players', [PlayerController::class, 'index'])
                ->middleware(['admin.active', 'admin.permission:players.view']);
            Route::get('/players/{id}', [PlayerController::class, 'show'])
                ->middleware(['admin.active', 'admin.permission:players.view']);
            Route::patch('/players/{id}/status', [PlayerController::class, 'status'])
                ->middleware(['admin.active', 'admin.permission:players.manage']);

            Route::get('/platforms/{id}/keys', [PlatformKeyController::class, 'index'])
                ->middleware(['admin.active', 'admin.permission:platforms.keys.view']);
            Route::post('/platforms/{id}/keys', [PlatformKeyController::class, 'store'])
                ->middleware(['admin.active', 'admin.permission:platforms.keys.register']);
            Route::patch('/platforms/{id}/keys/{keyId}/status', [PlatformKeyController::class, 'status'])
                ->middleware(['admin.active', 'admin.permission:platforms.keys.manage']);
            Route::post('/platforms/{id}/keys/{keyId}/revoke', [PlatformKeyController::class, 'revoke'])
                ->middleware(['admin.active', 'admin.permission:platforms.keys.revoke']);
            Route::get('/platforms/{id}/keys/{keyId}', [PlatformKeyController::class, 'show'])
                ->middleware(['admin.active', 'admin.permission:platforms.keys.view']);
        });
    });

    Route::prefix('platform')->middleware('platform.jwt')->group(function () {
        Route::post('/players/resolve', \App\Http\Controllers\Api\V1\Platform\PlayerResolveController::class)
            ->middleware('platform.resolve.limit')->name('platform.players.resolve');
    });
});
