<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Providers;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Treasury\Application\Projections\TreasuryAccountChargeBridge;
use App\Modules\Treasury\Application\Projections\TreasuryAccountPaymentBridge;
use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Application\Services\RepositoryInflowService;
use App\Modules\Treasury\Application\Services\RepositoryOutflowService;
use App\Modules\Treasury\Application\Services\TreasuryMovementService;
use App\Modules\Treasury\Infrastructure\EloquentPaymentMethodResolver;
use App\Modules\Treasury\Presentation\Console\AuditDiscountsCommand;
use App\Shared\Contracts\Fiscal\PaymentMethodResolver;
use App\Shared\Contracts\Treasury\PaymentToleranceCheckerContract;
use App\Shared\Contracts\Treasury\RepositoryInflowInterface;
use App\Shared\Contracts\Treasury\RepositoryOutflowInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
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

        // Pass 2A.PHP.2 — bind the PaymentMethodResolver seam (synthesis v5
        // §8.B + dispatch §0 Gap A). The 27-key canonical SALE_RECEIPT
        // payload no longer carries `payment_method_id` per-payment; the POS
        // projector resolves the tenant-scoped FK from `(tenant_id, method_code)`
        // via this interface so POS module never imports Treasury directly
        // (SoT §13.6/D16 bounded-modules asymmetric seam).
        $this->app->singleton(
            PaymentMethodResolver::class,
            EloquentPaymentMethodResolver::class,
        );

        // Task 4 — boundary-safe cash/bank outflow port. Expense module (and any
        // future consumer) depends ONLY on this shared interface; the Treasury
        // model never leaks across the module boundary.
        $this->app->bind(
            RepositoryOutflowInterface::class,
            RepositoryOutflowService::class,
        );

        $this->app->bind(
            RepositoryInflowInterface::class,
            RepositoryInflowService::class,
        );

        // Task 11 — the single money-movement write port. Every treasury
        // movement (payments, expenses, POS receipt legs, transfers, reversals)
        // converges onto record(). Bound to the Shared contract so cross-module
        // consumers never import the concrete service or Treasury domain models.
        $this->app->bind(
            TreasuryMovementServiceInterface::class,
            TreasuryMovementService::class,
        );

        // Phase 1 §7.4 / §13 / SoT §13.6/D16 — Treasury-operational projector
        // for `SALE_RECEIPT` fiscal events. Owns the Treasury `Payment` +
        // POS-payment GL post relocated from `ReceiptPaymentService`. The
        // Fiscal module's registry (Task 18 — FiscalEventProjectionRegistry)
        // consumes the tagged set via `app->tagged(FiscalEventProjector::class)`
        // and gates dispatch on `ModuleActivationResolver::isActive('Treasury',
        // tenant, company)` (the bridge's `requiresModule()` returns the
        // canonical 'Treasury' token). Tag at register() (not boot()) — the
        // registry is a singleton constructed off the tagged set and a
        // boot-time tag would leave the registry's $projectors array empty.
        $this->app->tag(
            [
                TreasuryReceiptBridge::class,
                TreasuryAccountPaymentBridge::class,
                TreasuryAccountChargeBridge::class,
                TreasuryDepositBridge::class,
            ],
            FiscalEventProjector::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AuditDiscountsCommand::class,
            ]);
        }
    }
}
