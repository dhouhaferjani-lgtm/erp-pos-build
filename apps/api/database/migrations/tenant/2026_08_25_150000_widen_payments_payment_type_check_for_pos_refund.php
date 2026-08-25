<?php

declare(strict_types=1);

use App\Modules\Treasury\Domain\Enums\PaymentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W4R2-2 — widen `chk_payments_payment_type_enum` for `PaymentType::POSRefund`.
 *
 * ## Why this migration exists
 *
 * `2026_08_25_130300_add_enum_check_constraints_to_payments` materialises the
 * `PaymentType` value list INTO SQL at the moment it runs on a given tenant, and
 * its own docblock states the consequence in full:
 *
 *   > FROZEN AT MIGRATION-RUN TIME. […] adding a case later leaves every
 *   > already-migrated tenant with the OLD constraint, which will then reject the
 *   > new value at INSERT time, per tenant, at runtime, while the application
 *   > happily accepts it. **A new case therefore needs its own widening
 *   > migration** (`DROP CONSTRAINT IF EXISTS` + `ADD CONSTRAINT`, the same
 *   > statements `up()` runs).
 *
 * That is this file. Without it, the first POS refund receipt synced on an
 * already-migrated tenant raises SQLSTATE 23514 inside
 * `TreasuryReceiptBridge::apply()` and the whole fiscal projection fails — a
 * per-tenant runtime write bomb with zero CI signal (the batch-1 parity test runs
 * under `RefreshDatabase`, so on a fresh schema the CHECK and the enum agree by
 * construction and stay green either way).
 *
 * The companion obligation the same docblock names — the per-batch freeze test
 * `tests/Feature/Treasury/SliceDBatch1EnumFreezeTest.php`, which pins the exact
 * case list — is discharged in the same commit.
 *
 * ## Idiom
 *
 * Identical to `up()` in `…130300`, deliberately: derive the value list from
 * `PaymentType::cases()` (never hand-list it), run the pre-flight census FIRST so
 * a `VALIDATE` can never fail on a row PostgreSQL would not name, then
 * `DROP CONSTRAINT IF EXISTS` + `ADD CONSTRAINT … NOT VALID` + `VALIDATE`.
 *
 * This widening is STRICTLY WIDER than the constraint it replaces (one value
 * added, none removed), so the census cannot find a row that the OLD constraint
 * admitted and the new one rejects. It is retained anyway, for the reason
 * `…130300` retains it: it is a raw-writer detector, and it is what makes the
 * `VALIDATE` provably safe rather than hopefully safe.
 *
 * ## Ordering
 *
 * Must run BEFORE `2026_08_25_150100_retype_supplier_and_pos_refund_payments`,
 * which writes `'pos_refund'` rows the pre-widening constraint would reject. The
 * filename timestamps encode that order.
 *
 * ## Lock profile — unchanged from `…130300`, read its warning
 *
 * `Migration::$withinTransaction` defaults to TRUE and is not overridden here, so
 * the census, the ADD and the VALIDATE all run in ONE transaction and the
 * ACCESS EXCLUSIVE lock is held through the validation scan. Same as a plain
 * `ADD CONSTRAINT`: full table, ACCESS EXCLUSIVE, for the duration. Schedule the
 * fleet run in a maintenance window on any tenant whose `payments` table is not
 * small.
 *
 * ## Idempotency
 *
 * `DROP CONSTRAINT IF EXISTS` + `ADD CONSTRAINT` is idempotent by construction. On
 * a FRESH schema `…130300` already derives the widened list from the same
 * `PaymentType::cases()`, so this migration re-creates a byte-identical
 * constraint — a deliberate no-op, not a redundancy.
 *
 * PG-ONLY (`pgsql` guard) per the tenant-migration convention: the SQLite test
 * driver never sees it, and SQLite has no CHECK to widen.
 *
 * MIGRATION-BEARING (schema). Per-tenant census to run BEFORE the fleet
 * migration — expect ZERO rows, since the change only ADDS a permitted value:
 *
 *   SELECT COALESCE(payment_type, '<NULL>') AS value, COUNT(*)
 *     FROM payments
 *    WHERE payment_type IS NULL
 *       OR payment_type NOT IN ('document_payment', 'advance', 'refund',
 *                               'credit_application', 'supplier_payment', 'pos',
 *                               'reversal', 'pos_refund')
 *    GROUP BY payment_type;
 *
 * A non-empty result means a raw writer exists and must be found before the
 * constraint is re-applied. FLEET-ABORT RISK: the RuntimeException below aborts
 * ONLY the offending tenant; tenants already processed keep the widened
 * constraint, and the run can be resumed after the writer is fixed.
 */
return new class extends Migration
{
    private const TABLE = 'payments';

    private const COLUMN = 'payment_type';

    private const CONSTRAINT = 'chk_payments_payment_type_enum';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $values = implode(', ', array_map(
            static fn (PaymentType $case): string => "'".$case->value."'",
            PaymentType::cases(),
        ));

        // NOT NULL column: `col IS NULL` is counted as a violation deliberately —
        // it is a NOT-NULL-DRIFT DETECTOR, not a VALIDATE-safety measure. Same
        // rationale, verbatim, as `…130300::violationPredicate()`.
        $violations = DB::select(
            'SELECT COALESCE('.self::COLUMN.", '<NULL>') AS offending_value, COUNT(*) AS violation_count
               FROM ".self::TABLE.'
              WHERE '.self::COLUMN.' IS NULL OR '.self::COLUMN.' NOT IN ('.$values.')
              GROUP BY '.self::COLUMN
        );

        if ($violations !== []) {
            $first = $violations[0];

            throw new RuntimeException(sprintf(
                'Cannot widen %s: %s.%s carries %s row(s) with the unknown value "%s". '
                .'Find the writer before re-applying this constraint.',
                self::CONSTRAINT,
                self::TABLE,
                self::COLUMN,
                (string) $first->violation_count,
                (string) $first->offending_value,
            ));
        }

        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::CONSTRAINT
            .' CHECK ('.self::COLUMN.' IN ('.$values.')) NOT VALID'
        );
        DB::statement('ALTER TABLE '.self::TABLE.' VALIDATE CONSTRAINT '.self::CONSTRAINT);
    }

    public function down(): void
    {
        // Intentionally NOT narrowed back. Re-materialising the seven-value list
        // would reject every `pos_refund` row this branch's backfill and writer
        // have already committed — the exact SQLSTATE 23514 bomb this migration
        // exists to defuse. To roll the case back, first retype those rows, then
        // re-run `…130300` (which derives the list from the enum as it then is).
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
    }
};
