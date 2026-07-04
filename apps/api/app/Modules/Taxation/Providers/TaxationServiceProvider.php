<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Providers;

use App\Modules\Taxation\Application\Services\CertificatePDFService;
use App\Modules\Taxation\Application\Services\SalesWithholdingTrackingService;
use App\Modules\Taxation\Application\Services\TEJExportService;
use App\Modules\Taxation\Application\Services\VatExportService;
use App\Modules\Taxation\Application\Services\VatPeriodManagementService;
use App\Modules\Taxation\Application\Services\VatReportGenerationService;
use App\Modules\Taxation\Application\Services\WithholdingCertificateService;
use App\Modules\Taxation\Application\Services\WithholdingHashChainService;
use App\Modules\Taxation\Domain\Repositories\SalesWithholdingTrackingRepositoryInterface;
use App\Modules\Taxation\Domain\Repositories\VatDataRepositoryInterface;
use App\Modules\Taxation\Domain\Repositories\VatPeriodRepositoryInterface;
use App\Modules\Taxation\Domain\Repositories\WithholdingCertificateRepositoryInterface;
use App\Modules\Taxation\Domain\Repositories\WithholdingTaxRuleRepositoryInterface;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Taxation\Domain\Services\TaxResolutionService;
use App\Modules\Taxation\Domain\Services\VatCreditService;
use App\Modules\Taxation\Domain\Services\WithholdingCalculationService;
use App\Modules\Taxation\Infrastructure\Exporters\CsvVatExporter;
use App\Modules\Taxation\Infrastructure\Exporters\FecExporter;
use App\Modules\Taxation\Infrastructure\Exporters\MtdJsonExporter;
use App\Modules\Taxation\Infrastructure\Exporters\PdfVatExporter;
use App\Modules\Taxation\Infrastructure\Exporters\TeifXmlExporter;
use App\Modules\Taxation\Infrastructure\Repositories\EloquentSalesWithholdingTrackingRepository;
use App\Modules\Taxation\Infrastructure\Repositories\EloquentVatDataRepository;
use App\Modules\Taxation\Infrastructure\Repositories\EloquentVatPeriodRepository;
use App\Modules\Taxation\Infrastructure\Repositories\EloquentWithholdingCertificateRepository;
use App\Modules\Taxation\Infrastructure\Repositories\EloquentWithholdingTaxRuleRepository;
use App\Shared\Contracts\TaxDefaultResolverInterface;
use Illuminate\Support\ServiceProvider;

class TaxationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register taxation services as singletons
        $this->app->singleton(TaxResolutionService::class);
        $this->app->bind(TaxDefaultResolverInterface::class, fn ($app): TaxResolutionService => $app->make(TaxResolutionService::class));
        $this->app->singleton(TaxCalculationService::class);

        // Register withholding tax repositories
        $this->app->bind(
            WithholdingTaxRuleRepositoryInterface::class,
            EloquentWithholdingTaxRuleRepository::class
        );

        $this->app->bind(
            WithholdingCertificateRepositoryInterface::class,
            EloquentWithholdingCertificateRepository::class
        );

        $this->app->bind(
            SalesWithholdingTrackingRepositoryInterface::class,
            EloquentSalesWithholdingTrackingRepository::class
        );

        // Register VAT reporting repositories
        $this->app->bind(
            VatPeriodRepositoryInterface::class,
            EloquentVatPeriodRepository::class
        );

        $this->app->bind(
            VatDataRepositoryInterface::class,
            EloquentVatDataRepository::class
        );

        // Register withholding tax services as singletons
        $this->app->singleton(WithholdingCalculationService::class);
        $this->app->singleton(WithholdingHashChainService::class);
        $this->app->singleton(WithholdingCertificateService::class);
        $this->app->singleton(SalesWithholdingTrackingService::class);
        $this->app->singleton(TEJExportService::class);
        $this->app->singleton(CertificatePDFService::class);

        // Register VAT reporting services as singletons
        $this->app->singleton(VatCreditService::class);
        $this->app->singleton(VatReportGenerationService::class);
        $this->app->singleton(VatPeriodManagementService::class);
        $this->app->singleton(VatExportService::class, function (): VatExportService {
            return new VatExportService([
                new CsvVatExporter,
                new PdfVatExporter,
                new FecExporter,
                new MtdJsonExporter,
                new TeifXmlExporter,
            ]);
        });
    }

    public function boot(): void
    {
        // Load taxation module routes
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
