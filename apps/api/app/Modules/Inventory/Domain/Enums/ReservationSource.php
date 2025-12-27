<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

use App\Modules\Company\Domain\ValueObjects\ReservationSettings;

enum ReservationSource: string
{
    case SalesOrder = 'sales_order';
    case EcommerceCart = 'ecommerce_cart';
    case MarketplaceOrder = 'marketplace_order';
    case ManualHold = 'manual_hold';
    case CustomerReturnPending = 'customer_return_pending';
    case QualityCheck = 'quality_check';
    case TransferPending = 'transfer_pending';

    public function getDefaultExpiry(ReservationSettings $settings): ?\DateTimeInterface
    {
        return match ($this) {
            self::SalesOrder => $settings->salesOrderExpiryDays > 0
                ? now()->addDays($settings->salesOrderExpiryDays)
                : null,
            self::EcommerceCart => now()->addMinutes($settings->ecommerceCartExpiryMinutes),
            self::MarketplaceOrder => now()->addHours($settings->marketplaceOrderExpiryHours),
            self::CustomerReturnPending => now()->addDays($settings->customerReturnExpiryDays),
            self::ManualHold => null,
            self::QualityCheck => now()->addDays(3),
            self::TransferPending => now()->addDays(1),
        };
    }

    public function triggersFraudAlert(): bool
    {
        return match ($this) {
            self::SalesOrder => true,
            self::MarketplaceOrder => true,
            self::ManualHold => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SalesOrder => 'Sales Order',
            self::EcommerceCart => 'E-commerce Cart',
            self::MarketplaceOrder => 'Marketplace Order',
            self::ManualHold => 'Manual Hold',
            self::CustomerReturnPending => 'Pending Customer Return',
            self::QualityCheck => 'Quality Check',
            self::TransferPending => 'Pending Transfer',
        };
    }
}
