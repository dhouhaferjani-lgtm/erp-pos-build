<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Providers;

use App\Modules\Fiscal\Application\Services\DefaultModuleActivationResolver;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Application\Services\HashChainIntegrityProvider;
use App\Modules\Fiscal\Infrastructure\Commands\PreflightFiscalGateCommand;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
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
        // expects a stable instance per process. The tagged
        // FiscalEventProjector set is empty in Phase 1 until Tasks 21/22
        // register real projectors; an empty registry is valid.
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
            ]);
        }
    }
}
