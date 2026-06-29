<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Providers;

use App\Modules\Procurement\Application\ProcurementPolicyResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Procurement module.
 *
 * Phase 1 (Stage A): registers the ProcurementPolicyResolver as a
 * singleton so the same instance is reused within a request lifecycle.
 *
 * B3 (Presentation layer): loads supplier-invoice routes from the
 * Procurement Presentation routes.php, mirroring DocumentServiceProvider.
 */
final class ProcurementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProcurementPolicyResolver::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
