<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

final class PaymentRepositoryOnly
{
    public function run(): void
    {
        // stock_levels and ProductCostLock are comment-only decoys.
        db()->table('payment_repositories')->lockForUpdate()->first();
        logger()->warning('stock_levels is a string-only decoy');
    }
}

final class InventoryOnly
{
    public function run(): void
    {
        db()->table('stock_levels')->lockForUpdate()->first();
    }
}
