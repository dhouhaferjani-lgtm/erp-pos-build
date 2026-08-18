<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Providers;

use App\Modules\Accounting\Application\Services\AccountingPartnerReferenceSource;
use App\Modules\Accounting\Infrastructure\Commands\BackfillRefundCompensationAccountsCommand;
use App\Modules\Accounting\Presentation\Console\CheckCogsCoverageCommand;
use App\Modules\Accounting\Presentation\Console\CheckSubledgerReconciliationCommand;
use App\Modules\Accounting\Presentation\Console\ReverseInventoryMovementEntriesCommand;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Partner delete guard (lane R2-S): this module answers for its own
        // partner-referencing tables. Consumed by
        // `PartnerReferenceCounter` via
        // `app->tagged(PartnerReferenceSource::class)`. Tagged in
        // `register()` (not `boot()`) to match the `FiscalEventProjector`
        // precedent.
        $this->app->tag([AccountingPartnerReferenceSource::class], PartnerReferenceSource::class);

        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                // DPA Wave 3 T23 — the lane-separation detector.
                CheckCogsCoverageCommand::class,
                CheckSubledgerReconciliationCommand::class,
                // DPA Wave 3 T19b — operator-only forward rollback command.
                ReverseInventoryMovementEntriesCommand::class,
                // v3-refund-chain-integration spec §5.3 (T1 errata).
                BackfillRefundCompensationAccountsCommand::class,
            ]);
        }
    }
}
