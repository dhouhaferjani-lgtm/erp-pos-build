<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen all monetary decimal columns from scale 2 to scale 3.
 *
 * This supports ISO 4217 currencies with 3 decimal places (TND, LYD, BHD, IQD, JOD, KWD, OMR).
 * PostgreSQL pads existing values with trailing zeros (1.24 → 1.240) — no data loss.
 * Existing truncated Tunisian data stays as-is (fiscally-signed records can't be changed).
 */
return new class extends Migration
{
    /**
     * Column definitions: [table => [[column, precision, new_scale], ...]]
     *
     * @var array<string, list<array{string, int, int}>>
     */
    private const COLUMNS = [
        // Accounting
        'accounts' => [
            ['balance', 19, 3],
        ],
        'journal_lines' => [
            ['debit', 15, 3],
            ['credit', 15, 3],
        ],

        // Products
        'products' => [
            ['sale_price', 15, 3],
            ['purchase_price', 15, 3],
            ['cost_price', 12, 3],
            ['last_purchase_cost', 12, 3],
            ['target_margin_override', 5, 3],
            ['minimum_margin_override', 5, 3],
        ],

        // Stock (monetary columns only — quantity columns stay at 2 for now)
        'stock_movements' => [
            ['unit_cost', 12, 3],
            ['total_cost', 12, 3],
            ['avg_cost_before', 12, 3],
            ['avg_cost_after', 12, 3],
        ],

        // Documents
        'documents' => [
            ['subtotal', 15, 3],
            ['discount_amount', 15, 3],
            ['tax_amount', 15, 3],
            ['total', 15, 3],
            ['balance_due', 15, 3],
            ['loyalty_discount_amount', 15, 3],
        ],
        'document_lines' => [
            ['unit_price', 15, 3],
            ['discount_amount', 15, 3],
            ['line_total', 15, 3],
            ['allocated_costs', 12, 3],
            ['landed_unit_cost', 12, 3],
        ],
        'document_additional_costs' => [
            ['amount', 12, 3],
        ],

        // POS
        'pos_receipts' => [
            ['subtotal', 12, 3],
            ['tax_amount', 12, 3],
            ['discount_amount', 12, 3],
            ['total', 12, 3],
        ],
        'pos_receipt_lines' => [
            ['unit_price', 12, 3],
            ['line_total', 12, 3],
            ['tax_amount', 12, 3],
            ['discount_amount', 12, 3],
        ],
        'pos_receipt_payments' => [
            ['amount', 12, 3],
        ],
        'pos_cash_drawer_operations' => [
            ['amount', 12, 3],
        ],
        'pos_shifts' => [
            ['opening_cash', 12, 3],
            ['expected_cash', 12, 3],
            ['actual_cash', 12, 3],
            ['variance', 12, 3],
        ],
        'pos_receipt_vat_details' => [
            ['net_amount', 12, 3],
            ['vat_amount', 12, 3],
            ['gross_amount', 12, 3],
        ],

        // Pricing
        'price_list_items' => [
            ['price', 12, 3],
        ],

        // Treasury
        'payment_instruments' => [
            ['amount', 15, 3],
        ],
        'payments' => [
            ['amount', 15, 3],
        ],
        'payment_repositories' => [
            ['balance', 15, 3],
            ['last_reconciled_balance', 15, 3],
        ],
        'bank_reconciliations' => [
            ['opening_balance', 15, 3],
            ['closing_balance', 15, 3],
            ['statement_balance', 15, 3],
            ['difference', 15, 3],
        ],

        // Services
        'services' => [
            ['base_price', 15, 3],
            ['hourly_rate', 15, 3],
        ],

        // Credit notes
        'credit_note_allocations' => [
            ['amount', 15, 3],
        ],

        // Loyalty
        'loyalty_tiers' => [
            ['qualification_threshold', 15, 3],
        ],
        'loyalty_rewards' => [
            ['points_cost', 15, 3],
            ['reward_value', 15, 3],
            ['max_discount', 15, 3],
            ['min_order_value', 15, 3],
        ],
        'loyalty_transactions' => [
            ['amount', 15, 3],
            ['balance_before', 15, 3],
            ['balance_after', 15, 3],
        ],

        // Billing
        'billing_invoices' => [
            ['subtotal', 12, 3],
            ['tax_amount', 12, 3],
            ['discount_amount', 12, 3],
            ['total', 12, 3],
            ['amount_paid', 12, 3],
            ['amount_due', 12, 3],
        ],
        'billing_invoice_items' => [
            ['unit_price', 12, 3],
            ['amount', 12, 3],
            ['tax_amount', 12, 3],
            ['discount_amount', 12, 3],
        ],
        'billing_payments' => [
            ['amount', 12, 3],
            ['fee', 12, 3],
            ['net_amount', 12, 3],
            ['refunded_amount', 12, 3],
        ],
        'billing_refunds' => [
            ['amount', 12, 3],
        ],

        // Coupons
        'coupons' => [
            ['max_discount_amount', 15, 3],
            ['minimum_order_amount', 15, 3],
        ],

        // Promotion usages
        'promotion_usages' => [
            ['discount_amount', 15, 3],
        ],

        // Sales withholding tracking
        'sales_withholding_tracking' => [
            ['invoice_amount', 15, 3],
            ['withholding_amount', 15, 3],
            ['expected_receivable', 15, 3],
        ],
    ];

    public function up(): void
    {
        // SQLite does not support ALTER COLUMN TYPE — column types are effectively
        // unchecked in SQLite, so this migration is a no-op for the test database.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            $alterParts = [];
            foreach ($columns as [$column, $precision, $scale]) {
                $alterParts[] = "ALTER COLUMN {$column} TYPE decimal({$precision}, {$scale})";
            }

            if ($alterParts !== []) {
                DB::statement("ALTER TABLE {$table} ".implode(', ', $alterParts));
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            $alterParts = [];
            foreach ($columns as [$column, $precision]) {
                $alterParts[] = "ALTER COLUMN {$column} TYPE decimal({$precision}, 2)";
            }

            if ($alterParts !== []) {
                DB::statement("ALTER TABLE {$table} ".implode(', ', $alterParts));
            }
        }
    }
};
