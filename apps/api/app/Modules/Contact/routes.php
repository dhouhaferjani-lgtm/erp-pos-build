<?php

declare(strict_types=1);

use App\Modules\Contact\Presentation\Controllers\ContactController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Contact Module API Routes
|--------------------------------------------------------------------------
|
| Contact management routes for CRM contact operations.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    Route::get('contacts', [ContactController::class, 'index'])
        ->middleware('can:contacts.view')
        ->name('contacts.index');

    Route::post('contacts', [ContactController::class, 'store'])
        ->middleware('can:contacts.create')
        ->name('contacts.store');

    Route::get('contacts/{id}', [ContactController::class, 'show'])
        ->middleware('can:contacts.view')
        ->name('contacts.show');

    Route::patch('contacts/{id}', [ContactController::class, 'update'])
        ->middleware('can:contacts.update')
        ->name('contacts.update');

    Route::delete('contacts/{id}', [ContactController::class, 'destroy'])
        ->middleware('can:contacts.delete')
        ->name('contacts.destroy');

    Route::post('contacts/{id}/link-party', [ContactController::class, 'linkParty'])
        ->middleware('can:contacts.update')
        ->name('contacts.link-party');

    Route::delete('contacts/{id}/unlink-party/{partyId}', [ContactController::class, 'unlinkParty'])
        ->middleware('can:contacts.update')
        ->name('contacts.unlink-party');
});
