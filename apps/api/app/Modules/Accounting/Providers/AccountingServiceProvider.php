<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Providers;

use App\Modules\Accounting\Infrastructure\Commands\BackfillRefundCompensationAccountsCommand;
use App\Modules\Accounting\Presentation\Console\CheckSubledgerReconciliationCommand;
use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckSubledgerReconciliationCommand::class,
                // v3-refund-chain-integration spec §5.3 (T1 errata).
                BackfillRefundCompensationAccountsCommand::class,
            ]);
        }
    }
}
