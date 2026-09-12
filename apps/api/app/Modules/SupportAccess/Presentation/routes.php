<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\SupportAccess\Presentation\Controllers\AdminElevationController;
use App\Modules\SupportAccess\Presentation\Controllers\AdminGrantController;
use App\Modules\SupportAccess\Presentation\Controllers\AdminSessionController;
use App\Modules\SupportAccess\Presentation\Controllers\AdminSupportAccessQueryController;
use App\Modules\SupportAccess\Presentation\Controllers\AdminTenantSensitivityController;
use App\Modules\SupportAccess\Presentation\Controllers\TenantGrantController;
use App\Modules\SupportAccess\Presentation\Controllers\TenantSessionController;
use App\Modules\SupportAccess\Presentation\Controllers\TenantSupportAccessQueryController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/impersonation')
    ->middleware(['api', 'auth:sanctum-admin', SetPermissionsTeam::class])
    ->group(function (): void {
        Route::get('/', [AdminSupportAccessQueryController::class, 'index'])
            ->middleware('central_admin_role:super_admin,support_approver')
            ->name('admin.impersonation.index');
        Route::post('requests', [AdminGrantController::class, 'store'])
            ->middleware('central_admin_role:super_admin')
            ->name('admin.impersonation.requests.store');
        Route::post('requests/{grant}/approve', [AdminGrantController::class, 'approveSecond'])
            ->middleware('central_admin_role:support_approver')
            ->name('admin.impersonation.requests.approve');
        Route::post('requests/{grant}/revoke', [AdminGrantController::class, 'revoke'])
            ->middleware('central_admin_role:super_admin')
            ->name('admin.impersonation.requests.revoke');
        Route::post('sessions', [AdminSessionController::class, 'store'])
            ->middleware('central_admin_role:super_admin')
            ->name('admin.impersonation.sessions.store');
        Route::post('sessions/{session}/elevations', [AdminElevationController::class, 'store'])
            ->middleware('central_admin_role:super_admin')
            ->name('admin.impersonation.elevations.store');
        Route::post('elevations/{elevation}/approve', [AdminElevationController::class, 'approve'])
            ->middleware('central_admin_role:support_approver')
            ->name('admin.impersonation.elevations.approve');
        Route::post('elevations/{elevation}/reject', [AdminElevationController::class, 'reject'])
            ->middleware('central_admin_role:support_approver')
            ->name('admin.impersonation.elevations.reject');
        Route::patch('tenants/{tenant}/sensitivity', [AdminTenantSensitivityController::class, 'update'])
            ->middleware('central_admin_role:super_admin')
            ->name('admin.impersonation.tenants.sensitivity.update');
    });

Route::prefix('api/v1/support-access')
    ->middleware([
        'api',
        'auth:sanctum',
        SetPermissionsTeam::class,
        EnforceTokenTenantClaim::class,
    ])
    ->group(function (): void {
        // Self-service: a principal ending their OWN impersonation session. The
        // {session} parameter is self-addressable (SELF_ADDRESSABLE_PARAMETERS).
        Route::post('sessions/{session}/exit', [TenantSessionController::class, 'exit'])
            ->middleware('authz.self')
            ->name('support-access.sessions.exit');
    });

Route::prefix('api/v1/support-access')
    ->middleware([
        'api',
        'auth:sanctum',
        SetPermissionsTeam::class,
        EnforceTokenTenantClaim::class,
        'can:support-access.view',
    ])
    ->group(function (): void {
        Route::get('/', [TenantSupportAccessQueryController::class, 'index'])
            ->name('support-access.index');
        Route::get('log', [TenantSupportAccessQueryController::class, 'index'])
            ->name('support-access.log');
    });

Route::prefix('api/v1/support-access')
    ->middleware([
        'api',
        'auth:sanctum',
        SetPermissionsTeam::class,
        EnforceTokenTenantClaim::class,
        'can:support-access.manage',
    ])
    ->group(function (): void {
        Route::post('grants', [TenantGrantController::class, 'store'])
            ->name('support-access.grants.store');
        Route::post('requests/{grant}/approve', [TenantGrantController::class, 'approve'])
            ->name('support-access.requests.approve');
        Route::post('requests/{grant}/reject', [TenantGrantController::class, 'reject'])
            ->name('support-access.requests.reject');
        Route::post('grants/{grant}/revoke', [TenantGrantController::class, 'revoke'])
            ->name('support-access.grants.revoke');
    });
