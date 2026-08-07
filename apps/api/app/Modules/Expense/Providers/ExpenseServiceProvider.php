<?php

declare(strict_types=1);

namespace App\Modules\Expense\Providers;

use App\Modules\Expense\Application\Services\ExpensePartnerReferenceSource;
use App\Modules\Expense\Presentation\Console\GenerateRecurringExpensesCommand;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Expense module.
 */
class ExpenseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Partner delete guard (lane R2-S): this module answers for its own
        // partner-referencing tables. Consumed by
        // `PartnerReferenceCounter` via
        // `app->tagged(PartnerReferenceSource::class)`. Tagged in
        // `register()` (not `boot()`) to match the `FiscalEventProjector`
        // precedent.
        $this->app->tag([ExpensePartnerReferenceSource::class], PartnerReferenceSource::class);

        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateRecurringExpensesCommand::class,
            ]);
        }
    }
}
