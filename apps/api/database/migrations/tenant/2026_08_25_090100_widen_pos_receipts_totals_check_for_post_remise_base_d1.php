<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * D-1 (owner ruling 2026-08-25) — `pos_receipts_totals` must admit BOTH the
 * pre-D-1 and the post-remise arithmetic.
 *
 * Before D-1 the header aggregates were sealed on the PRE-discount base, so
 * the identity was
 *
 *     total = subtotal + tax_amount - discount_amount + rounding
 *
 * (the discount subtracted, because `subtotal`/`tax_amount` still contained
 * it). At `event_version = 5` the base is already NET of the remise, so the
 * identity becomes
 *
 *     total = subtotal + tax_amount + rounding
 *
 * with `discount_amount` recorded alongside as the remise actually granted.
 *
 * BOTH forms have to pass, at the same time, forever:
 *   - historical rows and rows projected from v1..v4 events (a device still on
 *     an older build — the cutover is FORWARD-ONLY) satisfy the first;
 *   - rows projected from v5 events satisfy the second.
 * With `discount_amount = 0` the two coincide, which is every undiscounted
 * receipt ever written — so this widening is a no-op for the overwhelming
 * majority of rows and never weakens them.
 *
 * The disjunction is the honest expression of "two shapes, distinguished by an
 * event_version this table does not carry". A CHECK cannot read
 * `fiscal_events.event_version`; the tight per-version identity is enforced
 * upstream and unconditionally by
 * `FiscalPayloadConstraintValidator::validateSaleReceiptAggregateConsistency()`,
 * which DOES know the version and refuses either shape declared under the
 * wrong one. This constraint stays as the storage-level backstop it has always
 * been.
 *
 * MIGRATION-BEARING. Pre-flight census, per tenant DB — a constraint is being
 * REPLACED, so prove first that every existing row satisfies the NEW (wider)
 * predicate, which it must by construction since the new predicate is a strict
 * superset of the old one:
 *
 *   SELECT count(*) FROM pos_receipts
 *    WHERE NOT (
 *          total = subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment, 0)
 *       OR total = subtotal + tax_amount + COALESCE(cash_rounding_adjustment, 0)
 *    );
 *   -- MUST be 0. A non-zero count means rows already violate the OLD
 *   -- constraint (only possible if it was previously left NOT VALID with
 *   -- residue), and the VALIDATE below would abort the deploy for that tenant.
 *
 * The same NOT VALID + savepoint-protected VALIDATE dance as
 * `2026_07_28_100200_add_cash_rounding_to_pos_receipts` is used, for the same
 * reason: a tenant carrying pre-existing residue must degrade to an unvalidated
 * constraint (which still guards every NEW row) rather than abort the whole
 * fleet migration.
 */
return new class extends Migration
{
    private const PRE_D1 = 'total = subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment, 0)';

    private const POST_D1 = 'total = subtotal + tax_amount + COALESCE(cash_rounding_adjustment, 0)';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_totals');
        DB::statement(
            'ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_totals CHECK ('
            .'('.self::PRE_D1.') OR ('.self::POST_D1.')'
            .') NOT VALID'
        );

        $this->validateOrLeaveUnvalidated();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_totals');
        // Restoring the NARROWER predicate would refuse every v5 row already
        // projected, so it is added NOT VALID and deliberately left that way:
        // a rollback must not fail on data the forward migration legitimised.
        DB::statement(
            'ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_totals CHECK ('
            .self::PRE_D1
            .') NOT VALID'
        );
    }

    private function validateOrLeaveUnvalidated(): void
    {
        try {
            DB::transaction(function (): void {
                DB::statement('ALTER TABLE pos_receipts VALIDATE CONSTRAINT pos_receipts_totals');
            });
        } catch (QueryException $e) {
            // 23514 = check_violation: this tenant already carried residue rows
            // under the OLD constraint. Leave the constraint NOT VALID — it
            // still guards every new row — rather than abort the fleet.
            // Anything else (42704, lock_timeout, connection loss) must fail so
            // the deploy stays retryable.
            if (! str_contains((string) $e->getCode(), '23514')) {
                throw $e;
            }
        }
    }
};
