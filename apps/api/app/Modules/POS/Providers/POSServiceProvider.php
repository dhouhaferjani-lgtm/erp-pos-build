<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\POS\Application\Projections\AccountChargeReceiptProjection;
use App\Modules\POS\Application\Projections\AccountPaymentReceiptProjection;
use App\Modules\POS\Application\Projections\DepositReceiptProjection;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Application\Projections\ZReportProjection;
use App\Modules\POS\Application\Projections\ZSessionLifecycleProjection;
use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Modules\POS\Application\Services\PosPartnerReferenceSource;
use App\Modules\POS\Application\Services\TerminalSyncHealthSourceService;
use App\Modules\POS\Commands\CloseOrphanedShiftCommand;
use App\Modules\POS\Commands\VerifyPosChainCommand;
use App\Modules\POS\Infrastructure\BatchTraceability\PosBatchTraceReaderAdapter;
use App\Shared\Contracts\BatchTraceability\PosBatchTraceReader;
use App\Shared\Contracts\Compliance\Nf525DataProviderContract;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use App\Shared\Contracts\POS\TerminalSyncHealthSource;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for POS module.
 *
 * Registers routes and services for the POS system including:
 * - Shift management
 * - Cash drawer operations
 * - X and Z reports
 * - NF525 compliance (hash chains, grand totals)
 */
final class POSServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PosBatchTraceReader::class, PosBatchTraceReaderAdapter::class);
        // Partner delete guard (lane R2-S): this module answers for its own
        // partner-referencing tables. Consumed by
        // `PartnerReferenceCounter` via
        // `app->tagged(PartnerReferenceSource::class)`. Tagged in
        // `register()` (not `boot()`) to match the `FiscalEventProjector`
        // precedent.
        $this->app->tag([PosPartnerReferenceSource::class], PartnerReferenceSource::class);

        // Services are auto-resolved via constructor injection.
        // Cross-module contracts published by POS are bound explicitly:
        $this->app->bind(
            Nf525DataProviderContract::class,
            Nf525DataProvider::class,
        );
        $this->app->bind(
            TerminalSyncHealthSource::class,
            TerminalSyncHealthSourceService::class,
        );

        // Fiscal-event projector — Phase 1 §7.5 / SoT §13.6/D16.
        // The POS module owns its projector; the Fiscal module's registry
        // (Task 18 — FiscalEventProjectionRegistry) consumes the tagged set
        // via `app->tagged(FiscalEventProjector::class)`. Tag at register()
        // (not boot()) because the registry is a singleton constructed off
        // the tagged set — late-binding from boot() would leave the
        // registry's $projectors array empty.
        $this->app->tag(
            [
                PosCoreReceiptProjection::class,
                AccountPaymentReceiptProjection::class,
                AccountChargeReceiptProjection::class,
                DepositReceiptProjection::class,
                ZReportProjection::class,
                ZSessionLifecycleProjection::class,
            ],
            FiscalEventProjector::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CloseOrphanedShiftCommand::class,
                VerifyPosChainCommand::class,
            ]);
        }

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }
}
