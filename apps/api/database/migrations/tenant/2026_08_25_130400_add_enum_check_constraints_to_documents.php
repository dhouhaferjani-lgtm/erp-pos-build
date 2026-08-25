<?php

declare(strict_types=1);

use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice D batch 1 — put the enum value domains of `documents` in the DATABASE.
 *
 * `documents.type` is the discriminator of the unified documents table: it
 * decides whether a row is an invoice, a credit note, a delivery note or a
 * purchase order, and therefore which fiscal chain, which stock direction and
 * which GL polarity apply to it. It is the last unconstrained member of the
 * `documents` enum family — `status` (N-6,
 * `2026_08_24_100100_add_status_check_constraint_to_documents.php`),
 * `fiscal_category` and `fiscal_status`
 * (`2025_12_11_054337_add_fiscal_constraints_to_documents.php:46,53`) are already
 * pinned in the database. This migration closes the family.
 *
 * SCOPE — VALUE DOMAIN ONLY, NOT THE ADJACENCY, exactly as N-6: which types may
 * convert into which is a service-layer question that would need the OLD row and
 * therefore a trigger. This constraint answers only "is this a document type
 * that exists?".
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
 *   SELECT COALESCE(type, '<NULL>') AS value, COUNT(*)
 *     FROM documents
 *    WHERE type IS NULL OR type NOT IN ('quote', 'sales_order', 'purchase_order', 'invoice', 'credit_note', 'delivery_note', 'return_note', 'expense', 'supplier_invoice', 'supplier_credit_note', 'income', 'purchase_rfq', 'correcting_entry')
 *    GROUP BY type;
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
    private const TABLE = 'documents';

    /**
     * column => [governing enum, is the column NULLABLE in the live schema].
     *
     * @var array<string, array{class-string<BackedEnum>, bool}>
     */
    private const COLUMNS = [
        'type' => [DocumentType::class, false],
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
