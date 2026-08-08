<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document-per-action remediation, lane V8 — the supplier goods-return note.
 *
 * Before this table, `SupplierCreditNotePostingService::issueBonusReturnStock()`
 * raw-wrote a `stock_levels` decrement plus an `Issue` `stock_movements` row on a
 * MONEY document's lifecycle, with `reference_type = 'Document'` pointing at the
 * supplier credit note. Two things were wrong with that:
 *
 *  1. no document justified the stock exit — the credit note is an AP document,
 *     and using it as the movement's source is exactly the pattern this program
 *     is remediating;
 *  2. it stamped `avg_cost_before = avg_cost_after = current WAC`, asserting that
 *     returning a ZERO-COST bonus unit leaves the weighted average cost
 *     untouched. It does not: free units enter through
 *     `recordPurchase(landedUnitCost: '0')` (GoodsReceiptService) and DILUTE the
 *     WAC, so handing one back must UN-dilute it.
 *
 * Meanwhile ORDINARY goods-return lines moved no units at all — one credit-note
 * reason, two lane behaviours. This table is the single document both kinds of
 * return line now hang off.
 *
 * SHAPE DECISIONS (and the precedent each follows)
 *
 *  - Own table, NOT the unified `documents` table — the house keeps
 *    inventory-motion documents on their own tables (`goods_receipts`,
 *    `stock_transfers`); `documents` is the fiscal/AR/AP family.
 *  - `supplier_credit_note_id`, `po_line_id`, `goods_receipt_id`,
 *    `goods_receipt_line_id`, `product_id`, `variant_id` and `location_id` are
 *    plain UUIDs with NO foreign key, mirroring `goods_receipts.purchase_order_id`
 *    and `goods_receipt_lines.po_line_id` (2026_07_04_100000) — the sibling
 *    inventory-document tables resolve those application-side. Only the
 *    line -> note edge is a real FK, exactly as `goods_receipt_lines` constrains
 *    `goods_receipt_id`.
 *  - `note_number` is nullable and stamped on CONFIRM from `document_sequences`
 *    (key `supplier_goods_return_note`, prefix `SGR`), like `receipt_number` on
 *    `goods_receipts`. Uniqueness is per COMPANY, not per tenant, because the
 *    sequence is scoped per (company_id, type, year) — a tenant with two
 *    companies would otherwise collide on each company's first SGR-YYYY-0001.
 *  - One note per credit note, enforced by a PARTIAL unique index so a future
 *    manually-raised note (no credit note) is still legal. Postgres and SQLite
 *    both support partial indexes; `goods_receipt_lines_movement_id_unique` in
 *    2026_07_04_100000 is the in-repo precedent for the syntax.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Unattended-safe: a push to origin/dev auto-deploys `tenants:migrate`
        // with no manual prerequisite, so a partially applied batch re-run
        // against a tenant must no-op rather than throw "relation already
        // exists". Same self-guard idiom as
        // 2026_07_31_950000_create_fiscal_refund_compensations_table.php.
        //
        // The guard proves "a table by this name exists", NOT "a table of THIS
        // shape exists". That is the accepted house trade-off for a brand-new
        // table name no tenant can already carry; any later SHAPE change must
        // ship as its own additive migration with its own `Schema::hasColumn`
        // guard, never by editing this file (already-migrated tenants will
        // never re-run it).
        if (! Schema::hasTable('supplier_goods_return_notes')) {
            Schema::create('supplier_goods_return_notes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('company_id');
                // The AP document that CAUSED this return. Nullable so a
                // stand-alone (manually raised) goods-return note stays legal.
                $table->uuid('supplier_credit_note_id')->nullable();
                $table->uuid('partner_id')->nullable();
                // Stamped on confirm from document_sequences (SGR-YYYY-NNNN).
                $table->string('note_number', 30)->nullable();
                $table->string('status', 20);       // SupplierGoodsReturnNoteStatus
                // The exit location when the whole note leaves one place; the
                // authoritative per-line location lives on the line.
                $table->uuid('location_id')->nullable();
                // Human-readable label of the causing document, carried onto the
                // movement's free-text `reference` alongside the note number.
                $table->string('reference', 100)->nullable();
                $table->timestampTz('returned_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('confirmed_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestampsTz();

                $table->unique(['company_id', 'note_number']);
                $table->index('tenant_id');
                $table->index(['tenant_id', 'company_id']);
                $table->index('supplier_credit_note_id');
            });

            // One note per supplier credit note. Partial so any number of
            // stand-alone notes (NULL credit note) remain legal — multiple NULLs
            // are accepted by a plain UNIQUE in both engines, but being explicit
            // documents the intent and keeps the index narrow.
            DB::statement(
                'CREATE UNIQUE INDEX supplier_goods_return_notes_credit_note_unique
                    ON supplier_goods_return_notes (supplier_credit_note_id)
                    WHERE supplier_credit_note_id IS NOT NULL'
            );
        }

        if (! Schema::hasTable('supplier_goods_return_note_lines')) {
            Schema::create('supplier_goods_return_note_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('company_id');
                $table->foreignUuid('supplier_goods_return_note_id')
                    ->constrained('supplier_goods_return_notes')
                    ->restrictOnDelete();
                // The PO line the returned units were ordered/received against.
                $table->uuid('po_line_id');
                // The goods receipt(s) the units came in on, where resolvable.
                $table->uuid('goods_receipt_id')->nullable();
                $table->uuid('goods_receipt_line_id')->nullable();
                $table->uuid('product_id');
                $table->uuid('variant_id')->nullable();
                // SupplierGoodsReturnLineKind: ordinary | bonus. This is the
                // whole point of the table — the two kinds now share one
                // document and differ only by this discriminator.
                $table->string('kind', 16);
                $table->decimal('quantity', 15, 4);
                // WAC at the moment of exit, stamped on confirm at COST_SCALE=6
                // (mirrors goods_receipt_lines.landed_unit_cost precision).
                $table->decimal('unit_cost', 19, 6)->nullable();
                $table->uuid('location_id')->nullable();
                // The Issue movement this line produced.
                $table->uuid('movement_id')->nullable();
                // BONUS lines only: the quantity-neutral WAC un-dilution
                // movement that restores the value a zero-cost exit destroyed.
                $table->uuid('cost_adjustment_movement_id')->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'supplier_goods_return_note_id']);
                $table->index(['tenant_id', 'po_line_id']);
                $table->index('goods_receipt_line_id');
            });

            // A movement justifies exactly one note line. Partial because both
            // columns are NULL for the whole Draft window.
            DB::statement(
                'CREATE UNIQUE INDEX supplier_goods_return_note_lines_movement_unique
                    ON supplier_goods_return_note_lines (movement_id)
                    WHERE movement_id IS NOT NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX supplier_goods_return_note_lines_cost_movement_unique
                    ON supplier_goods_return_note_lines (cost_adjustment_movement_id)
                    WHERE cost_adjustment_movement_id IS NOT NULL'
            );

            // pgsql-only CHECKs on the two discriminators/quantities that the
            // domain guarantees. `kind` is deliberately NOT CHECKed against the
            // enum vocabulary: adding a case would then pass the whole sqlite
            // suite and fail on Postgres with 23514 in production (the V3 lane's
            // gate finding I4). The enum cast is the enforcement point.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement(
                    'ALTER TABLE supplier_goods_return_note_lines
                        ADD CONSTRAINT supplier_goods_return_note_lines_quantity_positive
                        CHECK (quantity > 0)'
                );
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS supplier_goods_return_note_lines_cost_movement_unique');
        DB::statement('DROP INDEX IF EXISTS supplier_goods_return_note_lines_movement_unique');
        Schema::dropIfExists('supplier_goods_return_note_lines');
        DB::statement('DROP INDEX IF EXISTS supplier_goods_return_notes_credit_note_unique');
        Schema::dropIfExists('supplier_goods_return_notes');
    }
};
