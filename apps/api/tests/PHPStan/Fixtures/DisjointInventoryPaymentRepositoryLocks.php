<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

final class PaymentRepositoryOnly
{
    public function run(): void
    {
        // stock_levels and ProductCostLock are comment-only decoys.
        db()->table('payment_repositories')->lockForUpdate()->first();
    }
}

final class InventoryOnly
{
    public function run(): void
    {
        db()->table('stock_levels')->lockForUpdate()->first();
    }
}
