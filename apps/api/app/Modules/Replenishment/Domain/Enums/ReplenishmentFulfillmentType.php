<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Domain\Enums;

enum ReplenishmentFulfillmentType: string
{
    case Transfer = 'transfer';
    case PurchaseOrder = 'purchase_order';
}
