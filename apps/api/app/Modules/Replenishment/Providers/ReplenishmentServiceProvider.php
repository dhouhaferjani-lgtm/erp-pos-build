<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Providers;

use App\Modules\Inventory\Domain\Events\StockTransferCancelled;
use App\Modules\Inventory\Domain\Events\StockTransferInitiated;
use App\Modules\Replenishment\Application\Listeners\ReopenRequestsOnTransferCancelled;
use App\Modules\Replenishment\Application\Listeners\SettleRequestsOnTransferInitiated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class ReplenishmentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
        Event::listen(StockTransferInitiated::class, SettleRequestsOnTransferInitiated::class);
        Event::listen(StockTransferCancelled::class, ReopenRequestsOnTransferCancelled::class);
    }
}
