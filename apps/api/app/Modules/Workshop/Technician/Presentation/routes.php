<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Workshop\Technician\Presentation\Controllers\TechnicianCertificationController;
use App\Modules\Workshop\Technician\Presentation\Controllers\TechnicianProfileController;
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
| Middleware stack MUST be `['api', 'auth:sanctum', SetPermissionsTeam::class]`
| per CLAUDE.md rule #12.
|
*/

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Workshop'])
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
    });
