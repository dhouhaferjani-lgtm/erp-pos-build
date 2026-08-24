<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\EnumCheckParityAnalyzer;
use Tests\Architecture\Support\PgValueSetCheckReader;
use Tests\TestCase;

/**
 * CONVENTION 08 LIVENESS for the enum<->CHECK parity ratchet.
 *
 * "A guard that cannot fail is not a guard." The two incidents conv. 08 records —
 * a detector regex that rotted and reported zero for months, and rules that had no
 * tamper test at all — are both reachable here: `PgValueSetCheckReader` is a regex
 * over `pg_get_constraintdef()` output, and PostgreSQL's rendering is not a stable
 * contract. If that parser silently stopped matching, EVERY column would read as
 * MISSING, every MISSING is already baselined, and the gate would go quietly dead
 * while staying green.
 *
 * So each of the three tamper cases the lane brief names is pinned as a POSITIVE
 * assertion that the guard FIRES:
 *
 *   removing a CHECK        -> a baselined-COVERED column becomes MISSING, whose key
 *                              is not in the baseline => NEW failure (and the stale
 *                              COVERED entry is not there to absorb it).
 *   widening a CHECK        -> verdict WIDER, a different key from the baselined one
 *                              => NEW failure, NOT absorbed by the MISSING entry.
 *   a new enum-backed column with no CHECK
 *                           -> a key the baseline has never seen => NEW failure.
 *
 * The analyzer cases are driver-independent ON PURPOSE: they are pure-function
 * tamper tests, so they fire in any lane, including the sqlite ones, and do not
 * depend on the PG service being present. The reader case is PG-only because
 * parsing `pg_constraint` is the thing it proves.
 */
final class EnumCheckParityDetectorLivenessTest extends TestCase
{
    /**
     * @return array{table: string, column: string, enum: class-string, cases: list<string>, backing: string, origin: string, model: string|null, scope: string}
     */
    private function column(string $table, string $column, array $cases): array
    {
        /** @var class-string $enum */
        $enum = 'Tests\\Fixture\\Enum\\'.$column;

        return [
            'table' => $table,
            'column' => $column,
            'enum' => $enum,
            'cases' => $cases,
            'backing' => 'string',
            'origin' => 'model-cast',
            'model' => 'Tests\\Fixture\\Model',
            'scope' => 'tenant',
        ];
    }

    // ---------------------------------------------------------------- tamper 1

    #[Test]
    public function removing_a_check_from_a_covered_column_fails_immediately(): void
    {
        $analyzer = new EnumCheckParityAnalyzer;
        $columns = [$this->column('pos_shifts', 'status', ['CLOSED', 'OPEN'])];
        $schema = ['pos_shifts' => ['status']];

        $withCheck = $analyzer->analyze(
            $columns,
            ['pos_shifts' => ['status' => ['accepted' => ['CLOSED', 'OPEN'], 'constraints' => ['pos_shifts_status'], 'nullable' => false]]],
            $schema,
        );
        $this->assertSame(EnumCheckParityAnalyzer::VERDICT_COVERED, $withCheck[0]['verdict']);
        $this->assertSame([], $analyzer->partition($withCheck, [])['new'], 'A covered column must not be reported.');

        // The CHECK is dropped. The baseline that exists on dev cannot absorb it:
        // a covered column has no baseline entry to begin with.
        $checkRemoved = $analyzer->analyze($columns, [], $schema);
        $partition = $analyzer->partition($checkRemoved, []);

        $this->assertSame(EnumCheckParityAnalyzer::VERDICT_MISSING, $checkRemoved[0]['verdict']);
        $this->assertSame(['pos_shifts.status::MISSING'], array_column($partition['new'], 'key'));
    }

    // ---------------------------------------------------------------- tamper 2

    #[Test]
    public function widening_a_check_beyond_its_enum_fails_immediately(): void
    {
        $analyzer = new EnumCheckParityAnalyzer;
        $columns = [$this->column('pos_shifts', 'status', ['CLOSED', 'OPEN'])];
        $schema = ['pos_shifts' => ['status']];

        $widened = $analyzer->analyze(
            $columns,
            ['pos_shifts' => ['status' => ['accepted' => ['CLOSED', 'OPEN', 'SUSPENDED'], 'constraints' => ['pos_shifts_status'], 'nullable' => false]]],
            $schema,
        );

        $this->assertSame(EnumCheckParityAnalyzer::VERDICT_WIDER, $widened[0]['verdict']);
        $this->assertSame(['SUSPENDED'], $widened[0]['extra']);
        $this->assertSame(['pos_shifts.status::WIDER'], array_column($analyzer->partition($widened, [])['new'], 'key'));
    }

