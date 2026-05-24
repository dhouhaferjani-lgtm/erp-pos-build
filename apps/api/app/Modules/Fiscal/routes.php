<?php

declare(strict_types=1);

use App\Modules\Fiscal\Presentation\Controllers\FiscalEventIngestionController;
use App\Modules\Fiscal\Presentation\Controllers\ParseFailureResolutionController;
use App\Modules\Fiscal\Presentation\Controllers\QuarantineBestEffortParseController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

/**
 * Fiscal Module Routes (Task 20, plan §1484).
 *
 * Single endpoint — `POST /api/v1/pos/sync/fiscal-events` — accepts a batch
 * of device-authored fiscal-event wire envelopes for the server-side
 * verify-then-insert flow (spec v7 §7.1 + §7.2).
 *
 * Middleware tuple matches every existing POS route surface
 * (`apps/api/app/Modules/POS/routes.php:30`,
 * `apps/api/app/Modules/POS/routes_orders.php:15`) verbatim. This endpoint
 * writes fiscal chain truth — any weaker tenant boundary than the rest of
 * POS could admit envelopes under the wrong tenant context and corrupt
 * the chain.
 */
Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])
    ->group(function (): void {
        Route::post('/pos/sync/fiscal-events', [FiscalEventIngestionController::class, 'store']);
        Route::post('/fiscal/quarantine/{id}/best-effort-parse', [QuarantineBestEffortParseController::class, 'store'])
            ->middleware('can:fiscal.events.resolve_quarantine');
        Route::post('/fiscal/events/{id}/resolve-parse-failure', [ParseFailureResolutionController::class, 'store'])
            ->middleware('can:fiscal.events.resolve_quarantine');
    });
