<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\CountryInventoryDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane P-1 — count-correction GL posting is SEEDED ON (owner ruling 2026-08-25).
 *
 * Supersedes the OQ-12/H-5 deploy-time blocker recorded in
 * `docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-19-m5-oq12-gate.md`:
 * perpetual inventory means a stock-take difference must reach the ledger, and
 * the expert-comptable reviews the Option A account choice (6586 shortage /
 * 7586 overage) later at onboarding rather than as a gate before the flip.
 *
 * ## Shape — two grains, only one of which this migration ever writes
 *
 *  * `country_inventory_settings.count_correction_gl_posting_enabled`
 *    NOT NULL, DEFAULT true — the seeded JURISDICTION default. Pinned by
 *    `CountryInventorySettingsSeeder` on every provisioning run, exactly like
 *    the sibling `inventory_valuation_mode`: the country row is not operator
 *    state.
 *  * `companies.count_correction_gl_posting_enabled`
 *    NULLABLE, UNDEFAULTED — the TENANT override, edited through
 *    `PATCH /settings/company`. **NULL means "never decided"**, a non-NULL
 *    boolean means an operator decided.
 *
 * That nullability IS the "explicitly set" predicate the flip needs — the same
 * representation `inventory_valuation_mode` already uses (DPA Wave 3 T9), so
 * nothing has to be inferred. This migration therefore **never writes the
 * companies column at all**: a tenant that set `false` keeps `false`, and a
 * tenant that never touched it keeps NULL and re-inherits any future country
 * ruling instead of being frozen at today's answer.
 *
 * ## Self-guarding and idempotent
 *
 * Every step is `hasTable`/`hasColumn`-guarded and re-runnable:
 *  1. add the country column (PostgreSQL backfills existing rows from the
 *     DEFAULT in the same `ALTER TABLE`, so step 3 is a no-op on a first run);
 *  2. add the nullable company column;
 *  3. raise ONLY the country rows that still carry the pre-ruling `false`, and
 *     only for countries whose pinned map says ON — a future country ruling of
 *     `false` is expressed in `CountryInventoryDefaults` and is not stomped
 *     here.
 *
 * MIGRATION-BEARING but fleet-safe: additive DDL, one narrow UPDATE, no
 * constraint that existing rows could violate. Per-tenant census (what the
 * flip will touch, run before or after — the answer does not change):
 *
 *   SELECT country_code, count_correction_gl_posting_enabled
 *   FROM country_inventory_settings
 *   WHERE count_correction_gl_posting_enabled = false;
 *
 *   SELECT id, name, count_correction_gl_posting_enabled
 *   FROM companies
 *   WHERE count_correction_gl_posting_enabled IS NOT NULL;   -- NEVER touched
 *
 * `down()` drops both columns. It does not restore the pre-ruling `false`,
 * because dropping the column removes the setting entirely and the resolver
 * falls back to `config('inventory.count_correction_gl_posting_enabled')`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('country_inventory_settings')
            && ! Schema::hasColumn('country_inventory_settings', 'count_correction_gl_posting_enabled')) {
            Schema::table('country_inventory_settings', function (Blueprint $table): void {
                $table->boolean('count_correction_gl_posting_enabled')->default(true);
            });
        }

        if (Schema::hasTable('companies')
            && ! Schema::hasColumn('companies', 'count_correction_gl_posting_enabled')) {
            Schema::table('companies', function (Blueprint $table): void {
                // Nullable AND undefaulted on purpose — see the class docblock.
                $table->boolean('count_correction_gl_posting_enabled')->nullable();
            });
        }

        if (! Schema::hasTable('country_inventory_settings')
            || ! Schema::hasColumn('country_inventory_settings', 'count_correction_gl_posting_enabled')) {
            return;
        }

        $countriesToEnable = array_keys(array_filter(
            CountryInventoryDefaults::allCountCorrectionGlPosting(),
            static fn (bool $enabled): bool => $enabled,
        ));

        if ($countriesToEnable === []) {
            return;
        }

        DB::table('country_inventory_settings')
            ->whereIn('country_code', $countriesToEnable)
            ->where('count_correction_gl_posting_enabled', false)
            ->update([
                'count_correction_gl_posting_enabled' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('country_inventory_settings')
            && Schema::hasColumn('country_inventory_settings', 'count_correction_gl_posting_enabled')) {
            Schema::table('country_inventory_settings', function (Blueprint $table): void {
                $table->dropColumn('count_correction_gl_posting_enabled');
            });
        }

        if (Schema::hasTable('companies')
            && Schema::hasColumn('companies', 'count_correction_gl_posting_enabled')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('count_correction_gl_posting_enabled');
            });
        }
    }
};