    /**
     * The verdict-in-key design, pinned: a column already baselined as MISSING may
     * not smuggle in a WRONG CHECK under cover of its own baseline entry.
     */
    #[Test]
    public function a_baselined_missing_column_that_grows_a_wrong_check_still_fails(): void
    {
        $analyzer = new EnumCheckParityAnalyzer;
        $columns = [$this->column('payments', 'status', ['completed', 'pending'])];
        $schema = ['payments' => ['status']];
        $baseline = ['payments.status::MISSING'];

        $narrowed = $analyzer->analyze(
            $columns,
            ['payments' => ['status' => ['accepted' => ['pending'], 'constraints' => ['payments_status_check'], 'nullable' => false]]],
            $schema,
        );
        $partition = $analyzer->partition($narrowed, $baseline);

        $this->assertSame(EnumCheckParityAnalyzer::VERDICT_NARROWER, $narrowed[0]['verdict']);
        $this->assertSame(['payments.status::NARROWER'], array_column($partition['new'], 'key'));
        $this->assertSame(['payments.status::MISSING'], $partition['stale'], 'The MISSING entry must also go stale.');
    }

    // ---------------------------------------------------------------- tamper 3

    #[Test]
    public function a_new_enum_backed_column_with_no_check_fails_immediately(): void
    {
        $analyzer = new EnumCheckParityAnalyzer;
        $baseline = ['payments.status::MISSING'];
        $schema = ['payments' => ['status'], 'brand_new_table' => ['status']];

        $findings = $analyzer->analyze(
            [
                $this->column('payments', 'status', ['completed', 'pending']),
                $this->column('brand_new_table', 'status', ['a', 'b']),
            ],
            [],
            $schema,
        );
        $partition = $analyzer->partition($findings, $baseline);

        $this->assertSame(['brand_new_table.status::MISSING'], array_column($partition['new'], 'key'));
        $this->assertSame([], $partition['stale']);
    }

    // ------------------------------------------------------- baseline direction

    #[Test]
    public function a_closed_gap_makes_its_baseline_entry_stale_so_the_baseline_only_shrinks(): void
    {
        $analyzer = new EnumCheckParityAnalyzer;
        $findings = $analyzer->analyze(
            [$this->column('payments', 'status', ['completed', 'pending'])],
            ['payments' => ['status' => ['accepted' => ['completed', 'pending'], 'constraints' => ['payments_status_check'], 'nullable' => false]]],
            ['payments' => ['status']],
        );

        $partition = $analyzer->partition($findings, ['payments.status::MISSING']);

        $this->assertSame([], $partition['new']);
        $this->assertSame(['payments.status::MISSING'], $partition['stale']);
    }

    #[Test]
    public function a_registry_column_the_schema_does_not_have_is_never_silently_passed(): void
    {
        $analyzer = new EnumCheckParityAnalyzer;
        $findings = $analyzer->analyze(
            [$this->column('payments', 'ghost_status', ['a'])],
            [],
            ['payments' => ['status']],
        );

        $this->assertSame(EnumCheckParityAnalyzer::VERDICT_ABSENT, $findings[0]['verdict']);
        $this->assertSame(['payments.ghost_status::ABSENT'], array_column($analyzer->partition($findings, [])['new'], 'key'));
    }

    // --------------------------------------------------------- parser liveness

