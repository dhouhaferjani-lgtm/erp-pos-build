<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice D batch 1 — put the enum value domains of `journal_entries` in the DATABASE.
 *
 * `journal_entries.status` is the posted/reversed discriminator every GL report
 * and every period close filters on; `journal_code` is the French/Tunisian
 * statutory journal (VT/AC/BQ/CA/EF/OD) that the legal journals are grouped by.
 * A value outside either set is not a display bug — it silently drops entries out
 * of a statutory report.
 *
 * `journal_code` is NULLABLE in the live schema (it was added after the table and
 * legacy entries carry NULL), so its CHECK is written `(journal_code IS NULL OR
 * journal_code IN (…))` per the C-37(iii) authoring rule. `IS NOT NULL AND …` is
 * NOT used: the parity parser reads that shape as MISSING because it
 * over-rejects, and the register would keep counting this column as debt.
 *
 * IDIOM — N-6 (`2026_08_24_100100_add_status_check_constraint_to_documents.php`)
 * plus the NOT VALID / VALIDATE split this batch mandates:
 *
 *   1. a pre-flight census, INSIDE this migration, that ABORTS the tenant with a
 *      RuntimeException naming table, column, offending value and row count —
 *      rather than letting PostgreSQL fail the DDL with a message that names no
 *      row;
 *   2. `ADD CONSTRAINT … NOT VALID` — takes only a brief ACCESS EXCLUSIVE lock
 *      and does not scan the table;
 *   3. a SEPARATE `VALIDATE CONSTRAINT` — SHARE UPDATE EXCLUSIVE, does not block
 *      readers or writers while it scans.
 *
 * Step (1) is what guarantees step (3) cannot fail on a row: the census has
 * already proven the table clean under exactly the predicate the constraint
 * expresses.
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
 *     FROM journal_entries
 *    WHERE status IS NULL OR status NOT IN ('draft', 'posted', 'reversed')
 *    GROUP BY status;
 *
 *   SELECT COALESCE(journal_code, '<NULL>') AS value, COUNT(*)
 *     FROM journal_entries
 *    WHERE journal_code IS NOT NULL AND journal_code NOT IN ('VT', 'AC', 'BQ', 'CA', 'EF', 'OD')
 *    GROUP BY journal_code;
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
 * statements `up()` runs). `EnumCheckParityTest` fails the moment the two
 * disagree, so the obligation is enforced, not merely documented.
 */
return new class extends Migration
{
    private const TABLE = 'journal_entries';

    /**
     * column => [governing enum, is the column NULLABLE in the live schema].
     *
     * @var array<string, array{class-string<BackedEnum>, bool}>
     */
    private const COLUMNS = [
        'status' => [JournalEntryStatus::class, false],
        'journal_code' => [JournalCode::class, true],
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
     * For a NOT NULL column, NULL itself is a violation and is reported as such
     * (`COALESCE(col, '<NULL>')`), so that a column PostgreSQL still allows to be
     * NULL — because an earlier migration dropped the NOT NULL and nobody noticed —
     * aborts the tenant here instead of failing VALIDATE. For a NULLABLE column,
     * NULL is legitimate and is excluded from the violation set.
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
