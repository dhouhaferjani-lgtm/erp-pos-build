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
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])
            ->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])
            ->whereUuid('id')
            ->name('notifications.read');
    });