    /**
     * @return array<string, array{0: string, 1: array{column: string, values: list<string>, nullable: bool}|null}>
     */
    public static function constraintDefinitionProvider(): array
    {
        return [
            'plain varchar ANY(ARRAY) — the dominant shape' => [
                "CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'completed'::character varying])::text[])))",
                ['column' => 'status', 'values' => ['completed', 'draft'], 'nullable' => false],
            ],
            'nullable guard first — workshop_work_order_lines.core_deposit_status' => [
                "CHECK (((core_deposit_status IS NULL) OR ((core_deposit_status)::text = ANY ((ARRAY['outstanding'::character varying, 'returned'::character varying])::text[]))))",
                ['column' => 'core_deposit_status', 'values' => ['outstanding', 'returned'], 'nullable' => true],
            ],
            'nullable guard last' => [
                "CHECK ((((kind)::text = ANY ((ARRAY['a'::character varying])::text[])) OR (kind IS NULL)))",
                ['column' => 'kind', 'values' => ['a'], 'nullable' => true],
            ],
            'text-cast array, no varchar wrapper' => [
                "CHECK ((mode)::text = ANY (ARRAY['x'::text, 'y'::text]))",
                ['column' => 'mode', 'values' => ['x', 'y'], 'nullable' => false],
            ],
            'integer-backed enum' => [
                'CHECK ((level = ANY (ARRAY[1, 2, 3])))',
                ['column' => 'level', 'values' => ['1', '2', '3'], 'nullable' => false],
            ],
            'degenerate single value' => [
                "CHECK (((kind)::text = 'only'::text))",
                ['column' => 'kind', 'values' => ['only'], 'nullable' => false],
            ],
            // The carve-outs. These are NOT value-set constraints and must not be
            // folded into the parity comparison, or the gate becomes meaningless.
            'cross-column state invariant is NOT a value set — pos_shifts_closed_logic' => [
                "CHECK (((((status)::text = 'OPEN'::text) AND (closed_at IS NULL) AND (closed_by IS NULL)) OR (((status)::text = 'CLOSED'::text) AND (closed_at IS NOT NULL) AND (closed_by IS NOT NULL))))",
                null,
            ],
            'regex format check is NOT a value set — pos_terminals_code_format' => [
                "CHECK (((code)::text ~ '^[A-Za-z0-9\\-]{2,20}$'::text))",
                null,
            ],
            'numeric range check is NOT a value set' => [
                'CHECK (((max_discount_percent >= (0)::numeric) AND (max_discount_percent <= (100)::numeric)))',
                null,
            ],
            'length check is NOT a value set' => [
                'CHECK ((length(genesis_seed) = 64))',
                null,
            ],
            'a null guard naming a DIFFERENT column is NOT a single-column value set' => [
                "CHECK (((other_col IS NULL) OR ((status)::text = ANY ((ARRAY['a'::character varying])::text[]))))",
                null,
            ],
        ];
    }

    /**
     * @param  array{column: string, values: list<string>, nullable: bool}|null  $expected
     */
    #[Test]
    #[DataProvider('constraintDefinitionProvider')]
    public function the_constraint_parser_fires_on_every_shape_it_claims(string $definition, ?array $expected): void
    {
        $this->assertSame($expected, PgValueSetCheckReader::parseValueSet($definition));
    }

    // ---------------------------------------------------------- reader liveness

    /**
     * The regex-rot guard, end to end: plant a real CHECK on a real scratch table,
     * read it back through `pg_constraint`, then drop it and read again. If
     * PostgreSQL's rendering ever drifts away from what the parser expects, THIS
     * is the test that goes red — before the parity gate quietly reports every
     * column as MISSING and the baseline swallows it.
     */
    #[Test]
    public function the_pg_constraint_reader_actually_reads_a_planted_check(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped("Reading pg_constraint is PostgreSQL-only; driver is {$driver}.");
        }

        $table = 'enum_check_parity_liveness_probe';
        DB::statement("DROP TABLE IF EXISTS {$table}");
        DB::statement("CREATE TABLE {$table} (id int, status varchar(32), tone varchar(32) NULL, level int)");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_status_chk CHECK (status IN ('open', 'closed'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_tone_chk CHECK (tone IS NULL OR tone IN ('warm'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_level_chk CHECK (level IN (1, 2))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_shape_chk CHECK (id > 0)");

        try {
            $read = (new PgValueSetCheckReader)->read(DB::connection());

            $this->assertArrayHasKey($table, $read, 'The reader found NO value-set CHECK on a table that has three.');
            $this->assertSame(['closed', 'open'], $read[$table]['status']['accepted']);
            $this->assertSame([$table.'_status_chk'], $read[$table]['status']['constraints']);
            $this->assertSame(['warm'], $read[$table]['tone']['accepted']);
            $this->assertTrue($read[$table]['tone']['nullable']);
            $this->assertSame(['1', '2'], $read[$table]['level']['accepted']);
            $this->assertArrayNotHasKey('id', $read[$table], 'A non-value-set CHECK must not be read as one.');

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_status_chk");
            $afterDrop = (new PgValueSetCheckReader)->read(DB::connection());
            $this->assertArrayNotHasKey('status', $afterDrop[$table] ?? [], 'A dropped CHECK must disappear from the read.');
        } finally {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
    }
}
