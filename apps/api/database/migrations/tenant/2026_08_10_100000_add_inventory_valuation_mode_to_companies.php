<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DPA Wave 3 · T7 / M2 — `companies.inventory_valuation_mode`.
 *
 * NULL means "inherit the country default" — the exact analogue of
 * `companies.payment_tolerance_percentage`, and the reason the column is
 * nullable rather than defaulted: a default would make every company an
 * explicit override and the country row would never be consulted.
 *
 * The CHECK admits `'periodic'` even though nothing implements it, so enabling
 * periodic valuation later needs NO DDL on a live tenant database. The refusal
 * lives in `InventoryValuationMode::isSupported()` and
 * `InventoryValuationModeResolver` (D-14's three-layer shape).
 *
 * Shape mirrors `2026_07_08_130000_add_discount_policy_columns.php`: nullable
 * string column + a pgsql-only named CHECK, both guarded so a re-run on a
 * partially-migrated tenant database is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'inventory_valuation_mode')) {
                $table->string('inventory_valuation_mode', 16)
                    ->nullable()
                    ->after('inventory_costing_method');
            }
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $exists = DB::selectOne(
            'select 1 as present from pg_constraint where conname = ?',
            ['companies_inventory_valuation_mode_valid'],
        );

        if ($exists !== null) {
            return;
        }

        DB::statement(
            "ALTER TABLE companies ADD CONSTRAINT companies_inventory_valuation_mode_valid
             CHECK (inventory_valuation_mode IS NULL OR inventory_valuation_mode IN ('perpetual', 'periodic'))"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_inventory_valuation_mode_valid');
        }

        Schema::table('companies', function (Blueprint $table): void {
            if (Schema::hasColumn('companies', 'inventory_valuation_mode')) {
                $table->dropColumn('inventory_valuation_mode');
            }
        });
    }
};
