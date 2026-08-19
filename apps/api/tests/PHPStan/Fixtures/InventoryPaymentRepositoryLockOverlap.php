<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

final class InventoryPaymentRepositoryLockOverlap
{
    public function run(): void
    {
        db()->table('payment_repositories')->lockForUpdate()->first();
        db()->table('stock_levels')->lockForUpdate()->first();
    }
}
