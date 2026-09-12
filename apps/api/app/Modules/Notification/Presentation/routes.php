<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Notification\Presentation\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notification Module API Routes
|--------------------------------------------------------------------------
|
| The authenticated user's notification inbox and read-state operations.
|
*/

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])
    ->group(function (): void {
        Route::get('notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])
            ->name('notifications.unread-count');
        // Self-service: both act on the caller's own inbox. `markRead` looks the
        // notification up scoped to $request->user(), which is why its {id} is a
        // NAMED exemption in SelfServiceRouteRegistry::SHAPE_EXEMPTIONS rather
        // than a hole in the shape rule.
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])
            ->middleware('authz.self')
            ->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])
            ->middleware('authz.self')
            ->whereUuid('id')
            ->name('notifications.read');
    });
