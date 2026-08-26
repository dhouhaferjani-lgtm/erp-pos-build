<?php

declare(strict_types=1);

use Database\Seeders\UomSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * N-9 — give unit-less tenants the base unit-of-measure set.
 *
 * `units` / `unit_categories` are TENANT-SCOPED reference tables, and nothing on
 * the registration path ever seeded them: `UomSeeder` was reachable only from the
 * demo seeders (CoffeeShopSeeder, ParapharmacySeeder). The 2026-08-25 re-check's
 * day-one provisioning census recorded it plainly — `units 0` on a tenant created
 * through `POST /auth/register`.
 *
 * That is not cosmetic: `products.unit_id` FKs into this table and
 * `units.decimal_places` drives every quantity the operator sees or types
 * (QuantityScale::formatForUnit, the web `getQuantityDecimals`, the POS
 * QuantityInput). With an empty table no product can be given a unit at all.
 *
 * The registration path is fixed at the source in
 * `TenantInitializationService::seedReferenceData()`. THIS migration is the
 * backfill for tenants that were already provisioned without units.
 *
 * ## Self-guarding and idempotent
 *
 * `UomSeeder` writes with bare `create()` — it owns the category/base-unit cycle
 * (`unit_categories.base_unit_id` -> `units.id`) and cannot express that through
 * `updateOrCreate` — so a second run WOULD collide on `unit_categories.code` /
 * `units.code`. The guard therefore demands BOTH tables be empty: seeding units
 * into existing categories, or categories over existing units, is exactly the
 * half-state that collides. A tenant holding any row of either — including one
 * that has customised its units — is left completely alone.
 *
 * Re-running this migration on a tenant it already seeded is a no-op, which is
 * what makes it safe under the staging auto-deploy `tenants:migrate`.
 *
 * ## It only touches an ALREADY-PROVISIONED tenant
 *
 * A database with no `companies` row is not a tenant that lost its units — it is
 * a migration target that has not been provisioned yet. Under db-per-tenant the
 * tenant database is created and migrated BEFORE `TenantProvisioningService`
 * writes the company (topology contract §9.1 steps 2-4), and every fresh test
 * database is permanently in that state. Those get their units from
 * `TenantInitializationService::seedReferenceData()` moments later; seeding them
 * here as well would plant system rows into every fresh database and collide
 * with the `unit_categories.code` uniqueness that the UOM factories rely on.
 * This migration is the BACKFILL, and it backfills only what already exists.
 *
 * Per-tenant census (the answer does not change before or after — after the
 * migration it should report 0 rows):
 *
 *   SELECT (SELECT COUNT(*) FROM companies) AS companies,
 *          (SELECT COUNT(*) FROM units) AS units,
 *          (SELECT COUNT(*) FROM unit_categories) AS categories;
 *
 * `down()` is deliberately a no-op. Dropping seeded units would break
 * `products.unit_id` on any product that has since been given one, and this
 * migration cannot tell its own rows from an operator's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('units') || ! Schema::hasTable('unit_categories')) {
            return;
        }

        if (! Schema::hasTable('companies') || ! DB::table('companies')->exists()) {
            return;
        }

        if (DB::table('units')->exists() || DB::table('unit_categories')->exists()) {
            return;
        }

        (new UomSeeder)->run();
    }

    public function down(): void
    {
        // Intentionally irreversible — see the class docblock.
    }
};
