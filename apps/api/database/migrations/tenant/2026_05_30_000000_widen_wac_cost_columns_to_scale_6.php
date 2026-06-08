<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen the WAC / cost-carrying decimal columns from scale 3 to a higher
 * internal cost precision: decimal(19, 6).
 *
 * WHY (NC 01 §62 + perpetual-WAC bias):
 * Carrying the weighted-average unit cost truncated to the currency scale at the
 * write boundary introduces a systematic DOWNWARD bias that compounds across
 * perpetual recomputes (each recompute reads an already-truncated cost). Tunisia
 * NC 01 §62 also forbids rounding "dans l'enregistrement des opérations" — rounding
 * is permitted only at presentation. We therefore carry the WAC unit cost at a
 * higher internal precision AT REST (6 dp — Dynamics-style headroom, matches the
 * ">= 4-6 dp carry cost" accounting best practice) and round HALF-UP to the
 * currency scale ONLY at the GL/COGS posting (and display) boundary.
 *
 * SCOPE: only the cost-carrying columns. Journal columns (journal_lines.debit/credit)
 * stay at the currency scale 3 — COGS is rounded at the posting boundary before it
 * lands there. Quantity columns are untouched. pos_receipt_lines.unit_cost is left
 * at (15,4): on a POS receipt line it is the recorded unit PRICE-side cost snapshot,
 * not the perpetual WAC carried on the product, so it is not widened here.
 *
 * PostgreSQL pads existing values with trailing zeros (1.240 → 1.240000) — no data
 * loss. SQLite is type-agnostic for decimals, so this is a no-op there (test DB).
 */
return new class extends Migration
{
    /**
     * Column definitions: [table => [[column, precision, scale], ...]]
     *
     * @var array<string, list<array{string, int, int}>>
     */
    private const COLUMNS = [
        // Products: cost_price is the perpetual WAC; last_purchase_cost is the
        // most recent landed unit cost. Both must carry full internal precision.
        'products' => [
            ['cost_price', 19, 6],
            ['last_purchase_cost', 19, 6],
        ],

        // Stock movements: unit_cost / total_cost / avg_cost_before / avg_cost_after
        // are the per-movement WAC ledger — the running cost trail.
        'stock_movements' => [
            ['unit_cost', 19, 6],
            ['total_cost', 19, 6],
            ['avg_cost_before', 19, 6],
            ['avg_cost_after', 19, 6],
        ],

        // Document lines: allocated_costs + landed_unit_cost feed the WAC on a
        // purchase receipt; carry them at the internal cost precision so the
        // landed unit cost that flows into recordPurchase() is not pre-truncated.
        'document_lines' => [
            ['allocated_costs', 19, 6],
            ['landed_unit_cost', 19, 6],
        ],
    ];

    /** Previous scale (set by the scale-3 widening migration). */
    private const PREVIOUS_SCALE = 3;

    public function up(): void
    {
        // SQLite does not enforce decimal precision/scale — no-op for the test DB.
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

        // Revert to the prior scale. Note: PostgreSQL will ROUND (not truncate)
        // values that no longer fit scale 3 on the way down; this is acceptable
        // for a rollback path and only affects the sub-millième digits that did
        // not exist before this migration ran.
        foreach (self::COLUMNS as $table => $columns) {
            $alterParts = [];
            foreach ($columns as [$column]) {
                $alterParts[] = "ALTER COLUMN {$column} TYPE decimal(12, ".self::PREVIOUS_SCALE.')';
            }

            if ($alterParts !== []) {
                DB::statement("ALTER TABLE {$table} ".implode(', ', $alterParts));
            }
        }
    }
};
