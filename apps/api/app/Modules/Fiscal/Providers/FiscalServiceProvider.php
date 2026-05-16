<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Providers;

use App\Modules\Fiscal\Application\Services\DefaultModuleActivationResolver;
use App\Modules\Fiscal\Application\Services\HashChainIntegrityProvider;
use App\Modules\Fiscal\Infrastructure\Commands\PreflightFiscalGateCommand;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Support\ServiceProvider;

final class FiscalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FiscalIntegrityProvider::class, HashChainIntegrityProvider::class);
        $this->app->bind(ModuleActivationResolver::class, DefaultModuleActivationResolver::class);
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
