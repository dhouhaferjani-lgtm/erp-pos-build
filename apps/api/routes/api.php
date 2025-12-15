<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\SuperAdminAuthController;
use App\Http\Controllers\Api\Admin\SuperAdminController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {
    // Public routes
    Route::get('/countries', [CountryController::class, 'index']);
    Route::get('/countries/{code}', [CountryController::class, 'show']);

    // Super admin authentication routes
    Route::prefix('admin/auth')->group(function (): void {
        // Login is public but rate-limited
        Route::post('/login', [SuperAdminAuthController::class, 'login'])
            ->middleware('throttle:admin-login');

        // Logout and profile require super admin authentication
        Route::middleware(['auth:sanctum', 'super_admin'])->group(function (): void {
            Route::post('/logout', [SuperAdminAuthController::class, 'logout']);
            Route::get('/me', [SuperAdminAuthController::class, 'me']);
        });
    });

    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/subscription', [SubscriptionController::class, 'show']);
    });

    // Super admin routes - require authenticated super admin with rate limiting
    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'super_admin', 'throttle:admin-sensitive'])
        ->group(function (): void {
            Route::get('/dashboard', [SuperAdminController::class, 'dashboard']);
            Route::get('/tenants', [SuperAdminController::class, 'tenants']);
            Route::get('/tenants/{id}', [SuperAdminController::class, 'showTenant']);
            Route::post('/tenants/{id}/extend-trial', [SuperAdminController::class, 'extendTrial']);
            Route::post('/tenants/{id}/change-plan', [SuperAdminController::class, 'changePlan']);
            Route::post('/tenants/{id}/suspend', [SuperAdminController::class, 'suspendTenant']);
            Route::post('/tenants/{id}/activate', [SuperAdminController::class, 'activateTenant']);
            Route::get('/audit-logs', [SuperAdminController::class, 'auditLogs']);
        });
});
