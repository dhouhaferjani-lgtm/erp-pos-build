<?php

declare(strict_types=1);

use App\Modules\Fiscal\Presentation\Controllers\DeadLetteredProjectionsController;
use App\Modules\Fiscal\Presentation\Controllers\FiscalEventIngestionController;
use App\Modules\Fiscal\Presentation\Controllers\ParseFailureResolutionController;
use App\Modules\Fiscal\Presentation\Controllers\QuarantineBestEffortParseController;
use App\Modules\Fiscal\Presentation\Controllers\QuarantineIncidentResolutionController;
use App\Modules\Fiscal\Presentation\Controllers\RefundCompensationController;
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
        // ES-17 — record that an operator has ADJUDICATED a
        // `fiscal_event_quarantine` incident, so `fiscal:verify-event-chain`
        // stops reporting it (the `whereNull('resolved_at')` predicate inside
        // `VerifyEventChainCommand::reportQuarantineIncidents()` — cited by
        // SYMBOL, not by line: that file has moved this seam three times in
        // this wave alone). Reuses the
        // EXISTING seeded `fiscal.events.resolve_quarantine` that already gates
        // the two sibling quarantine actions above: no new permission, no role
        // seeder change, no `permission:cache-reset` to deploy.
        Route::post('/fiscal/quarantine/{id}/resolve-incident', [QuarantineIncidentResolutionController::class, 'store'])
            ->name('fiscal.quarantine.resolve-incident')
            ->middleware('can:fiscal.events.resolve_quarantine');
        // v3-refund-chain-integration spec §5.2/§17.
        Route::post('/fiscal/refund-compensations', [RefundCompensationController::class, 'store'])
            ->name('fiscal.refund-compensations.store')
            ->middleware('can:fiscal.refunds.manage_dead_letters');
        // v3-refund-chain-integration spec §5.1/§5.2 — read-only operator
        // visibility over dead-lettered projections + ingress-quarantined
        // (canonical-parse-failure) events. Same permission as the write-off
        // action (rule 12's can: pattern) since the two surfaces are used
        // together by the same operator role.
        Route::get('/fiscal/dead-lettered-projections', [DeadLetteredProjectionsController::class, 'index'])
            ->name('fiscal.dead-lettered-projections.index')
            ->middleware('can:fiscal.refunds.manage_dead_letters');
        Route::get('/fiscal/dead-lettered-projections/{fiscalEventId}', [DeadLetteredProjectionsController::class, 'show'])
            ->name('fiscal.dead-lettered-projections.show')
            ->middleware('can:fiscal.refunds.manage_dead_letters');
    });
