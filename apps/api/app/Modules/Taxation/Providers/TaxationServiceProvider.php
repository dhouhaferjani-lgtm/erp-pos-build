<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Providers;

use App\Modules\Taxation\Application\Services\CertificatePDFService;
use App\Modules\Taxation\Application\Services\SalesWithholdingTrackingService;
use App\Modules\Taxation\Application\Services\TaxationPartnerReferenceSource;
use App\Modules\Taxation\Application\Services\TaxConfigurationLookupService;
use App\Modules\Taxation\Application\Services\TEJExportService;
use App\Modules\Taxation\Application\Services\VatExportService;
use App\Modules\Taxation\Application\Services\VatPeriodBackdatingGuard;
use App\Modules\Taxation\Application\Services\VatPeriodCancellationGuard;
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
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use App\Shared\Contracts\Taxation\DocumentPeriodLockInterface;
use App\Shared\Contracts\Taxation\PeriodBackdatingGuardInterface;
use App\Shared\Contracts\TaxConfigurationLookupInterface;
use App\Shared\Contracts\TaxDefaultResolverInterface;
use Illuminate\Support\ServiceProvider;

class TaxationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Partner delete guard (lane R2-S): this module answers for its own
        // partner-referencing tables. Consumed by
        // `PartnerReferenceCounter` via
        // `app->tagged(PartnerReferenceSource::class)`. Tagged in
        // `register()` (not `boot()`) to match the `FiscalEventProjector`
        // precedent.
        $this->app->tag([TaxationPartnerReferenceSource::class], PartnerReferenceSource::class);

        // Register taxation services as singletons
        $this->app->singleton(TaxResolutionService::class);
        $this->app->bind(TaxDefaultResolverInterface::class, fn ($app): TaxResolutionService => $app->make(TaxResolutionService::class));
        $this->app->bind(TaxConfigurationLookupInterface::class, TaxConfigurationLookupService::class);
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

        // R2-F1 — Document asks Taxation whether a cancellation's period is still
        // OPEN through this Shared contract, never through the VatPeriod entity.
        $this->app->bind(DocumentPeriodLockInterface::class, VatPeriodCancellationGuard::class);

        // Plan CF CF-D3 — Document asks Taxation whether a return note may be
        // DATED into a period, through a SEPARATE Shared contract. Deliberately not
        // a widening of `DocumentPeriodLockInterface`: that one answers "may this
        // ledger-bearing document be withdrawn" and its `refusalAppliesTo()` seam
        // excludes ReturnNote for documented reasons. Different question, different
        // date, different refusal codes, different remedy (one PATCH of the draft's
        // `document_date`, not a credit note).
        $this->app->bind(PeriodBackdatingGuardInterface::class, VatPeriodBackdatingGuard::class);

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
