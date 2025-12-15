<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Media\Presentation\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Media Module API Routes
|--------------------------------------------------------------------------
|
| Document attachment management routes.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Attachment configuration (public, authenticated)
    Route::get('attachments/config', [AttachmentController::class, 'config'])
        ->name('attachments.config');

    // Document attachments - nested under documents
    Route::prefix('documents/{document}')->group(function () {
        Route::get('attachments', [AttachmentController::class, 'index'])
            ->middleware('can:documents.view')
            ->name('documents.attachments.index');

        Route::post('attachments', [AttachmentController::class, 'store'])
            ->middleware('can:documents.update')
            ->name('documents.attachments.store');

        Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
            ->middleware('can:documents.view')
            ->name('documents.attachments.download');

        Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])
            ->middleware('can:documents.update')
            ->name('documents.attachments.destroy');
    });
});
