<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum DeliveryNoteBillingLane: string
{
    case Consolidation = 'consolidation';
    case OrderConversion = 'order_conversion';
    case PrePostDelivery = 'pre_post_delivery';
    case LegacyUnknown = 'legacy_unknown';
}
