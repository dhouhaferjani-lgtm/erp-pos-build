<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\ValueObjects;

/**
 * DTO for company reservation settings.
 * Handles stock reservation expiry and fraud detection thresholds.
 *
 * IMPORTANT: Required by CLAUDE.md Rule #3 - No direct JSONB array access.
 */
final readonly class ReservationSettings
{
    public function __construct(
        public int $salesOrderExpiryDays = 7,
        public int $ecommerceCartExpiryMinutes = 15,
        public int $marketplaceOrderExpiryHours = 24,
        public int $customerReturnExpiryDays = 14,
        public float $highValueAlertThreshold = 1000.0,
        public float $inventoryCountTriggerThreshold = 5000.0,
        public bool $autoReserveOnSalesOrder = true,
    ) {}

    /**
     * Create from array (used when loading from database).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            salesOrderExpiryDays: (int) ($data['sales_order_expiry_days'] ?? 7),
            ecommerceCartExpiryMinutes: (int) ($data['ecommerce_cart_expiry_minutes'] ?? 15),
            marketplaceOrderExpiryHours: (int) ($data['marketplace_order_expiry_hours'] ?? 24),
            customerReturnExpiryDays: (int) ($data['customer_return_expiry_days'] ?? 14),
            highValueAlertThreshold: (float) ($data['high_value_alert_threshold'] ?? 1000.0),
            inventoryCountTriggerThreshold: (float) ($data['inventory_count_trigger_threshold'] ?? 5000.0),
            autoReserveOnSalesOrder: (bool) ($data['auto_reserve_on_sales_order'] ?? true),
        );
    }

    /**
     * Convert to array (used when saving to database).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sales_order_expiry_days' => $this->salesOrderExpiryDays,
            'ecommerce_cart_expiry_minutes' => $this->ecommerceCartExpiryMinutes,
            'marketplace_order_expiry_hours' => $this->marketplaceOrderExpiryHours,
            'customer_return_expiry_days' => $this->customerReturnExpiryDays,
            'high_value_alert_threshold' => $this->highValueAlertThreshold,
            'inventory_count_trigger_threshold' => $this->inventoryCountTriggerThreshold,
            'auto_reserve_on_sales_order' => $this->autoReserveOnSalesOrder,
        ];
    }
}
