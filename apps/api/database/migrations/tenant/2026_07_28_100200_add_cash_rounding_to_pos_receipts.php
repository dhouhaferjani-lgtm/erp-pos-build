<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     *    The constraint is ALWAYS added `NOT VALID` and then immediately
     *    VALIDATEd in a savepoint-protected try/catch. Net effect on a
     *    first apply is identical to a plain validating ADD: every row
     *    that satisfied the OLD constraint has a NULL adjustment, so
     *    `COALESCE(...) = 0` and the new expression reduces to the old one
     *    — VALIDATE succeeds and `convalidated` is true.
     *
     *    The try/catch exists for the RE-APPLY-AFTER-ROLLBACK path, which a
     *    plain validating ADD cannot survive: `down()` DROPS
     *    `cash_rounding_adjustment`, destroying the values, so a rounded
     *    receipt is left as bare `total 9.950 / subtotal 9.973` residue that
     *    satisfies NEITHER identity. A validating ADD would then 23514 and
     *    hard-fail `tenants:migrate` for that tenant with no in-code remedy.
     *    Instead the constraint stays NOT VALID (still enforced on every new
     *    INSERT/UPDATE — only the historical scan is skipped) and a warning
     *    names the manual remedy: backfill `cash_rounding_adjustment` from
     *    `canonical_bytes`, then `ALTER TABLE pos_receipts VALIDATE
     *    CONSTRAINT pos_receipts_totals`. Task 13 deploy-checklist item.
     *
     *    The catch is NARROW — SQLSTATE 23514 (check_violation) ONLY. That
     *    is the single failure mode the residue produces and the only one
     *    for which "leave it NOT VALID and warn" is the right answer.
     *    Anything else (42704 wrong constraint name, 55P03/lock_timeout,
     *    connection loss) RETHROWS and fails the migration, so the deploy
     *    stops and is retryable — swallowing those would record the
     *    migration as applied with the constraint silently NOT VALID
     *    forever, under a warning naming a cause that never happened.
     *
     *    That one swallowed 23514 still aborts the surrounding transaction
     *    in PostgreSQL, and Laravel runs PG migrations inside one
     *    (`Migration::$withinTransaction = true` + `PostgresGrammar
     *    ::$transactions = true`), so the VALIDATE is wrapped in
     *    `DB::transaction()`: nested inside an open transaction it issues a
     *    SAVEPOINT and rolls back only to it. A bare try/catch would leave
     *    the migration's transaction poisoned.
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
                ') NOT VALID'
            );

            // Savepoint-protected: see the docblock. On a first apply this
            // succeeds and the constraint ends up fully validated, exactly as
            // a plain validating ADD would have left it.
            try {
                DB::transaction(function (): void {
                    DB::statement('ALTER TABLE pos_receipts VALIDATE CONSTRAINT pos_receipts_totals');
                });
            } catch (QueryException $e) {
                // 23514 = check_violation, i.e. the residue rows. Everything
                // else (42704 wrong constraint name, lock_timeout, connection
                // loss) must fail the migration so the deploy is retryable —
                // see the docblock.
                if ((string) $e->getCode() !== '23514') {
                    throw $e;
                }

                Log::warning(
                    'pos_receipts_totals left NOT VALID: existing rows violate the rounding-aware identity. '
                        .'This is the re-apply-after-rollback path — down() dropped cash_rounding_adjustment and '
                        .'the values are gone. New writes ARE still enforced. Remedy: backfill '
                        .'pos_receipts.cash_rounding_adjustment from canonical_bytes, then run '
                        .'"ALTER TABLE pos_receipts VALIDATE CONSTRAINT pos_receipts_totals".',
                    ['exception' => $e->getMessage()],
                );
            }

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
     * DELETE on pos_receipts outright).
     *
     * AND IT IS LOSSY: dropping `cash_rounding_adjustment` DESTROYS the
     * adjustment values. A rounded receipt is left as bare
     * `total 9.950 / subtotal 9.973` residue that satisfies neither the
     * legacy identity nor the rounding-aware one. Re-applying `up()`
     * therefore does NOT restore full validation — it re-adds an all-NULL
     * column, and `up()`'s VALIDATE attempt fails on exactly those residue
     * rows, leaving the rounding-aware constraint NOT VALID with a logged
     * warning. `up()` still SUCCEEDS (that is what its savepointed try/catch
     * is for); only the historical scan stays skipped.
     *
     * Full recovery is a manual, out-of-migration operation: backfill
     * `cash_rounding_adjustment` from each receipt's `canonical_bytes`, then
     * `ALTER TABLE pos_receipts VALIDATE CONSTRAINT pos_receipts_totals`.
     * Task 13 deploy-checklist item: rolling this migration back on a tenant
     * with rounded sales is a one-way door for the totals invariant until
     * that backfill is run.
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
