<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Enums;

enum SyncOperationType: string
{
    case ProductPush = 'product_push';
    case StockPush = 'stock_push';
    case PriceUpdate = 'price_update';
    case OrderStatusUpdate = 'order_status_update';
}
