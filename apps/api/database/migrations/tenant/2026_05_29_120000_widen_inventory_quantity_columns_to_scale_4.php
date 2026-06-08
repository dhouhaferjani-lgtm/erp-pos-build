<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen inventory quantity columns from scale 2 to scale 4.
 *
 * Inventory quantities are produced at 4 decimal places (stock transfer lines,
 * document lines, counting items all use decimal(15,4)), but `stock_levels` and
 * `stock_movements` stored quantities at decimal(15,2). A quantity such as
 * 7.1234 was silently truncated to 7.12 when it round-tripped through a stock
 * adjustment, leaving un-reconcilable ghost stock.
 *
 * This aligns the storage scale with the highest-precision producer (4 dp) so
 * the boundary is honest from API to storage. PostgreSQL pads existing values
 * with trailing zeros (7.12 → 7.1200) — the widening is non-destructive.
 *
 * Scope is quantity columns only. Currency columns are handled separately by
 * CurrencyScale / the scale-3 widening migration; tax-rate columns keep their
 * own precision. Neither is touched here.
 */
return new class extends Migration
{
    /**
     * Quantity columns to widen: [table => [column, ...]].
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'stock_levels' => [
            'quantity',
            'reserved',
            'min_quantity',
            'max_quantity',
        ],
        'stock_movements' => [
            'quantity',
            'quantity_before',
            'quantity_after',
        ],
    ];

    public function up(): void
    {
        // SQLite does not support ALTER COLUMN TYPE — column types are
        // effectively unchecked in SQLite, so this migration is a no-op for the
        // test database. Quantity precision is enforced there by the bcmath
        // scale and the `decimal:4` model casts instead.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            $alterParts = [];
            foreach ($columns as $column) {
                $alterParts[] = "ALTER COLUMN {$column} TYPE decimal(15, 4)";
            }

            DB::statement("ALTER TABLE {$table} ".implode(', ', $alterParts));
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
                $alterParts[] = "ALTER COLUMN {$column} TYPE decimal(15, 2)";
            }

            DB::statement("ALTER TABLE {$table} ".implode(', ', $alterParts));
        }
    }
};
