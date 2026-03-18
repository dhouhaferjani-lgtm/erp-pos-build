<?php

declare(strict_types=1);

namespace App\Modules\Import\Providers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Import\Services\ImportService;
use App\Modules\Import\Services\MigrationWizardService;
use App\Modules\Import\Services\ValidationEngine;
use App\Shared\Contracts\AccountingServiceInterface;
use App\Shared\Contracts\CompositeItemServiceInterface;
use App\Shared\Contracts\InventoryServiceInterface;
use App\Shared\Contracts\LocationServiceInterface;
use App\Shared\Contracts\PartnerServiceInterface;
use App\Shared\Contracts\ProductServiceInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ValidationEngine::class, function () {
            return new ValidationEngine;
        });

        $this->app->singleton(ImportService::class, function ($app) {
            return new ImportService(
                $app->make(ValidationEngine::class),
                $app->make(CompanyContext::class),
                $app->make(PartnerServiceInterface::class),
                $app->make(ProductServiceInterface::class),
                $app->make(InventoryServiceInterface::class),
                $app->make(LocationServiceInterface::class),
                $app->make(AccountingServiceInterface::class),
                $app->make(CompositeItemServiceInterface::class)
            );
        });

        $this->app->singleton(MigrationWizardService::class, function () {
            return new MigrationWizardService;
        });
    }

    public function boot(): void
    {
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])
            ->prefix('api/v1')
            ->group(function (): void {
                // Import routes
                Route::get('/imports', [\App\Modules\Import\Presentation\Controllers\ImportController::class, 'index']);
                Route::post('/imports', [\App\Modules\Import\Presentation\Controllers\ImportController::class, 'store']);
                Route::get('/imports/{id}', [\App\Modules\Import\Presentation\Controllers\ImportController::class, 'show']);
                Route::get('/imports/{id}/preview', [\App\Modules\Import\Presentation\Controllers\ImportController::class, 'preview']);
                Route::get('/imports/{id}/errors', [\App\Modules\Import\Presentation\Controllers\ImportController::class, 'errors']);
                Route::get('/imports/{id}/error-summary', [\App\Modules\Import\Presentation\Controllers\ImportController::class, 'errorSummary']);
                Route::post('/imports/{id}/execute', [\App\Modules\Import\Presentation\Controllers\ImportController::class, 'execute']);
                Route::get('/imports/{id}/failed-rows.csv', [\App\Modules\Import\Presentation\Controllers\ImportController::class, 'downloadFailedRows']);

                // Migration wizard routes
                Route::get('/migration-wizard/order', [\App\Modules\Import\Presentation\Controllers\MigrationWizardController::class, 'order']);
                Route::get('/migration-wizard/dependencies/{type}', [\App\Modules\Import\Presentation\Controllers\MigrationWizardController::class, 'dependencies']);
                Route::post('/migration-wizard/suggest-mapping', [\App\Modules\Import\Presentation\Controllers\MigrationWizardController::class, 'suggestMapping']);
                Route::get('/migration-wizard/template/{type}', [\App\Modules\Import\Presentation\Controllers\MigrationWizardController::class, 'template']);
                Route::get('/migration-wizard/status', [\App\Modules\Import\Presentation\Controllers\MigrationWizardController::class, 'status']);
            });
    }
}
