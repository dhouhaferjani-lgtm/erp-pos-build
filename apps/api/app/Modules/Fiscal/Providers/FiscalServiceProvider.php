<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Providers;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\DefaultModuleActivationResolver;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Application\Services\HashChainIntegrityProvider;
use App\Modules\Fiscal\Infrastructure\Commands\BackfillSealedHashAlgorithmCommand;
use App\Modules\Fiscal\Infrastructure\Commands\EnqueueResolvedEventProjectionsCommand;
use App\Modules\Fiscal\Infrastructure\Commands\PreflightFiscalGateCommand;
use App\Modules\Fiscal\Infrastructure\Commands\RetryFiscalProjectionsCommand;
use App\Modules\Fiscal\Infrastructure\Commands\VerifyEventChainCommand;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class FiscalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FiscalIntegrityProvider::class, HashChainIntegrityProvider::class);
        $this->app->bind(ModuleActivationResolver::class, DefaultModuleActivationResolver::class);

        // Singleton — Task 19's OutboxIngestor constructor-injects this and
        // expects a stable instance per process. Phase 1 tagged set:
        // Task 21 tags `PosCoreReceiptProjection` (priority=50, always-active);
        // Task 22 tags `TreasuryReceiptBridge` (priority=150, gated on the
        // `Treasury` module). The registry sorts the materialized tagged
        // set by (priority ASC, name ASC) once at construction (Task 22
        // round-2). An empty tagged set is still a valid runtime state.
        $this->app->singleton(
            FiscalEventProjectionRegistry::class,
            static fn (Application $app): FiscalEventProjectionRegistry => new FiscalEventProjectionRegistry(
                $app->tagged(FiscalEventProjector::class),
                $app->make(ModuleActivationResolver::class),
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PreflightFiscalGateCommand::class,
                // Task 24 — parse-failure resolution recovery path (spec
                // §15.2). Permission-gated by
                // `fiscal.events.resolve_quarantine` against an
                // --actor-id supplied at invocation.
                EnqueueResolvedEventProjectionsCommand::class,
                // Task 31 — operator + CI chain verifier (spec §12).
                // Permission-gated by `fiscal.events.verify_chain`
                // against an --actor-id supplied at invocation, mirroring
                // the Task 24 convention.
                VerifyEventChainCommand::class,
                RetryFiscalProjectionsCommand::class,
                // v3-refund-chain-integration spec §6 — one-time
                // sealed_hash_algorithm backfill + per-terminal
                // backfill-completion stamp (§6.3's verifier gate).
                BackfillSealedHashAlgorithmCommand::class,
            ]);
        }

        // Task 20 — load the single fiscal-event ingestion endpoint
        // (`POST /api/v1/pos/sync/fiscal-events`). Mirrors
        // `POSServiceProvider::boot()` (apps/api/app/Modules/POS/Providers/POSServiceProvider.php:49).
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
