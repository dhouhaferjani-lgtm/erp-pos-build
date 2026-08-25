<?php

declare(strict_types=1);

use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherKind;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice D batch 1 — put the enum value domains of `vouchers` in the DATABASE.
 *
 * `vouchers` is a MONEY table: `current_balance` is spendable tender and
 * `status` is the only thing standing between a voided voucher and a redemption.
 * All four columns are written exclusively through Eloquent enum casts today
 * (`App\Modules\Voucher\Domain\Voucher::$casts`), which is an APPLICATION guard —
 * it survives neither a raw `UPDATE`, nor a backfill script, nor a psql session.
 *
 * IDIOM — N-6 (`2026_08_24_100100_add_status_check_constraint_to_documents.php`)
 * plus the NOT VALID / VALIDATE split this batch mandates:
 *
 *   1. a pre-flight census, INSIDE this migration, that ABORTS the tenant with a
 *      RuntimeException naming table, column, offending value and row count —
 *      rather than letting PostgreSQL fail the DDL with a message that names no
 *      row;
 *   2. `ADD CONSTRAINT … NOT VALID`;
 *   3. a SEPARATE `VALIDATE CONSTRAINT`.
 *
 * Step (1) is what guarantees step (3) cannot fail on a row: the census has
 * already proven the table clean under exactly the predicate the constraint
 * expresses.
 *
 * THE SPLIT BUYS NO LOCK RELIEF AS EXECUTED — DO NOT READ IT AS PERMISSION TO RUN
 * THIS HOT. `Illuminate\Database\Migrations\Migration::$withinTransaction`
 * defaults to TRUE and `Migrator.php:449` honours it on PostgreSQL, and none of
 * the five batch-1 migrations opts out. The census, `ADD CONSTRAINT … NOT VALID`
 * and `VALIDATE CONSTRAINT` therefore all run inside ONE transaction, so the
 * ACCESS EXCLUSIVE lock taken by the ADD is held until COMMIT — through the whole
 * validation scan. Proven by the r1 treasury gate: with that transaction open, a
 * plain `SELECT count(*) FROM payments` in a second session blocked until
 * `statement_timeout`. The net lock profile equals a plain `ADD CONSTRAINT`: full
 * table, ACCESS EXCLUSIVE, for the duration of the scan. Schedule the fleet run in
 * a maintenance window on any tenant whose tables are not small.
 *
 * THE TRANSACTION IS KEPT DELIBERATELY (parent ruling, r1 fix round): green-field
 * tenants with small tables buy nothing from lock relief, and ATOMICITY is worth
 * more — a failed census leaves ZERO constraints on the table, including any this
 * `up()` already created for an earlier column in the same loop. Both r1 gates
 * proved that live (planted dirt → RuntimeException → zero `chk_%_enum` rows). The
 * NOT VALID / VALIDATE split is retained only as the IDIOM for the day a tenant's
 * table is large enough to justify `public $withinTransaction = false;` — at which
 * point the split starts delivering lock relief AND the abort stops being atomic
 * (columns applied earlier in the loop survive, and the message names only the
 * failing one).
 *
 * NULLABLE COLUMNS are written `(col IS NULL OR col IN (…))` and never
 * `(col IS NOT NULL AND col IN (…))` — LEDGER C-37(iii). The `IS NOT NULL AND`
 * shape over-rejects (it forbids the NULL the column allows) and
 * `PgValueSetCheckReader` deliberately reads it as MISSING, so it would leave the
 * column counted as open debt in the parity register while breaking legitimate
 * writes. Single-column value sets ONLY: a compound CHECK is refused by the
 * parser by design (C-26 R2-1).
 *
 * PG-ONLY (`pgsql` guard) per the tenant-migration convention: the SQLite test
 * driver never sees it.
 *
 * MIGRATION-BEARING — these are CHECKs an existing row could violate. Per-tenant
 * census to run BEFORE the fleet migration (expect zero rows):
 *
 *   SELECT COALESCE(status, '<NULL>') AS value, COUNT(*)
 *     FROM vouchers
 *    WHERE status IS NULL OR status NOT IN ('issued', 'partially_redeemed', 'fully_redeemed', 'expired', 'voided')
 *    GROUP BY status;
 *
 *   SELECT COALESCE(source, '<NULL>') AS value, COUNT(*)
 *     FROM vouchers
 *    WHERE source IS NULL OR source NOT IN ('refund', 'exchange_surplus', 'goodwill', 'loyalty_credit', 'gift_card_purchase', 'promotional')
 *    GROUP BY source;
 *
 *   SELECT COALESCE(voucher_kind, '<NULL>') AS value, COUNT(*)
 *     FROM vouchers
 *    WHERE voucher_kind IS NULL OR voucher_kind NOT IN ('MPV', 'SPV')
 *    GROUP BY voucher_kind;
 *
 *   SELECT COALESCE(redemption_mode, '<NULL>') AS value, COUNT(*)
 *     FROM vouchers
 *    WHERE redemption_mode IS NULL OR redemption_mode NOT IN ('bearer', 'customer_bound')
 *    GROUP BY redemption_mode;
 *
 * A non-empty result means a raw writer exists and must be found before these
 * constraints are applied — which is the whole point of the census.
 *
 * FROZEN AT MIGRATION-RUN TIME. The value lists are derived from `Enum::cases()`
 * and never hand-listed, but they are MATERIALISED INTO SQL when THIS migration
 * runs on THIS tenant. The constraint does not track the enum afterwards: adding
 * a case later leaves every already-migrated tenant with the OLD constraint,
 * which will then reject the new value at INSERT time, per tenant, at runtime,
 * while the application happily accepts it. **A new case therefore needs its own
 * widening migration** (`DROP CONSTRAINT IF EXISTS` + `ADD CONSTRAINT`, the same
 * statements `up()` runs).
 *
 * `EnumCheckParityTest` DOES NOT ENFORCE THAT OBLIGATION — do not rely on it (r1
 * treasury C1 / fiscal F-2 corrected the earlier claim here). It runs under
 * `RefreshDatabase`, i.e. against a schema THIS migration just built, and the
 * migration re-derives its value list from `Enum::cases()` at migrate time, so on
 * a fresh schema the CHECK and the enum agree by construction, always. A case
 * added with NO widening migration therefore silently WIDENS every fresh schema
 * while every already-migrated tenant keeps the old CHECK and raises SQLSTATE
 * 23514 on the first row carrying the new value — a per-tenant runtime write bomb
 * with zero CI signal. Proven live at r1: a 6th `VoucherStatus` case with no
 * migration left `EnumCheckParityTest` (11/790) and
 * `SliceDBatch1CheckConstraintsTest` (19/70) fully GREEN. What actually enforces
 * the obligation is the per-batch freeze test
 * `tests/Feature/Treasury/SliceDBatch1EnumFreezeTest.php`, which pins the exact
 * case list this batch materialised into SQL and goes red the moment an enum moves
 * in EITHER direction.
 *
 * REMOVING A CASE IS EQUALLY MIGRATION-BEARING, and costs more than adding one.
 * Deleting a case (the live candidates are `JournalEntryStatus::Reversed`, program
 * spec §R-D3, and the dead `PaymentOrigin` cases, §R-D8) leaves the DB WIDER than
 * the enum, which the parity gate reports as a NEW failure — and it CANNOT be
 * baselined away, because the O-31 owner-pinned anti-growth ceiling refuses
 * baseline growth. The delete therefore costs a NARROWING migration plus a
 * per-tenant census, and any surviving row still carrying the removed value aborts
 * that tenant. LEDGER C-40 couples R-D2/R-D3/R-D8 to this batch so the owner
 * ruling is made with that cost visible.
 */
