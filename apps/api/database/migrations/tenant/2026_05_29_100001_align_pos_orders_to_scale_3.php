<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Narrow pos_orders and pos_order_lines monetary columns from scale 4 to scale 3.
 *
 * CONTRACT
 * --------
 * pos_receipts.total is the fiscal artifact stored at decimal(12,3) and is the
 * source of truth for the fiscal hash chain. pos_orders.total was originally
 * created at decimal(15,4) — the extra 4th decimal digit is truncated immediately
 * on conversion to a receipt, creating a scale mismatch between the in-flight
 * order and its fiscal artifact. Narrowing to decimal(15,3) makes the boundary
 * honest: whatever scale is stored in an order is exactly what lands in the
 * receipt without silent truncation.
 *
 * SAFETY
 * ------
 * Before each ALTER we assert that no existing row carries a non-zero 4th decimal
 * digit. If any row does, the migration aborts with a RuntimeException naming the
 * table and column — this prevents silent truncation of real fiscal data. The
 * guard runs only on PostgreSQL (SQLite has no ALTER COLUMN TYPE support and does
 * not enforce numeric precision, so the Eloquent decimal:3 cast is the enforcer
 * there).
 *
 * SCOPE
 * -----
 * Monetary columns only: subtotal, tax_amount, discount_amount, total on
 * pos_orders; unit_price, discount_amount, tax_amount, line_total on
 * pos_order_lines. quantity (scale 3 already) and tax_rate (scale 2) are
 * explicitly excluded.
 */
return new class extends Migration
{
    /**
     * Monetary columns per table: [table => [column, ...]].
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'pos_orders' => [
            'subtotal',
            'tax_amount',
            'discount_amount',
            'total',
        ],
        'pos_order_lines' => [
            'unit_price',
            'discount_amount',
            'tax_amount',
            'line_total',
        ],
    ];

    public function up(): void
    {
        // SQLite does not support ALTER COLUMN TYPE — column types are
        // effectively unchecked in SQLite, so this migration is a no-op for
        // the test database. The decimal:3 model cast enforces display scale
        // there.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                // Pre-check: abort if any row has a non-zero 4th decimal place.
                // Casting to numeric(20,4) then to numeric(20,3) loses the 4th
                // digit; if the two values differ, data would be truncated.
                $driftCount = DB::selectOne(
                    "SELECT COUNT(*) AS cnt
                     FROM {$table}
                     WHERE {$column}::numeric(20,4) <> {$column}::numeric(20,3)",
                );

                if ((int) $driftCount->cnt > 0) {
                    throw new RuntimeException(
                        "Migration aborted: {$driftCount->cnt} row(s) in {$table}.{$column} "
                        .'carry a non-zero 4th decimal digit. '
                        .'Narrowing from decimal(15,4) to decimal(15,3) would truncate fiscal data. '
                        .'Inspect and correct these rows before re-running the migration.',
                    );
                }
            }

            $alterParts = [];
            foreach ($columns as $column) {
                $alterParts[] = "ALTER COLUMN {$column} TYPE decimal(15, 3)";
            }

            DB::statement('ALTER TABLE '.$table.' '.implode(', ', $alterParts));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            $alterParts = [];
            foreach ($columns as $column) {
                $alterParts[] = "ALTER COLUMN {$column} TYPE decimal(15, 4)";
            }

            DB::statement('ALTER TABLE '.$table.' '.implode(', ', $alterParts));
        }
    }
};
