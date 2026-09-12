<?php

declare(strict_types=1);

namespace App\Modules\Import\Providers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Import\Infrastructure\Commands\PurgeExpiredImportArtifactsCommand;
use App\Modules\Import\Infrastructure\Commands\ReapStuckImportsCommand;
use App\Modules\Import\Presentation\Controllers\ImportController;
use App\Modules\Import\Presentation\Controllers\MigrationWizardController;
use App\Modules\Import\Services\AccountingBalancesPhase;
use App\Modules\Import\Services\CoalescingAttributeMerger;
use App\Modules\Import\Services\DuplicateCensusService;
use App\Modules\Import\Services\ImportJobClaimService;
use App\Modules\Import\Services\ImportService;
use App\Modules\Import\Services\MigrationWizardService;
use App\Modules\Import\Services\NumericFieldNormalizer;
use App\Modules\Import\Services\PartiesBalancesPhase;
use App\Modules\Import\Services\PartiesRowMapper;
use App\Modules\Import\Services\ProductOpeningStockPhase;
use App\Modules\Import\Services\ProductPlacementImportService;
use App\Modules\Import\Services\ProductPriceResolver;
use App\Modules\Import\Services\UnitResolver;
use App\Modules\Import\Services\ValidationEngine;
use App\Shared\Contracts\CoalescingAttributeMergerInterface;
use App\Shared\Contracts\CompositeItemServiceInterface;
use App\Shared\Contracts\PartnerResolverInterface;
use App\Shared\Contracts\PartnerServiceInterface;
use App\Shared\Contracts\ProductResolverInterface;
use App\Shared\Contracts\ProductServiceInterface;
use App\Shared\Contracts\TaxDefaultResolverInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CoalescingAttributeMergerInterface::class, CoalescingAttributeMerger::class);
        $this->app->singleton(DuplicateCensusService::class);

        $this->app->singleton(ValidationEngine::class, function () {
            return new ValidationEngine;
        });

        $this->app->singleton(UnitResolver::class);

        $this->app->singleton(ImportService::class, function ($app) {
            return new ImportService(
                $app->make(ValidationEngine::class),
                $app->make(CompanyContext::class),
                $app->make(PartnerServiceInterface::class),
                $app->make(PartnerResolverInterface::class),
                $app->make(ProductServiceInterface::class),
                $app->make(ProductResolverInterface::class),
                $app->make(CompositeItemServiceInterface::class),
                $app->make(NumericFieldNormalizer::class),
                $app->make(PartiesRowMapper::class),
                $app->make(PartiesBalancesPhase::class),
                $app->make(AccountingBalancesPhase::class),
                $app->make(ProductPriceResolver::class),
                $app->make(TaxDefaultResolverInterface::class),
                $app->make(ProductOpeningStockPhase::class),
                $app->make(ProductPlacementImportService::class),
                $app->make(UnitResolver::class),
                $app->make(DuplicateCensusService::class),
                $app->make(ImportJobClaimService::class),
            );
        });

        $this->app->singleton(MigrationWizardService::class, function () {
            return new MigrationWizardService;
        });
    }

    public function boot(): void
    {
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PurgeExpiredImportArtifactsCommand::class,
                ReapStuckImportsCommand::class,
            ]);
        }
    }

    private function registerRoutes(): void
    {
        Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'can:imports.manage'])
            ->prefix('api/v1')
            ->group(function (): void {
                // Import routes
                Route::get('/imports', [ImportController::class, 'index']);
                Route::post('/imports', [ImportController::class, 'store']);
                Route::whereUuid('id')->group(function (): void {
                    Route::get('/imports/{id}', [ImportController::class, 'show']);
                    Route::delete('/imports/{id}', [ImportController::class, 'destroy']);
                    Route::patch('/imports/{id}/options', [ImportController::class, 'updateOptions']);
                    Route::get('/imports/{id}/preview', [ImportController::class, 'preview']);
                    Route::get('/imports/{id}/errors', [ImportController::class, 'errors']);
                    Route::get('/imports/{id}/error-summary', [ImportController::class, 'errorSummary']);
                    Route::post('/imports/{id}/execute', [ImportController::class, 'execute']);
                    Route::get('/imports/{id}/failed-rows.{format}', [ImportController::class, 'downloadFailedRows'])->where('format', 'csv|xlsx');
                    Route::get('/imports/{id}/source-file', [ImportController::class, 'downloadSourceFile']);
                    Route::get('/imports/{id}/result-workbook', [ImportController::class, 'downloadResultWorkbook']);
                });

                // Migration wizard routes
                Route::get('/migration-wizard/order', [MigrationWizardController::class, 'order']);
                Route::get('/migration-wizard/dependencies/{type}', [MigrationWizardController::class, 'dependencies']);
                Route::post('/migration-wizard/suggest-mapping', [MigrationWizardController::class, 'suggestMapping']);
                Route::post('/migration-wizard/parse-headers', [MigrationWizardController::class, 'parseHeaders']);
                Route::get('/migration-wizard/template/{type}', [MigrationWizardController::class, 'template']);
                Route::get('/migration-wizard/status', [MigrationWizardController::class, 'status']);
            });
    }
}
