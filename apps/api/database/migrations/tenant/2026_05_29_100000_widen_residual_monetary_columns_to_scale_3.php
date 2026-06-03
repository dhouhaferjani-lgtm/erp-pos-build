<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen residual monetary columns from scale 2 to scale 3.
 *
 * Two columns were identified as scale-2 outliers after the main monetary
 * widening pass (which landed most currency columns at NUMERIC(N, 3)):
 *
 *   - services.tax_rate           NUMERIC(5, 2)  →  NUMERIC(6, 3)
 *     Tax rates are expressed as a percentage (e.g., 19.000 for Tunisia VAT).
 *     Widening the integer part by 1 (5→6) preserves the max value of 999.99%
 *     at 2dp; the extra scale digit brings it in line with Workshop and Document
 *     tax_rate columns (also NUMERIC(6, 3) elsewhere in the schema).
 *
 *   - loyalty_programs.welcome_bonus_points  NUMERIC(15, 2)  →  NUMERIC(15, 3)
 *     Points are a pseudo-monetary quantity subject to the same 3-decimal
 *     contract as other loyalty balance columns.  Keeping it at scale 2 would
 *     silently truncate fractional bonus awards produced by the earning-rule
 *     engine (e.g., 10.500 pts capped at 10.50 pts).
 *
 * PostgreSQL pads existing values with trailing zeros (19.00 → 19.000) — the
 * widening is non-destructive. SQLite does not enforce column types so this
 * migration is a no-op there; precision is enforced via bcmath scale and
 * application-level validation instead.
 *
 * Scope is limited to the two remaining scale-2 monetary outliers.  Quantity
 * columns are handled separately by the 2026_05_29_120000 migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite does not support ALTER COLUMN TYPE — column types are
        // effectively unchecked in SQLite, so this migration is a no-op for the
        // test database.  Monetary precision is enforced there by bcmath scale
        // and the `decimal:3` model casts instead.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE services ALTER COLUMN tax_rate TYPE NUMERIC(6, 3)');
        DB::statement('ALTER TABLE loyalty_programs ALTER COLUMN welcome_bonus_points TYPE NUMERIC(15, 3)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE services ALTER COLUMN tax_rate TYPE NUMERIC(5, 2)');
        DB::statement('ALTER TABLE loyalty_programs ALTER COLUMN welcome_bonus_points TYPE NUMERIC(15, 2)');
    }
};
