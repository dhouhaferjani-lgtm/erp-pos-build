<?php

declare(strict_types=1);

use App\Modules\Company\Domain\Company;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: backfill refund-policy default values into every existing
 * Company's `reservation_settings` JSON blob.
 *
 * Uses array_merge($defaults, $current) so any values already stored by an
 * earlier version of this migration (or manually set) are never overwritten —
 * the migration is fully idempotent.
 *
 * No down() — backfilled defaults are non-destructive and safe to leave.
 */
return new class extends Migration
{
    /** @var array<string, mixed> */
    private array $defaults = [
        'customer_history_window_days' => 14,
        'out_of_window_policy' => 'voucher_only',
        'manager_override_threshold_amount' => '50.00',
        'manager_override_threshold_percent' => '10.00',
        'manager_override_required_for_no_receipt' => true,
        'allowed_refund_destinations' => ['original_payment', 'cash', 'store_voucher'],
        'proration_strategy' => 'proportional',
        'voucher_default_expiry_days' => 365,
        'voucher_transferable_default' => true,
        'voucher_cash_refund_allowed' => false,
        'daily_refund_cap_per_cashier' => null,
        'daily_refund_cap_override_allowed' => true,
        'customer_history_search_max_per_cashier_per_day' => 15,
        'customer_history_search_alert_thresholds' => [
            'rejected_specificity_per_hour' => 3,
            'same_partner_per_day' => 8,
            'cross_company_immediate' => true,
        ],
        'voucher_lookup_per_terminal_per_day' => 200,
        'voucher_lookup_per_cashier_per_day' => 100,
        'voucher_lookup_failed_per_tenant_per_hour_alert' => 50,
        'voucher_lookup_failed_per_tenant_per_hour_block' => 200,
        'voucher_failed_attempts_auto_void' => 5,
        'goodwill_named_customer_threshold' => '100.00',
        'goodwill_four_eyes_threshold' => '250.00',
        'goodwill_daily_issuance_cap_per_user' => null,
        'goodwill_bearer_default_off' => true,
    ];

    public function up(): void
    {
        Company::query()->each(function (Company $company): void {
            /** @var array<string, mixed> $current */
            $current = $company->getReservationSettings()->toArray();
            // array_merge: $defaults first so any previously persisted values always win
            $merged = array_merge($this->defaults, $current);
            $company->update(['reservation_settings' => $merged]);
        });
    }
};
