<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Providers;

use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Shared\Contracts\Treasury\PaymentToleranceCheckerContract;
use Illuminate\Support\ServiceProvider;

class TreasuryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Module-boundary contract: POS A1 and B2B A2 close-with-tolerance
        // depend on this interface from Shared/Contracts/Treasury, not on the
        // concrete service. Singleton because the service is stateless and
        // we want callers to share a resolved instance during a request.
        $this->app->singleton(
            PaymentToleranceCheckerContract::class,
            PaymentToleranceService::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
