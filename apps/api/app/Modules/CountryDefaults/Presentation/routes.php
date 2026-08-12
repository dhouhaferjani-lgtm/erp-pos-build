<?php

declare(strict_types=1);

use App\Modules\CountryDefaults\Presentation\Controllers\AssignmentController;
use App\Modules\CountryDefaults\Presentation\Controllers\DefaultsEditorController;
use App\Modules\CountryDefaults\Presentation\Controllers\TemplateController;
use App\Modules\CountryDefaults\Presentation\Controllers\TemplateRowController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/country-defaults')
    ->middleware([
        'api',
        'auth:sanctum-admin',
        'central_admin',
        'central_admin_role:super_admin,defaults_editor',
        'throttle:admin-sensitive',
    ])
    ->group(function (): void {
        Route::get('templates', [TemplateController::class, 'index'])->name('admin.country-defaults.templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->name('admin.country-defaults.templates.store');
        Route::post('templates/{id}/clone', [TemplateController::class, 'clone'])->name('admin.country-defaults.templates.clone');
        Route::get('templates/{id}', [TemplateController::class, 'show'])->name('admin.country-defaults.templates.show');
        Route::put('templates/{id}', [TemplateController::class, 'update'])->name('admin.country-defaults.templates.update');
        Route::delete('templates/{id}', [TemplateController::class, 'destroy'])->whereUuid('id')->name('admin.country-defaults.templates.destroy');
        Route::put('templates/{id}/rows', [TemplateRowController::class, 'update'])->name('admin.country-defaults.templates.rows.update');
        Route::get('templates/{id}/validation', [TemplateController::class, 'validation'])->name('admin.country-defaults.templates.validation');
        Route::post('templates/{id}/publish', [TemplateController::class, 'publish'])->name('admin.country-defaults.templates.publish');
        Route::post('templates/{id}/archive', [TemplateController::class, 'archive'])->whereUuid('id')->name('admin.country-defaults.templates.archive');

        Route::get('assignments', [AssignmentController::class, 'index'])->name('admin.country-defaults.assignments.index');
        Route::put('assignments/{countryCode}', [AssignmentController::class, 'update'])->name('admin.country-defaults.assignments.update');

        Route::middleware('central_admin_role:super_admin')->prefix('editors')->group(function (): void {
            Route::get('/', [DefaultsEditorController::class, 'index'])->name('admin.country-defaults.editors.index');
            Route::post('/', [DefaultsEditorController::class, 'store'])->name('admin.country-defaults.editors.store');
            Route::put('/', [DefaultsEditorController::class, 'update'])->name('admin.country-defaults.editors.update');
            Route::post('{editor}/reset-credentials', [DefaultsEditorController::class, 'resetCredentials'])->name('admin.country-defaults.editors.reset-credentials');
        });
    });
