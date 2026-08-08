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
 *    (sequence key `supplier_return`, prefix `SGR`), like `receipt_number` on
 *    `goods_receipts`. The key is 15 chars because `document_sequences.type` is
 *    `varchar(20)` (2025_11_30_080002:16) — the obvious
 *    `supplier_goods_return_note` is 26 and throws SQLSTATE[22001] on PostgreSQL
 *    while staying green on the SQLite test runner. `SupplierGoodsReturnNoteService::SEQUENCE_KEY`
 *    is the single source of truth; this line only mirrors it.
 *    Uniqueness of the resulting number is per COMPANY, not per tenant, because
 *    the sequence is scoped per (company_id, type, year) — a tenant with two
 *    companies would otherwise collide on each company's first SGR-YYYY-0001.
 *  - One note per credit note, enforced by a PARTIAL unique index so a future
 *    manually-raised note (no credit note) is still legal. Postgres and SQLite
 *    both support partial indexes; `goods_receipt_lines_movement_id_unique` in
 *    2026_07_04_100000 is the in-repo precedent for the syntax. Every index is
 *    created with IF NOT EXISTS OUTSIDE the `hasTable` guard, so a run that dies
 *    between `Schema::create` and the index statements self-heals on the next
 *    `tenants:migrate` instead of leaving the table without its stated backstop
 *    forever (gate round 1, M-4).
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
                // BONUS lines: the per-unit price actually PAID on the receipt
                // that brought the units in, and therefore the ceiling the WAC
                // un-dilution may never push products.cost_price above (gate
                // round 1, C-1). Captured at DRAFT time and persisted, because a
                // Draft can sit for days before confirm and the receipt's price
                // is a fact about the past, not about the moment of exit.
                $table->decimal('unit_cost_ceiling', 19, 6)->nullable();
                $table->uuid('location_id')->nullable();
                // The Issue movement this line produced.
                $table->uuid('movement_id')->nullable();
                // BONUS lines only: the quantity-neutral WAC un-dilution
                // movement that restores the value a zero-cost exit destroyed.
                $table->uuid('cost_adjustment_movement_id')->nullable();
                // The bounded WAC un-dilution audit pair (gate round 1, C-1/I-5).
                // BONUS lines only. `applied` is what was actually capitalized
                // back onto the surviving units; `forgone` is the remainder that
                // could NOT be — because the dilution had already left through
                // COGS with units issued since the bonus receipt, or because no
                // survivor remains at all. A full bonus return therefore records
                // `applied = 0` and the whole amount as `forgone`, instead of the
                // unexplained NULL `cost_adjustment_movement_id` the gate flagged.
                // c1-bis reads `forgone` for the P&L leg this lane must not post.
                $table->decimal('wac_undilution_applied', 19, 6)->nullable();
                $table->decimal('wac_undilution_forgone', 19, 6)->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'supplier_goods_return_note_id']);
                $table->index(['tenant_id', 'po_line_id']);
                $table->index('goods_receipt_line_id');
            });

            // pgsql-only CHECK on the one invariant the domain guarantees.
            // `kind` is deliberately NOT CHECKed against the enum vocabulary:
            // adding a case would then pass the whole sqlite suite and fail on
            // Postgres with 23514 in production (the V3 lane's gate finding I4).
            // The enum cast is the enforcement point.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement(
                    'ALTER TABLE supplier_goods_return_note_lines
                        ADD CONSTRAINT supplier_goods_return_note_lines_quantity_positive
                        CHECK (quantity > 0)'
                );
            }
        }

        // Indexes live OUTSIDE the hasTable guards, with IF NOT EXISTS (gate
        // round 1, M-4). Creating them inside meant a run that died between
        // `Schema::create` and these statements left the table permanently
        // without the one-note-per-credit-note backstop — and the re-run skipped
        // straight past it. Out here, every `tenants:migrate` re-asserts them.
        //
        // One note per supplier credit note. Partial so any number of
        // stand-alone notes (NULL credit note) remain legal.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS supplier_goods_return_notes_credit_note_unique
                ON supplier_goods_return_notes (supplier_credit_note_id)
                WHERE supplier_credit_note_id IS NOT NULL'
        );
        // A movement justifies exactly one note line. Partial because both
        // columns are NULL for the whole Draft window.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS supplier_goods_return_note_lines_movement_unique
                ON supplier_goods_return_note_lines (movement_id)
                WHERE movement_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS supplier_goods_return_note_lines_cost_movement_unique
                ON supplier_goods_return_note_lines (cost_adjustment_movement_id)
                WHERE cost_adjustment_movement_id IS NOT NULL'
        );
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
