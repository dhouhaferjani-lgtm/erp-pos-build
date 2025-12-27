<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum ReleaseReason: string
{
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case ManualRelease = 'manual_release';
    case Converted = 'converted';
    case OrderModified = 'order_modified';
    case InsufficientStock = 'insufficient_stock';

    public function isSuspicious(): bool
    {
        return match ($this) {
            self::Expired => true,
            self::ManualRelease => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Delivered => 'Fulfilled by Delivery',
            self::Cancelled => 'Order Cancelled',
            self::Expired => 'Reservation Expired',
            self::ManualRelease => 'Manually Released',
            self::Converted => 'Converted to Order',
            self::OrderModified => 'Order Modified',
            self::InsufficientStock => 'Stock Unavailable',
        };
    }
}