return new class extends Migration
{
    private const TABLE = 'vouchers';

    /**
     * column => [governing enum, is the column NULLABLE in the live schema].
     *
     * @var array<string, array{class-string<BackedEnum>, bool}>
     */
    private const COLUMNS = [
        'status' => [VoucherStatus::class, false],
        'source' => [VoucherSource::class, false],
        'voucher_kind' => [VoucherKind::class, false],
        'redemption_mode' => [RedemptionMode::class, false],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::COLUMNS as $column => [$enum, $nullable]) {
            $values = $this->quotedValues($enum);
            $constraint = self::constraintName($column);

            $violationPredicate = $this->violationPredicate($column, $values, $nullable);

            $violations = DB::select(
                "SELECT COALESCE({$column}, '<NULL>') AS offending_value, COUNT(*) AS violation_count
                   FROM ".self::TABLE."
                  WHERE {$violationPredicate}
                  GROUP BY {$column}"
            );

            if ($violations !== []) {
                $first = $violations[0];
                throw new RuntimeException(sprintf(
                    'Cannot create %s: %s.%s carries %s row(s) with the unknown value "%s". '
                    .'Find the writer before applying this constraint.',
                    $constraint,
                    self::TABLE,
                    $column,
                    (string) $first->violation_count,
                    (string) $first->offending_value,
                ));
            }

            $predicate = $this->checkPredicate($column, $values, $nullable);

            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS '.$constraint);
            DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.$constraint.' CHECK '.$predicate.' NOT VALID');
            DB::statement('ALTER TABLE '.self::TABLE.' VALIDATE CONSTRAINT '.$constraint);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (array_keys(self::COLUMNS) as $column) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS '.self::constraintName($column));
        }
    }

    private static function constraintName(string $column): string
    {
        return 'chk_'.self::TABLE.'_'.$column.'_enum';
    }

    /**
     * The PRE-FLIGHT CENSUS predicate — the rows this constraint would reject.
     *
     * For a NOT NULL column the census DELIBERATELY OVER-REJECTS relative to its
     * own constraint: `col IS NULL` counts as a violation even though the CHECK
     * would accept such a row. That is NOT a VALIDATE-safety measure — `col IN (…)`
     * evaluates to NULL for a NULL input, NULL is not FALSE, and PostgreSQL admits
     * it, so VALIDATE would not fail on it (r1 fiscal gate F-3 corrected the wrong
     * rationale previously stated here). It is a NOT-NULL-DRIFT DETECTOR: this
     * batch asserts at BUILD time that the column is NOT NULL, and if an earlier
     * migration quietly dropped that on some tenant, the operator is told loudly
     * instead of the batch constraining a column whose nullability it got wrong.
     *
     * For a NULLABLE column, NULL is legitimate and is excluded from the violation
     * set.
     */
    private function violationPredicate(string $column, string $values, bool $nullable): string
    {
        return $nullable
            ? "{$column} IS NOT NULL AND {$column} NOT IN ({$values})"
            : "{$column} IS NULL OR {$column} NOT IN ({$values})";
    }

    /**
     * The CHECK predicate. LEDGER C-37(iii): a nullable column is written
     * `(col IS NULL OR col IN (…))` — never `(col IS NOT NULL AND col IN (…))`,
     * which would forbid the NULL the column allows AND read as MISSING in the
     * parity register. Single column, single value set, no conjunction.
     */
    private function checkPredicate(string $column, string $values, bool $nullable): string
    {
        return $nullable
            ? "({$column} IS NULL OR {$column} IN ({$values}))"
            : "({$column} IN ({$values}))";
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    private function quotedValues(string $enum): string
    {
        return implode(', ', array_map(
            static fn (BackedEnum $case): string => "'".((string) $case->value)."'",
            $enum::cases(),
        ));
    }
};
