<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DPA V7 / T4 — the `stock_adjustments` document family.
 *
 * Aligns with .superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/plan-v7.md
 * (§3), which replaces the four raw `POST /stock-movements/*` writers with ONE
 * multi-line, lifecycle-tracked correction document:
 *
 * - `location_id` on the HEADER, `reason_code` on the LINE (D6), so a
 *   mixed-direction reconciliation is representable and the sign invariant is
 *   expressible as a DB CHECK;
 * - status lifecycle draft -> posted | cancelled (D5), numbering stamped at POST
 *   (D13), correction by contra document (D8);
 * - `batch_id` on the line (D1b): a lot-identified correction, so the aggregate
 *   and the lots move together.
 *
 * Out of scope (deferred, see plan §5):
 * - GL legs for adjustments (c1-bis / G1); every line posts unit_cost = NULL
 * - approval / tolerance ceilings (an orthogonal approval_* column set)
 * - the PostgreSQL immutability trigger (D10)
 * - backdating (D11): occurred_at is server now(), the FormRequest prohibits a
 *   client value, and the period guard is plan-cf T3's contract
 * - multi-lot FEFO allocation for one negative line (v1 is one line per lot)
 * - variant-scoped manual adjustment from the UI (the COLUMN ships here so no
 *   second migration is needed, but StockLevelData exposes no variant_id yet)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // tenant_id is a plain indexed uuid (NOT an FK): the `tenants` table
            // lives in the CENTRAL database, so under db-per-tenant a cross-DB FK
            // is impossible. Matches every sibling tenant table (companies,
            // products, stock_levels, ...). Covered by the tenant_id-leading
            // composite indexes below.
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();

            // NULL while draft; stamped at POST by DocumentNumberingService (D13).
            $table->string('adjustment_number', 30)->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('note')->nullable();

            $table->foreignUuid('location_id')->constrained('locations')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->string('idempotency_key', 128)->nullable();

            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Overriding an integrity guard must never be invisible (D1a / D15).
            $table->foreignUuid('stale_acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('reservations_ignored_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('stale_acknowledged_at')->nullable();
            $table->timestampTz('reservations_ignored_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Self-referencing FK added after the table exists.
            $table->uuid('corrects_adjustment_id')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'status'], 'stock_adjustments_company_status_idx');
            $table->index(['tenant_id', 'company_id', 'occurred_at'], 'stock_adjustments_company_occurred_idx');
            $table->index(['tenant_id', 'company_id', 'location_id'], 'stock_adjustments_company_location_idx');
        });

        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->foreign('corrects_adjustment_id', 'stock_adjustments_corrects_adjustment_id_foreign')
                ->references('id')->on('stock_adjustments')->restrictOnDelete();
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            // tenant_id is a plain indexed uuid (NOT an FK): the `tenants` table
            // lives in the CENTRAL database, so under db-per-tenant a cross-DB FK
            // is impossible. Matches every sibling tenant table (companies,
            // products, stock_levels, ...). Covered by the tenant_id-leading
            // composite indexes below.
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            // NOT VALID FK below (the 2026_06_09_120000 idiom): variant-bearing
            // products cannot be adjusted from the UI yet, but the column ships
            // so surfacing them needs no second migration.
            $table->uuid('variant_id')->nullable();
            // batch_id is an INTEGER FK: product_batches uses $table->id()
            // (2026_01_05_150000:14) with a separate public `uuid` column (:15).
            // The HTTP contract takes the uuid and resolves it server-side (D1b).
            $table->unsignedBigInteger('batch_id')->nullable();

            $table->string('reason_code', 50);
            $table->decimal('delta_quantity', 15, 4);      // SIGNED
            $table->decimal('observed_before', 15, 4);     // authoring snapshot (D15)
            $table->decimal('quantity_before', 15, 4)->nullable();  // as actually posted
            $table->decimal('quantity_after', 15, 4)->nullable();   // as actually posted
            $table->uuid('movement_id')->nullable();       // partial unique below
            $table->string('line_note', 255)->nullable();

            $table->timestampsTz();

            $table->foreign('batch_id', 'stock_adjustment_lines_batch_id_foreign')
                ->references('id')->on('product_batches')->restrictOnDelete();
            $table->index(['tenant_id', 'product_id'], 'stock_adjustment_lines_product_idx');
            $table->index(['tenant_id', 'adjustment_id'], 'stock_adjustment_lines_adjustment_idx');
            $table->index('batch_id', 'stock_adjustment_lines_batch_idx');
        });

        // Partial UNIQUE indexes are portable (the goods_receipt_lines
        // movement_id precedent, 2026_07_04_100000:61-70) — created on every
        // driver so SQLite CI enforces the same uniqueness PostgreSQL does.

        // One correction per (SKU, lot) per document. FOUR partial indexes
        // because BOTH variant_id and batch_id are nullable and PostgreSQL
        // treats NULLs as distinct (D1b part 5).
        DB::statement('CREATE UNIQUE INDEX stock_adjustment_lines_sku_unique_nv_nb ON stock_adjustment_lines (adjustment_id, product_id) WHERE variant_id IS NULL AND batch_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX stock_adjustment_lines_sku_unique_v_nb ON stock_adjustment_lines (adjustment_id, product_id, variant_id) WHERE variant_id IS NOT NULL AND batch_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX stock_adjustment_lines_sku_unique_nv_b ON stock_adjustment_lines (adjustment_id, product_id, batch_id) WHERE variant_id IS NULL AND batch_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX stock_adjustment_lines_sku_unique_v_b ON stock_adjustment_lines (adjustment_id, product_id, variant_id, batch_id) WHERE variant_id IS NOT NULL AND batch_id IS NOT NULL');

        // A DB-level "posted at most once per line" that survives a retried job
        // (the goods_receipt_lines.movement_id precedent, D10).
        DB::statement('CREATE UNIQUE INDEX stock_adjustment_lines_movement_id_unique ON stock_adjustment_lines (movement_id) WHERE movement_id IS NOT NULL');

        DB::statement('CREATE UNIQUE INDEX stock_adjustments_company_number_unique ON stock_adjustments (tenant_id, company_id, adjustment_number) WHERE adjustment_number IS NOT NULL');
        // One LIVE correction per document. `status <> 'cancelled'` is part of the
        // predicate, not an afterthought: abandoning a correction is an ordinary
        // action, and without it a cancelled contra sealed the original as
        // permanently uncorrectable. StockAdjustmentDocumentService::correct()
        // carries the same filter — a service check alone would only move the
        // failure down to this index.
        DB::statement("CREATE UNIQUE INDEX stock_adjustments_corrects_unique ON stock_adjustments (corrects_adjustment_id) WHERE corrects_adjustment_id IS NOT NULL AND status <> 'cancelled'");
        // Partial, for consistency with every other nullable-column uniqueness in
        // this file: stock_transfers uses a plain unique, which relies on PG's
        // NULLS DISTINCT default and would change meaning under NULLS NOT
        // DISTINCT (gate M-4).
        DB::statement('CREATE UNIQUE INDEX stock_adjustments_idempotency_unique ON stock_adjustments (tenant_id, company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_adjustment_lines ADD CONSTRAINT stock_adjustment_lines_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT VALID');

            DB::statement('ALTER TABLE stock_adjustment_lines ADD CONSTRAINT stock_adjustment_lines_delta_nonzero CHECK (delta_quantity <> 0)');

            // D6a (plan revision 3): these lists are FROZEN LITERALS,
            // deliberately NOT generated from
            // MovementReason::manualAdjustmentCases() at run time. A migration is
            // a point-in-time snapshot, and `tenants:migrate` runs this file at
            // each tenant's provisioning time — a later enum change would
            // otherwise give tenants provisioned after it a DIFFERENT CHECK, with
            // no migration recording the divergence. The derivation is asserted
            // by a unit test (T17 —
            // tests/Unit/Inventory/AdjustmentReasonSignPartitionTest.php) so an
            // enum change fails CI instead of forking the schema.
            DB::statement(<<<'SQL'
                ALTER TABLE stock_adjustment_lines ADD CONSTRAINT stock_adjustment_lines_reason_sign CHECK (
                    (reason_code IN ('adjustment_positive') AND delta_quantity > 0)
                 OR (reason_code IN ('adjustment_negative','damage','write_off') AND delta_quantity < 0))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_adjustment_lines DROP CONSTRAINT IF EXISTS stock_adjustment_lines_reason_sign');
            DB::statement('ALTER TABLE stock_adjustment_lines DROP CONSTRAINT IF EXISTS stock_adjustment_lines_delta_nonzero');
            DB::statement('ALTER TABLE stock_adjustment_lines DROP CONSTRAINT IF EXISTS stock_adjustment_lines_variant_id_foreign');
        }

        DB::statement('DROP INDEX IF EXISTS stock_adjustments_idempotency_unique');
        DB::statement('DROP INDEX IF EXISTS stock_adjustments_corrects_unique');
        DB::statement('DROP INDEX IF EXISTS stock_adjustments_company_number_unique');
        DB::statement('DROP INDEX IF EXISTS stock_adjustment_lines_movement_id_unique');
        DB::statement('DROP INDEX IF EXISTS stock_adjustment_lines_sku_unique_v_b');
        DB::statement('DROP INDEX IF EXISTS stock_adjustment_lines_sku_unique_nv_b');
        DB::statement('DROP INDEX IF EXISTS stock_adjustment_lines_sku_unique_v_nb');
        DB::statement('DROP INDEX IF EXISTS stock_adjustment_lines_sku_unique_nv_nb');

        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
    }
};
