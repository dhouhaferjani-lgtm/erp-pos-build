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
    case WorkOrder = 'work_order';

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
            // WorkOrder reservations live as long as the WO is open — the caller supplies
            // an explicit expiry via InventoryReservationServiceInterface::reserveForWorkOrder,
            // so the default here is "no expiry" (null). WO cancellation/closure releases
            // them explicitly via releaseForWorkOrder.
            self::WorkOrder => null,
        };
    }

    public function triggersFraudAlert(): bool
    {
        return match ($this) {
            self::SalesOrder => true,
            self::MarketplaceOrder => true,
            self::ManualHold => true,
            // WorkOrder-sourced reservations do NOT trigger the POS fraud-alert heuristic.
            // Workshop has its own authorisation/approval flow (quote → customer approval)
            // that already scopes who can consume stock.
            self::WorkOrder => false,
            self::EcommerceCart,
            self::CustomerReturnPending,
            self::QualityCheck,
            self::TransferPending => false,
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
            self::WorkOrder => 'Work Order',
        };
    }
}
