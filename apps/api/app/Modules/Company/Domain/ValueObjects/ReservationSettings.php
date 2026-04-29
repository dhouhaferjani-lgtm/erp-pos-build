<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\ValueObjects;

/**
 * DTO for company reservation settings.
 * Handles stock reservation expiry, fraud detection thresholds,
 * and per-tenant refund-policy configuration (Phase C §3.5).
 *
 * IMPORTANT: Required by CLAUDE.md Rule #3 - No direct JSONB array access.
 */
final readonly class ReservationSettings
{
    /**
     * @param  array<int, string>  $allowedRefundDestinations
     * @param  array{rejected_specificity_per_hour: int, same_partner_per_day: int, cross_company_immediate: bool}  $customerHistorySearchAlertThresholds
     */
    public function __construct(
        // ── Existing fields ──────────────────────────────────────────────────
        public int $salesOrderExpiryDays = 7,
        public int $ecommerceCartExpiryMinutes = 15,
        public int $marketplaceOrderExpiryHours = 24,
        public int $customerReturnExpiryDays = 14,
        public float $highValueAlertThreshold = 1000.0,
        public float $inventoryCountTriggerThreshold = 5000.0,
        public bool $autoReserveOnSalesOrder = true,

        // ── Refund-policy: return-window (§3.5) ──────────────────────────────
        public int $customerHistoryWindowDays = 14,
        public string $outOfWindowPolicy = 'voucher_only',

        // ── Refund-policy: manager override (§3.5) ───────────────────────────
        public string $managerOverrideThresholdAmount = '50.00',
        public string $managerOverrideThresholdPercent = '10.00',
        public bool $managerOverrideRequiredForNoReceipt = true,

        // ── Refund-policy: destinations + proration (§3.5) ───────────────────
        public array $allowedRefundDestinations = ['original_payment', 'cash', 'store_voucher'],
        public string $prorationStrategy = 'proportional',

        // ── Refund-policy: voucher defaults (§3.5) ───────────────────────────
        public int $voucherDefaultExpiryDays = 365,
        public bool $voucherTransferableDefault = true,
        public bool $voucherCashRefundAllowed = false,

        // ── Refund-policy: daily caps (§3.5) ─────────────────────────────────
        public ?string $dailyRefundCapPerCashier = null,
        public bool $dailyRefundCapOverrideAllowed = true,

        // ── Refund-policy: customer-history privacy (§3.5 + Codex M) ─────────
        public int $customerHistorySearchMaxPerCashierPerDay = 15,
        public array $customerHistorySearchAlertThresholds = [
            'rejected_specificity_per_hour' => 3,
            'same_partner_per_day' => 8,
            'cross_company_immediate' => true,
        ],

        // ── Refund-policy: voucher rate limits (§3.5 + Codex H) ──────────────
        public int $voucherLookupPerTerminalPerDay = 200,
        public int $voucherLookupPerCashierPerDay = 100,
        public int $voucherLookupFailedPerTenantPerHourAlert = 50,
        public int $voucherLookupFailedPerTenantPerHourBlock = 200,
        public int $voucherFailedAttemptsAutoVoid = 5,

        // ── Refund-policy: goodwill controls (§3.5 + Codex I) ────────────────
        public string $goodwillNamedCustomerThreshold = '100.00',
        public string $goodwillFourEyesThreshold = '250.00',
        public ?string $goodwillDailyIssuanceCapPerUser = null,
        public bool $goodwillBearerDefaultOff = true,
    ) {}

    /**
     * Create from array (used when loading from database).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $customerReturnExpiryDays = (int) ($data['customer_return_expiry_days'] ?? 14);

        /** @var array{rejected_specificity_per_hour: int, same_partner_per_day: int, cross_company_immediate: bool} $alertThresholds */
        $alertThresholds = isset($data['customer_history_search_alert_thresholds'])
            && is_array($data['customer_history_search_alert_thresholds'])
            ? [
                'rejected_specificity_per_hour' => (int) ($data['customer_history_search_alert_thresholds']['rejected_specificity_per_hour'] ?? 3),
                'same_partner_per_day' => (int) ($data['customer_history_search_alert_thresholds']['same_partner_per_day'] ?? 8),
                'cross_company_immediate' => (bool) ($data['customer_history_search_alert_thresholds']['cross_company_immediate'] ?? true),
            ]
            : ['rejected_specificity_per_hour' => 3, 'same_partner_per_day' => 8, 'cross_company_immediate' => true];

        /** @var array<int, string> $allowedDestinations */
        $allowedDestinations = isset($data['allowed_refund_destinations'])
            && is_array($data['allowed_refund_destinations'])
            ? array_values(array_map('strval', $data['allowed_refund_destinations']))
            : ['original_payment', 'cash', 'store_voucher'];

        return new self(
            // Existing fields
            salesOrderExpiryDays: (int) ($data['sales_order_expiry_days'] ?? 7),
            ecommerceCartExpiryMinutes: (int) ($data['ecommerce_cart_expiry_minutes'] ?? 15),
            marketplaceOrderExpiryHours: (int) ($data['marketplace_order_expiry_hours'] ?? 24),
            customerReturnExpiryDays: $customerReturnExpiryDays,
            highValueAlertThreshold: (float) ($data['high_value_alert_threshold'] ?? 1000.0),
            inventoryCountTriggerThreshold: (float) ($data['inventory_count_trigger_threshold'] ?? 5000.0),
            autoReserveOnSalesOrder: (bool) ($data['auto_reserve_on_sales_order'] ?? true),

            // Return-window
            customerHistoryWindowDays: (int) ($data['customer_history_window_days'] ?? $customerReturnExpiryDays),
            outOfWindowPolicy: isset($data['out_of_window_policy']) ? (string) $data['out_of_window_policy'] : 'voucher_only',

            // Manager override
            managerOverrideThresholdAmount: isset($data['manager_override_threshold_amount'])
                ? (string) $data['manager_override_threshold_amount']
                : '50.00',
            managerOverrideThresholdPercent: isset($data['manager_override_threshold_percent'])
                ? (string) $data['manager_override_threshold_percent']
                : '10.00',
            managerOverrideRequiredForNoReceipt: (bool) ($data['manager_override_required_for_no_receipt'] ?? true),

            // Destinations + proration
            allowedRefundDestinations: $allowedDestinations,
            prorationStrategy: isset($data['proration_strategy']) ? (string) $data['proration_strategy'] : 'proportional',

            // Voucher defaults
            voucherDefaultExpiryDays: (int) ($data['voucher_default_expiry_days'] ?? 365),
            voucherTransferableDefault: (bool) ($data['voucher_transferable_default'] ?? true),
            voucherCashRefundAllowed: (bool) ($data['voucher_cash_refund_allowed'] ?? false),

            // Daily caps
            dailyRefundCapPerCashier: isset($data['daily_refund_cap_per_cashier'])
                ? (string) $data['daily_refund_cap_per_cashier']
                : null,
            dailyRefundCapOverrideAllowed: (bool) ($data['daily_refund_cap_override_allowed'] ?? true),

            // Customer-history privacy
            customerHistorySearchMaxPerCashierPerDay: (int) ($data['customer_history_search_max_per_cashier_per_day'] ?? 15),
            customerHistorySearchAlertThresholds: $alertThresholds,

            // Voucher rate limits
            voucherLookupPerTerminalPerDay: (int) ($data['voucher_lookup_per_terminal_per_day'] ?? 200),
            voucherLookupPerCashierPerDay: (int) ($data['voucher_lookup_per_cashier_per_day'] ?? 100),
            voucherLookupFailedPerTenantPerHourAlert: (int) ($data['voucher_lookup_failed_per_tenant_per_hour_alert'] ?? 50),
            voucherLookupFailedPerTenantPerHourBlock: (int) ($data['voucher_lookup_failed_per_tenant_per_hour_block'] ?? 200),
            voucherFailedAttemptsAutoVoid: (int) ($data['voucher_failed_attempts_auto_void'] ?? 5),

            // Goodwill controls
            goodwillNamedCustomerThreshold: isset($data['goodwill_named_customer_threshold'])
                ? (string) $data['goodwill_named_customer_threshold']
                : '100.00',
            goodwillFourEyesThreshold: isset($data['goodwill_four_eyes_threshold'])
                ? (string) $data['goodwill_four_eyes_threshold']
                : '250.00',
            goodwillDailyIssuanceCapPerUser: isset($data['goodwill_daily_issuance_cap_per_user'])
                ? (string) $data['goodwill_daily_issuance_cap_per_user']
                : null,
            goodwillBearerDefaultOff: (bool) ($data['goodwill_bearer_default_off'] ?? true),
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
            // Existing fields
            'sales_order_expiry_days' => $this->salesOrderExpiryDays,
            'ecommerce_cart_expiry_minutes' => $this->ecommerceCartExpiryMinutes,
            'marketplace_order_expiry_hours' => $this->marketplaceOrderExpiryHours,
            'customer_return_expiry_days' => $this->customerReturnExpiryDays,
            'high_value_alert_threshold' => $this->highValueAlertThreshold,
            'inventory_count_trigger_threshold' => $this->inventoryCountTriggerThreshold,
            'auto_reserve_on_sales_order' => $this->autoReserveOnSalesOrder,

            // Return-window
            'customer_history_window_days' => $this->customerHistoryWindowDays,
            'out_of_window_policy' => $this->outOfWindowPolicy,

            // Manager override
            'manager_override_threshold_amount' => $this->managerOverrideThresholdAmount,
            'manager_override_threshold_percent' => $this->managerOverrideThresholdPercent,
            'manager_override_required_for_no_receipt' => $this->managerOverrideRequiredForNoReceipt,

            // Destinations + proration
            'allowed_refund_destinations' => $this->allowedRefundDestinations,
            'proration_strategy' => $this->prorationStrategy,

            // Voucher defaults
            'voucher_default_expiry_days' => $this->voucherDefaultExpiryDays,
            'voucher_transferable_default' => $this->voucherTransferableDefault,
            'voucher_cash_refund_allowed' => $this->voucherCashRefundAllowed,

            // Daily caps
            'daily_refund_cap_per_cashier' => $this->dailyRefundCapPerCashier,
            'daily_refund_cap_override_allowed' => $this->dailyRefundCapOverrideAllowed,

            // Customer-history privacy
            'customer_history_search_max_per_cashier_per_day' => $this->customerHistorySearchMaxPerCashierPerDay,
            'customer_history_search_alert_thresholds' => $this->customerHistorySearchAlertThresholds,

            // Voucher rate limits
            'voucher_lookup_per_terminal_per_day' => $this->voucherLookupPerTerminalPerDay,
            'voucher_lookup_per_cashier_per_day' => $this->voucherLookupPerCashierPerDay,
            'voucher_lookup_failed_per_tenant_per_hour_alert' => $this->voucherLookupFailedPerTenantPerHourAlert,
            'voucher_lookup_failed_per_tenant_per_hour_block' => $this->voucherLookupFailedPerTenantPerHourBlock,
            'voucher_failed_attempts_auto_void' => $this->voucherFailedAttemptsAutoVoid,

            // Goodwill controls
            'goodwill_named_customer_threshold' => $this->goodwillNamedCustomerThreshold,
            'goodwill_four_eyes_threshold' => $this->goodwillFourEyesThreshold,
            'goodwill_daily_issuance_cap_per_user' => $this->goodwillDailyIssuanceCapPerUser,
            'goodwill_bearer_default_off' => $this->goodwillBearerDefaultOff,
        ];
    }
}
