<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Workshop\WorkOrder\Presentation\Controllers\WorkOrderAssignmentController;
use App\Modules\Workshop\WorkOrder\Presentation\Controllers\WorkOrderController;
use App\Modules\Workshop\WorkOrder\Presentation\Controllers\WorkOrderLineController;
use App\Modules\Workshop\WorkOrder\Presentation\Controllers\WorkOrderTransitionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workshop / WorkOrder Module API Routes
|--------------------------------------------------------------------------
|
| Middleware stack is `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`
| per CLAUDE.md rule #12. Authorization is enforced via FormRequest::authorize
| + per-method `can:*` checks in controllers. All endpoints live under
| `/api/v1/workshop/work-orders`.
|
*/

Route::prefix('api/v1/workshop')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Workshop'])
    ->group(function (): void {
        Route::get('work-orders', [WorkOrderController::class, 'index']);
        Route::post('work-orders', [WorkOrderController::class, 'store']);
        Route::get('work-orders/{id}', [WorkOrderController::class, 'show']);
        Route::patch('work-orders/{id}', [WorkOrderController::class, 'update']);

        // Lines
        Route::post('work-orders/{id}/lines', [WorkOrderLineController::class, 'store']);
        Route::post('work-orders/{id}/lines/bundle', [WorkOrderLineController::class, 'storeBundle']);
        Route::patch('work-orders/{id}/lines/{lineId}', [WorkOrderLineController::class, 'update']);
        Route::delete('work-orders/{id}/lines/{lineId}', [WorkOrderLineController::class, 'destroy']);
        Route::put('work-orders/{id}/lines/reorder', [WorkOrderLineController::class, 'reorder']);

        // Assignments
        Route::post('work-orders/{id}/assignments', [WorkOrderAssignmentController::class, 'store']);
        Route::delete('work-orders/{id}/assignments/{assignmentId}', [WorkOrderAssignmentController::class, 'destroy']);
        Route::put('work-orders/{id}/primary-technician', [WorkOrderAssignmentController::class, 'setPrimary']);

        // Transitions
        Route::post('work-orders/{id}/approval', [WorkOrderTransitionController::class, 'approve']);
        Route::post('work-orders/{id}/transition', [WorkOrderTransitionController::class, 'transition']);
        Route::post('work-orders/{id}/cancel', [WorkOrderTransitionController::class, 'cancel']);
        Route::post('work-orders/{id}/complete', [WorkOrderTransitionController::class, 'complete']);
    });
