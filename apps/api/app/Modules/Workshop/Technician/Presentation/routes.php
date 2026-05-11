<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Workshop\Technician\Presentation\Controllers\PayrollExportController;
use App\Modules\Workshop\Technician\Presentation\Controllers\TechnicianCertificationController;
use App\Modules\Workshop\Technician\Presentation\Controllers\TechnicianProfileController;
use App\Modules\Workshop\Technician\Presentation\Controllers\TechnicianTimeEntryController;
use App\Modules\Workshop\Technician\Presentation\Controllers\TechnicianTimeOffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workshop / Technician Module API Routes
|--------------------------------------------------------------------------
|
| Read-side endpoints for the Technician submodule (Plan C). Full authoring
| CRUD (create / update / time-off / time-entry) lands in a follow-up patch;
| current scope unblocks Spec B (work-order assignment) and Spec D (scheduler).
|
| Middleware stack MUST be `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`
| per CLAUDE.md rule #12.
|
*/

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Workshop'])
    ->group(function (): void {
        Route::get('/workshop/technicians', [TechnicianProfileController::class, 'index'])
            ->middleware('can:workshop.technicians.view')
            ->name('workshop.technicians.index');

        Route::get('/workshop/technicians/available', [TechnicianProfileController::class, 'available'])
            ->middleware('can:workshop.technicians.view')
            ->name('workshop.technicians.available');

        Route::get('/workshop/technicians/{id}', [TechnicianProfileController::class, 'show'])
            ->middleware('can:workshop.technicians.view')
            ->name('workshop.technicians.show');

        // --- Certifications CRUD (Phase A.4) ---------------------------
        Route::get('/workshop/technicians/{technicianId}/certifications', [TechnicianCertificationController::class, 'index'])
            ->middleware('can:workshop.technicians.view')
            ->name('workshop.technicians.certifications.index');
        Route::post('/workshop/technicians/{technicianId}/certifications', [TechnicianCertificationController::class, 'store'])
            ->middleware('can:workshop.technicians.manage_certifications')
            ->name('workshop.technicians.certifications.store');
        Route::patch('/workshop/technicians/{technicianId}/certifications/{certificationId}', [TechnicianCertificationController::class, 'update'])
            ->middleware('can:workshop.technicians.manage_certifications')
            ->name('workshop.technicians.certifications.update');
        Route::delete('/workshop/technicians/{technicianId}/certifications/{certificationId}', [TechnicianCertificationController::class, 'destroy'])
            ->middleware('can:workshop.technicians.manage_certifications')
            ->name('workshop.technicians.certifications.destroy');

        // --- Time-off CRUD (Phase A.4) ---------------------------------
        // `index` uses no `can:` gate — the controller does a self-view check
        // so technicians can see their own entries even without the view perm.
        Route::get('/workshop/technicians/{technicianId}/time-off', [TechnicianTimeOffController::class, 'index'])
            ->name('workshop.technicians.time-off.index');
        Route::post('/workshop/technicians/{technicianId}/time-off', [TechnicianTimeOffController::class, 'store'])
            ->middleware('can:workshop.technicians.manage_time_off')
            ->name('workshop.technicians.time-off.store');
        Route::patch('/workshop/technicians/{technicianId}/time-off/{timeOffId}', [TechnicianTimeOffController::class, 'update'])
            ->middleware('can:workshop.technicians.manage_time_off')
            ->name('workshop.technicians.time-off.update');
        Route::delete('/workshop/technicians/{technicianId}/time-off/{timeOffId}', [TechnicianTimeOffController::class, 'destroy'])
            ->middleware('can:workshop.technicians.manage_time_off')
            ->name('workshop.technicians.time-off.destroy');

        // --- Time-entries CRUD (Phase A.4) -----------------------------
        // `store` has no route-level `can:` gate because technicians can
        // self-log their own entries (controller enforces "manage OR self").
        // `update` / `destroy` use `can:manage_time_entries`, and the
        // controller additionally rejects writes to entries whose linked
        // work-order is locked (Completed or Invoiced) with 422 / TIME_ENTRY_LOCKED.
        Route::get('/workshop/technicians/{technicianId}/time-entries', [TechnicianTimeEntryController::class, 'index'])
            ->middleware('can:workshop.technicians.view')
            ->name('workshop.technicians.time-entries.index');
        Route::post('/workshop/technicians/{technicianId}/time-entries', [TechnicianTimeEntryController::class, 'store'])
            ->name('workshop.technicians.time-entries.store');
        Route::patch('/workshop/technicians/{technicianId}/time-entries/{timeEntryId}', [TechnicianTimeEntryController::class, 'update'])
            ->middleware('can:workshop.technicians.manage_time_entries')
            ->name('workshop.technicians.time-entries.update');
        Route::delete('/workshop/technicians/{technicianId}/time-entries/{timeEntryId}', [TechnicianTimeEntryController::class, 'destroy'])
            ->middleware('can:workshop.technicians.manage_time_entries')
            ->name('workshop.technicians.time-entries.destroy');

        // --- Payroll exports (Phase A.4, stateless v1) -----------------
        Route::get('/workshop/payroll-exports', [PayrollExportController::class, 'index'])
            ->middleware('can:workshop.payroll.view')
            ->name('workshop.payroll-exports.index');
        Route::post('/workshop/payroll-exports', [PayrollExportController::class, 'generate'])
            ->middleware('can:workshop.payroll.generate')
            ->name('workshop.payroll-exports.generate');
    });
