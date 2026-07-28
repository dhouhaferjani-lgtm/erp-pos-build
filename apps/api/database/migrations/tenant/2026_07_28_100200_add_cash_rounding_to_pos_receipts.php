<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash-rounding Phase 1 / migration B (spec Rev 2.2 §4.5).
     *
     * 1. `pos_receipts.cash_rounding_adjustment` decimal(12,3) — SIGNED.
     *    12,3 is deliberate sibling-column consistency: `subtotal`,
     *    `tax_amount`, `discount_amount`, `total` and `change_due`
     *    (`2026_04_23_100000:24`) are all numeric(12,3). It is NOT the
     *    15,3 of `tolerance_writeoff` — the adjustment participates in the
     *    `pos_receipts_totals` arithmetic and must share the scale of the
     *    terms it is added to.
     *    `cash_rounding_denomination` decimal(15,4) mirrors the
     *    `country_payment_settings.cash_rounding_denomination` storage type
     *    so the projection's reconciliation comparison is a scale-4 bccomp,
     *    not a string compare.
     *
     * 2. Re-defines `pos_receipts_totals` so a rounded total satisfies the
     *    identity. COALESCE is LOAD-BEARING: without it the expression is
     *    NULL on every legacy row and PostgreSQL treats a NULL CHECK result
     *    as SATISFIED — the identity would silently stop being enforced for
     *    all pre-v3 data. Without the swap entirely, every rounded v3 sale
     *    fails its projection INSERT and dead-letters after 5 tries.
     *
     *    Backfill safety: any row that satisfied the OLD constraint
     *    (`total = subtotal + tax_amount - discount_amount`) has a NULL
     *    adjustment, so `COALESCE(...) = 0` and the new expression reduces
     *    to the old one. ADD CONSTRAINT therefore cannot fail on existing
     *    tenant data and needs no NOT VALID escape hatch.
     *
     * 3. Partial unique indexes for the two new journal `source_type`
     *    families (procurement exemplar `2026_06_26_120000:44-48` — UNSCOPED
     *    by status; the bridge creates + posts inside one transaction, there
     *    is no Draft re-insert path that would need the status scoping the
     *    treasury-transfer index uses). The legacy, already-populated
     *    `pos_payment_tolerance` source type is deliberately NOT covered.
     *
     * DRIVER GUARD: the whole constraint swap sits behind
     * `DB::connection()->getDriverName() === 'pgsql'` (`2026_03_09_200000:39`
     * precedent). SQLite has no `ALTER TABLE ... DROP CONSTRAINT` and
     * `pos_receipts_totals` only ever existed on pgsql, so an unguarded
     * statement would break every SQLite suite. The partial indexes are
     * intentionally NOT guarded (treasury exemplar `2026_07_12_100000:19-21`
     * — SQLite supports partial indexes and guarding would silently remove
     * index coverage from the sqlite fast loop).
     *
     * SIBLING CONSTRAINT AUDIT (read on the migrated schema before writing
     * this migration — all bind identities that rounding never touches, so
     * none needs to be re-defined here):
     *   pos_receipts:
     *     • pos_receipts_discount_positive   CHECK (discount_amount >= 0)
     *       — rounding never writes discount_amount.
     *     • pos_receipts_hash_length, _fiscal_status_check, _return_logic,
     *       _sequence, _void_logic, _year_range — non-monetary.
     *   pos_receipt_lines (`2026_03_09_200000:66-77`, later relaxed):
     *     • pos_receipt_lines_quantity CHECK (quantity <> 0)
     *     • pos_receipt_lines_amounts  CHECK (unit_price >= 0 AND
     *                                         discount_amount >= 0)
     *     • _sellable_xor, _variant_requires_product
     *       — rounding is a HEADER-level adjustment; it is never distributed
     *         to line rows, so no line identity moves.
     *   pos_receipt_vat_details (`2026_03_09_200000:52-62`):
     *     • pos_receipt_vat_details_gross_calc
     *       CHECK (gross_amount = net_amount + vat_amount)
     *       — rounding is NOT allocated across VAT buckets (it is a
     *         payment-time cash artefact, not a taxable-base change), so
     *         each bucket's internal identity is untouched. There is no
     *         cross-table CHECK binding SUM(vat gross) to `total`, so the
     *         intended `total ≠ Σ vat gross` gap of one adjustment breaks
     *         nothing at the DB layer.
     *   pos_receipt_payments:
     *     • pos_receipt_payments_amount CHECK (amount > 0) — TENDERED
     *       amounts, always positive; a negative adjustment never lands on a
     *       payment row.
     */
    public function up(): void
    {
        // Hoisted ABOVE the pos_receipts early return: the journal indexes are
        // independent of pos_receipts. A tenant DB that (transiently) lacks
        // pos_receipts would otherwise be recorded as having applied this
        // migration with the indexes permanently absent — a silent, invisible
        // loss of the idempotency guard the GL writers depend on.
        if (Schema::hasTable('journal_entries')) {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uniq_je_source_pos_cash_rounding
                    ON journal_entries (source_type, source_id)
                    WHERE source_type IN ('pos_cash_rounding', 'pos_cash_rounding_refund')
                SQL);

            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS uniq_je_source_pos_tolerance_bridge
                    ON journal_entries (source_type, source_id)
                    WHERE source_type = 'pos_tolerance_bridge'
                SQL);
        }

        if (! Schema::hasTable('pos_receipts')) {
            return;
        }

        Schema::table('pos_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_receipts', 'cash_rounding_adjustment')) {
                $table->decimal('cash_rounding_adjustment', 12, 3)
                    ->nullable()
                    ->after('tolerance_writeoff');
            }
            if (! Schema::hasColumn('pos_receipts', 'cash_rounding_denomination')) {
                $table->decimal('cash_rounding_denomination', 15, 4)
                    ->nullable()
                    ->after('cash_rounding_adjustment');
            }
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // DROP + ADD in the SAME migration: re-running is a no-op because
            // the drop is IF EXISTS and the re-added definition is identical.
            DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_totals');
            DB::statement(
                'ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_totals CHECK ('.
                'total = subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment, 0)'.
                ')'
            );

            DB::statement(
                'COMMENT ON COLUMN pos_receipts.cash_rounding_adjustment IS '.
                "'Signed cash-rounding adjustment (rounded_total - exact_total) from the signed v3 SALE_RECEIPT payload. NULL on v1/v2 rows.'"
            );
            DB::statement(
                'COMMENT ON COLUMN pos_receipts.cash_rounding_denomination IS '.
                "'Denomination that was applied, as signed by the device. Compared to live policy by bccomp, never string equality.'"
            );
        }
    }

    /**
     * ROLLBACK IS DELIBERATELY ASYMMETRIC — read before running this on a
     * tenant that has taken a rounded sale.
     *
     * The legacy identity is re-added `NOT VALID`. A plain ADD CONSTRAINT
     * makes PostgreSQL validate the expression against every existing row,
     * and any receipt with `cash_rounding_adjustment <> 0` fails
     * `total = subtotal + tax_amount - discount_amount` by construction — so
     * the rollback would abort with a check_violation the moment a single
     * rounded receipt exists, i.e. exactly during the incident that motivated
     * the rollback.
     *
     * `NOT VALID` is safe here because the direction that matters is still
     * fully enforced: PostgreSQL applies a NOT VALID CHECK to every
     * subsequent INSERT and UPDATE, and once the column is gone no new row
     * can carry an adjustment. Only the historical scan is skipped.
     *
     * SEMANTIC CONSEQUENCE: the rolled-back schema no longer validates the
     * legacy identity against historical rounded rows — those rows stay in
     * place and stay out of compliance with the restored expression. Fully
     * symmetric rollback would require deleting the rounded receipts, which
     * fiscal immutability forbids (`prevent_receipt_modification` blocks
     * DELETE on pos_receipts outright). Flag for the Task 13 deploy
     * checklist: rolling this migration back is a one-way door for the
     * totals invariant on already-rounded data; re-applying `up()` restores
     * full validation.
     */
    public function down(): void
    {
        if (Schema::hasTable('journal_entries')) {
            DB::statement('DROP INDEX IF EXISTS uniq_je_source_pos_cash_rounding');
            DB::statement('DROP INDEX IF EXISTS uniq_je_source_pos_tolerance_bridge');
        }

        if (! Schema::hasTable('pos_receipts')) {
            return;
        }

        // Restore the pre-rounding identity BEFORE dropping the column the
        // new expression references. NOT VALID — see the method docblock:
        // without it this statement aborts as soon as one rounded receipt
        // exists, which is precisely when a rollback would be attempted.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_totals');
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_totals CHECK (total = subtotal + tax_amount - discount_amount) NOT VALID');
        }

        $columns = array_values(array_filter(
            ['cash_rounding_adjustment', 'cash_rounding_denomination'],
            static fn (string $column): bool => Schema::hasColumn('pos_receipts', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('pos_receipts', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
