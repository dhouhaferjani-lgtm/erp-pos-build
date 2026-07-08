<?php

declare(strict_types=1);

use App\Modules\DocumentIngestion\Presentation\Controllers\DocumentIngestionController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware([
    'api',
    'auth:sanctum',
    SetPermissionsTeam::class,
    EnforceTokenTenantClaim::class,
])->group(function (): void {
    Route::post('/document-ingestions', [DocumentIngestionController::class, 'store'])
        ->middleware('can:document-ingestions.create')
        ->name('document-ingestions.store');

    Route::get('/document-ingestions', [DocumentIngestionController::class, 'index'])
        ->middleware('can:document-ingestions.view')
        ->name('document-ingestions.index');

    Route::get('/document-ingestions/{id}', [DocumentIngestionController::class, 'show'])
        ->middleware('can:document-ingestions.view')
        ->whereUuid('id')
        ->name('document-ingestions.show');

    Route::post('/document-ingestions/{id}/extract', [DocumentIngestionController::class, 'extract'])
        ->middleware(['can:document-ingestions.create', 'throttle:6,1'])
        ->whereUuid('id')
        ->name('document-ingestions.extract');

    Route::post('/document-ingestions/{id}/reject', [DocumentIngestionController::class, 'reject'])
        ->middleware('can:document-ingestions.reject')
        ->whereUuid('id')
        ->name('document-ingestions.reject');

    Route::post('/document-ingestions/{id}/commit', [DocumentIngestionController::class, 'commit'])
        ->middleware('can:document-ingestions.commit')
        ->whereUuid('id')
        ->name('document-ingestions.commit');
});
