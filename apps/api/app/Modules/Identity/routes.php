<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Controllers\AuthController;
use App\Modules\Identity\Presentation\Controllers\RoleController;
use App\Modules\Identity\Presentation\Controllers\UserController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity Module API Routes
|--------------------------------------------------------------------------
|
| Authentication and user management routes.
|
*/

Route::prefix('api/v1/auth')->middleware('web')->group(function () {
    // Public routes with rate limiting
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:register')
        ->name('auth.register');
    Route::post('check-email', [AuthController::class, 'checkEmail'])
        ->middleware('throttle:login')
        ->name('auth.check-email');
    Route::post('verify-email', [AuthController::class, 'verifyEmail'])
        ->middleware('throttle:email-verification')
        ->name('auth.verify-email');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:password-reset')
        ->name('auth.forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('auth.reset-password');

    // Protected routes (no company context required for auth endpoints)
    Route::middleware(['auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');
        Route::post('resend-verification', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:email-verification')
            ->name('auth.resend-verification');
    });
});

// Routes that don't require company context
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Current user routes (no company context - used to get available companies)
    Route::get('user/companies', [UserController::class, 'companies'])->name('user.companies');
});

// Routes that require company context
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Role management (requires roles.view or roles.manage permission)
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{id}', [RoleController::class, 'show'])->name('roles.show');
    Route::patch('roles/{id}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('roles/{id}', [RoleController::class, 'destroy'])->name('roles.destroy');
    Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');

    // User management (requires users.* permissions)
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{id}', [UserController::class, 'show'])->name('users.show');
    Route::patch('users/{id}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('users/{id}/activate', [UserController::class, 'activate'])->name('users.activate');
    Route::post('users/{id}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('users/{id}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    Route::patch('users/{id}/pos-pin', [UserController::class, 'setPosPin'])->name('users.pos-pin');

    // User role assignment (requires users.assign-roles permission)
    Route::get('users/{userId}/roles', [RoleController::class, 'userRoles'])->name('users.roles');
    Route::post('users/{userId}/roles', [RoleController::class, 'assignRole'])->name('users.roles.assign');
    Route::delete('users/{userId}/roles', [RoleController::class, 'removeRole'])->name('users.roles.remove');
});
