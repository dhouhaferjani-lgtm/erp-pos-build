<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen monetary columns missed by 2026_03_11_200000_widen_monetary_columns_to_scale_3.
 *
 * These columns were still at scale 2, causing TND values to be truncated on insert.
 */
return new class extends Migration
{
    /**
     * Column definitions: [table => [[column, precision, new_scale], ...]]
     *
     * @var array<string, list<array{string, int, int}>>
     */
    private const COLUMNS = [
        // Document tax breakdown
        'document_tax_details' => [
            ['tax_base', 15, 3],
            ['tax_amount', 15, 3],
        ],

        // Document lines (tax-related columns missed in first migration)
        'document_lines' => [
            ['tax_amount', 15, 3],
            ['recoverable_tax_amount', 15, 3],
            ['non_recoverable_tax_amount', 15, 3],
        ],

        // Coupons
        'coupon_usages' => [
            ['discount_amount', 15, 3],
        ],

        // Promotions
        'promotions' => [
            ['max_discount_amount', 15, 3],
        ],

        // Loyalty earning rules
        'earning_rules' => [
            ['max_earn_per_transaction', 15, 3],
            ['max_earn_per_day', 15, 3],
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
