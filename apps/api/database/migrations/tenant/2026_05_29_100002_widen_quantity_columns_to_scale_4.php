<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen quantity columns across all non-inventory modules to canonical scale 4.
 *
 * CANONICAL QUANTITY RULE
 * =======================
 * All quantity columns in the ERP use decimal(15,4) — four decimal places —
 * to support fractional units such as litres, kilograms, and fractional packs.
 * The inventory tables (`stock_levels`, `stock_movements`) were aligned to this
 * scale in migration 2026_05_29_120000; this migration handles the remaining
 * modules.
 *
 * Sources were inconsistent before this migration:
 *   - Most tables: decimal(10,2)  — silently truncates e.g. 1.2345 → 1.23
 *   - Workshop tables: decimal(10,3) / decimal(12,3) — partial fix from an
 *     earlier pass that landed at scale 3 instead of 4
 *   - POS receipt/order lines: decimal(10,3) — ditto
 *
 * PostgreSQL column widening (`TYPE NUMERIC(15,4)`) is non-destructive: existing
 * values are padded with trailing zeros (e.g. 7.12 → 7.1200). No data is lost.
 *
 * SQLite (used for in-memory test runs) does not support ALTER COLUMN TYPE;
 * type enforcement happens via the `decimal:4` Eloquent cast, so this
 * migration is a no-op on SQLite connections.
 *
 * EXPLICITLY SKIPPED (see plan comments):
 *   - billing_invoice_items.quantity  — stays decimal(10,2); SaaS billing is
 *     integer-quantity by design (owner decision).
 *   - document_lines.*               — already decimal(15,4).
 *   - stock_*                        — already done by 2026_05_29_120000.
 *   - inventory_counting_items.*     — already decimal(15,4).
 */
return new class extends Migration
{
    /**
     * Tables and columns to widen, keyed by table name.
     * Values are [column => original_scale] for precise down() restoration.
     *
     * @var array<string, array<string, int>>
     */
    private const TARGETS = [
        // Cart: decimal(10,2) → decimal(15,4)
        'catalog_cart_items' => [
            'quantity' => 2,
        ],
        // Marketplace: decimal(10,2) → decimal(15,4)
        'marketplace_listings' => [
            'quantity_available' => 2,
            'min_order_quantity' => 2,
        ],
        'marketplace_order_lines' => [
            'quantity' => 2,
        ],
        // Pricing: decimal(10,2) → decimal(15,4)
        'price_list_items' => [
            'min_quantity' => 2,
            'max_quantity' => 2,
        ],
        // Workshop: decimal(12,3) → decimal(15,4) / decimal(10,3) → decimal(15,4)
        'workshop_work_order_lines' => [
            'quantity' => 3,
        ],
        'workshop_service_bundle_components' => [
            'quantity' => 3,
        ],
        // POS: decimal(10,3) → decimal(15,4)
        'pos_receipt_lines' => [
            'quantity' => 3,
        ],
        'pos_order_lines' => [
            'quantity' => 3,
        ],
        'pos_receipt_line_batch_allocations' => [
            'quantity' => 3,
        ],
    ];

    public function up(): void
    {
        // SQLite does not support ALTER COLUMN TYPE — column types are
        // effectively unchecked in SQLite, so this migration is a no-op for the
        // test database. Quantity precision is enforced there by the bcmath
        // scale and the `decimal:4` model casts instead.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TARGETS as $table => $columns) {
            $alterParts = [];
            foreach (array_keys($columns) as $column) {
                $alterParts[] = "ALTER COLUMN {$column} TYPE decimal(15, 4)";
            }

            DB::statement("ALTER TABLE {$table} ".implode(', ', $alterParts));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TARGETS as $table => $columns) {
            $alterParts = [];
            foreach ($columns as $column => $originalScale) {
                // Precision was 10 for most tables, 12 for workshop_work_order_lines.
                // Restore each column to its exact original definition.
                $originalPrecision = $table === 'workshop_work_order_lines' ? 12 : 10;
                $alterParts[] = "ALTER COLUMN {$column} TYPE decimal({$originalPrecision}, {$originalScale})";
            }

            DB::statement("ALTER TABLE {$table} ".implode(', ', $alterParts));
        }
    }
};
