<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\EnumBackedColumnRegistry;
use Tests\Architecture\Support\EnumCheckParityAcknowledgements;
use Tests\Architecture\Support\EnumCheckParityAnalyzer;
use Tests\Architecture\Support\MigrationTableScopeMap;
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

    // ------------------------------------------- acknowledgement relabel (F-1/F-4)

    /**
     * The relabel itself: an acknowledged raw verdict leaves the failure set (so it
     * leaves the baseline and stops reading as burn-down debt) — and NOTHING else
     * moves with it.
     */
    #[Test]
    public function an_acknowledged_verdict_is_relabelled_and_leaves_the_failure_set(): void
    {
        $analyzer = new EnumCheckParityAnalyzer;
        $columns = [
            $this->column('fiscal_event_quarantine', 'integrity_exception_class', ['a', 'b', 'c']),
            $this->column('payments', 'status', ['completed', 'pending']),
        ];
        $schema = ['fiscal_event_quarantine' => ['integrity_exception_class'], 'payments' => ['status']];
        $checks = ['fiscal_event_quarantine' => ['integrity_exception_class' => [
            'accepted' => ['a', 'b'], 'constraints' => ['q_chk'], 'nullable' => false, 'not_validated' => false,
        ]]];

        $unacknowledged = $analyzer->analyze($columns, $checks, $schema);
        $this->assertSame(EnumCheckParityAnalyzer::VERDICT_NARROWER, $unacknowledged[0]['verdict']);
        $this->assertSame(
            ['fiscal_event_quarantine.integrity_exception_class::NARROWER', 'payments.status::MISSING'],
            array_column($analyzer->partition($unacknowledged, [])['new'], 'key'),
        );

        $acknowledged = $analyzer->analyze($columns, $checks, $schema, [
            'fiscal_event_quarantine.integrity_exception_class::NARROWER' => EnumCheckParityAcknowledgements::KIND_INTENDED_NARROWER,
        ]);

        $this->assertSame(EnumCheckParityAnalyzer::VERDICT_INTENDED_NARROWER, $acknowledged[0]['verdict']);
        $this->assertSame(
            EnumCheckParityAcknowledgements::KIND_INTENDED_NARROWER,
            $acknowledged[0]['acknowledged_as'],
            'The relabel must be recorded on the finding, so the register can say WHY the row is not debt.',
        );
        $this->assertSame(
            ['payments.status::MISSING'],
            array_column($analyzer->partition($acknowledged, [])['new'], 'key'),
            'The acknowledgement relabelled a column other than the one it names.',
        );
    }

    /**
     * The reason keying on the RAW verdict is safe: an acknowledgement covers ONE
     * situation. Change the CHECK and the raw verdict changes, the key stops
     * matching, and the column drops straight back into the ratchet.
     */
    #[Test]
    public function an_acknowledgement_does_not_cover_a_different_verdict_on_the_same_column(): void
    {
        $analyzer = new EnumCheckParityAnalyzer;
        $columns = [$this->column('fiscal_event_quarantine', 'integrity_exception_class', ['a', 'b', 'c'])];
        $schema = ['fiscal_event_quarantine' => ['integrity_exception_class']];
        $acknowledgements = [
            'fiscal_event_quarantine.integrity_exception_class::NARROWER' => EnumCheckParityAcknowledgements::KIND_INTENDED_NARROWER,
        ];

        // The CHECK is edited to admit 'zz' as well as a subset: DIVERGENT, not NARROWER.
        $skewed = $analyzer->analyze($columns, ['fiscal_event_quarantine' => ['integrity_exception_class' => [
            'accepted' => ['a', 'zz'], 'constraints' => ['q_chk'], 'nullable' => false, 'not_validated' => false,
        ]]], $schema, $acknowledgements);

        $this->assertSame(EnumCheckParityAnalyzer::VERDICT_DIVERGENT, $skewed[0]['verdict']);
        $this->assertNull($skewed[0]['acknowledged_as']);
        $this->assertSame(
            ['fiscal_event_quarantine.integrity_exception_class::DIVERGENT'],
            array_column($analyzer->partition($skewed, [])['new'], 'key'),
        );

        // The CHECK is dropped entirely: MISSING, also not covered.
        $dropped = $analyzer->analyze($columns, [], $schema, $acknowledgements);
        $this->assertSame(
            ['fiscal_event_quarantine.integrity_exception_class::MISSING'],
            array_column($analyzer->partition($dropped, [])['new'], 'key'),
        );
    }

    // ------------------------------------ acknowledgement CLAIM drift (F-1/F-4)

    /**
     * @return array{enum_cases: list<string>, accepted: list<string>|null, predicate_cases: list<string>|null, constraint_definitions: array<string, string>, in_population: bool, column_exists: bool}
     */
    private function live(array $overrides = []): array
    {
        return [...[
            'enum_cases' => ['a', 'b', 'c'],
            'accepted' => ['a', 'b'],
            'predicate_cases' => ['a', 'b'],
            'constraint_definitions' => [],
            'in_population' => true,
            'column_exists' => true,
        ], ...$overrides];
    }

    /**
     * @return array<string, mixed>
     */
    private function narrowerAcknowledgement(): array
    {
        return [
            'key' => 't.c::NARROWER',
            'kind' => EnumCheckParityAcknowledgements::KIND_INTENDED_NARROWER,
            'table' => 't',
            'column' => 'c',
            'enum' => 'Tests\\Fixture\\Enum\\c',
            'intended_set' => ['a', 'b'],
            'enum_cases_at_acknowledgement' => ['a', 'b', 'c'],
            'predicate' => ['method' => 'isAdmissibleToLedger', 'expects' => false, 'ref' => 'Fixture.php:1'],
            'reason' => 'partition',
        ];
    }

    #[Test]
    public function an_intact_intended_narrower_acknowledgement_reports_no_problem(): void
    {
        $this->assertSame([], EnumCheckParityAcknowledgements::problems($this->narrowerAcknowledgement(), $this->live()));
    }

    /**
     * THE tamper that matters: a burn-down batch "closing" the NARROWER row by
     * widening the CHECK to the whole enum. That destroys the partition, and it
     * must fail rather than read as a gap closed.
     */
    #[Test]
    public function widening_an_intended_narrower_check_breaks_the_acknowledgement(): void
    {
        $problems = EnumCheckParityAcknowledgements::problems(
            $this->narrowerAcknowledgement(),
            $this->live(['accepted' => ['a', 'b', 'c']]),
        );

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('no longer admits exactly the acknowledged set', $problems[0]);
    }

    #[Test]
    public function dropping_an_intended_narrower_check_breaks_the_acknowledgement(): void
    {
        $problems = EnumCheckParityAcknowledgements::problems(
            $this->narrowerAcknowledgement(),
            $this->live(['accepted' => null]),
        );

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('is GONE', $problems[0]);
    }

    /**
     * The other side of the pin: a SEVENTH enum case. The acknowledged set is
     * pinned both to the enum's case list and to the governing predicate, so a new
     * case cannot slip into (or past) the partition unnoticed.
     */
    #[Test]
    public function adding_an_enum_case_breaks_an_intended_narrower_acknowledgement(): void
    {
        $admissibleNewCase = EnumCheckParityAcknowledgements::problems(
            $this->narrowerAcknowledgement(),
            $this->live(['enum_cases' => ['a', 'b', 'c', 'd']]),
        );
        $this->assertNotSame([], $admissibleNewCase);
        $this->assertStringContainsString('the ENUM changed since this partition was acknowledged', $admissibleNewCase[0]);

        // A new case that ALSO belongs on the non-admissible side moves the
        // predicate set as well — two independent failures, neither maskable.
        $nonAdmissibleNewCase = EnumCheckParityAcknowledgements::problems(
            $this->narrowerAcknowledgement(),
            $this->live(['enum_cases' => ['a', 'b', 'c', 'd'], 'predicate_cases' => ['a', 'b', 'd']]),
        );
        $this->assertCount(2, $nonAdmissibleNewCase);
        $this->assertStringContainsString('now selects [a, b, d]', $nonAdmissibleNewCase[1]);
    }

    #[Test]
    public function repointing_the_governing_predicate_breaks_an_intended_narrower_acknowledgement(): void
    {
        $problems = EnumCheckParityAcknowledgements::problems(
            $this->narrowerAcknowledgement(),
            $this->live(['predicate_cases' => ['a']]),
        );

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('The CHECK and the code that defines the partition have diverged', $problems[0]);
    }

    /**
     * The predicate pin, evaluated against the REAL enum. This is what ties the
     * acknowledgement to the code rather than to a comment: if
     * `isAdmissibleToLedger()` is edited, or a seventh non-admissible case is
     * added, this assertion moves and the acknowledgement's claim collapses with it.
     */
    #[Test]
    public function the_ledger_admissibility_predicate_still_selects_exactly_the_quarantine_partition(): void
    {
        $this->assertSame(
            ['malformed_envelope', 'sequence_conflict'],
            EnumCheckParityAcknowledgements::casesWherePredicate(IntegrityExceptionClass::class, 'isAdmissibleToLedger', false),
            'The ledger/quarantine partition moved. fiscal_event_quarantine_class_phase1_allowed and the '
            .'INTENDED_NARROWER acknowledgement must both be re-derived — do NOT widen the CHECK.',
        );
        $this->assertSame(
            ['canonical_hash_mismatch', 'canonical_parse_failure', 'sequence_gap', 'time_anomaly'],
            EnumCheckParityAcknowledgements::casesWherePredicate(IntegrityExceptionClass::class, 'isAdmissibleToLedger', true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compositeAcknowledgement(): array
    {
        return [
            'key' => 'pos_receipts.receipt_type::MISSING',
            'kind' => EnumCheckParityAcknowledgements::KIND_COVERED_BY_COMPOSITE,
            'table' => 'pos_receipts',
            'column' => 'receipt_type',
            'enum' => 'Tests\\Fixture\\Enum\\receipt_type',
            'constraint' => 'pos_receipts_return_logic',
            'pinned_set' => ['return', 'sale'],
            'reason' => 'cross-column',
        ];
    }

    /**
     * @return array{enum_cases: list<string>, accepted: list<string>|null, predicate_cases: list<string>|null, constraint_definitions: array<string, string>, in_population: bool, column_exists: bool}
     */
    private function compositeLive(array $overrides = []): array
    {
        return [...[
            'enum_cases' => ['return', 'sale'],
            'accepted' => null,
            'predicate_cases' => null,
            'constraint_definitions' => ['pos_receipts_return_logic' => "CHECK ((((receipt_type)::text = 'sale'::text) OR (((receipt_type)::text = 'return'::text) AND (original_receipt_id IS NOT NULL))))"],
            'in_population' => true,
            'column_exists' => true,
        ], ...$overrides];
    }

    #[Test]
    public function an_intact_composite_acknowledgement_reports_no_problem(): void
    {
        $this->assertSame([], EnumCheckParityAcknowledgements::problems($this->compositeAcknowledgement(), $this->compositeLive()));
    }

    /**
     * The latent write bomb this acknowledgement exists to catch: a THIRD
     * `ReceiptType` case turns the cross-column constraint into a live NARROWER
     * CHECK on a fiscal table. Before the acknowledgement, the column's MISSING key
     * was already baselined and the gate stayed green forever.
     */
    #[Test]
    public function a_third_enum_case_breaks_the_composite_acknowledgement(): void
    {
        $problems = EnumCheckParityAcknowledgements::problems(
            $this->compositeAcknowledgement(),
            $this->compositeLive(['enum_cases' => ['exchange', 'return', 'sale']]),
        );

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString("does not mention enum case 'exchange'", $problems[0]);
        $this->assertStringContainsString('no longer matches the acknowledged pinned set', $problems[1]);
    }

    #[Test]
    public function dropping_the_pinning_constraint_breaks_the_composite_acknowledgement(): void
    {
        $problems = EnumCheckParityAcknowledgements::problems(
            $this->compositeAcknowledgement(),
            $this->compositeLive(['constraint_definitions' => []]),
        );

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('no longer exists', $problems[0]);
        $this->assertStringContainsString('returns to the ratchet as MISSING', $problems[0]);
    }

    /**
     * The happy ending, also pinned: the POS batch adds an explicit value-set CHECK,
     * at which point the acknowledgement is dead weight and must be deleted.
     */
    #[Test]
    public function an_explicit_check_makes_the_composite_acknowledgement_redundant(): void
    {
        $problems = EnumCheckParityAcknowledgements::problems(
            $this->compositeAcknowledgement(),
            $this->compositeLive(['accepted' => ['return', 'sale']]),
        );

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('Delete the acknowledgement', $problems[0]);
    }

    #[Test]
    public function an_acknowledgement_for_a_column_that_left_the_population_is_reported(): void
    {
        $this->assertStringContainsString(
            'no longer in the derived population',
            EnumCheckParityAcknowledgements::problems($this->narrowerAcknowledgement(), $this->live(['in_population' => false]))[0],
        );
        $this->assertStringContainsString(
            'the live schema has no such column',
            EnumCheckParityAcknowledgements::problems($this->narrowerAcknowledgement(), $this->live(['column_exists' => false]))[0],
        );
    }

    // ------------------------------------------- un-gateable rot guard (F-9/T-5)

    #[Test]
    public function the_ungateable_supplement_reports_every_way_it_can_rot(): void
    {
        $schema = [
            'bank_reconciliations' => ['status'],
            'fiscal_event_quarantine' => ['payload_parse_status'],
            'super_admins' => ['role'],
        ];

        $this->assertSame([], EnumBackedColumnRegistry::ungateableProblems($schema, [], []));

        // A cast was added — the column is asserted now, so the entry is dead weight.
        $inPopulation = EnumBackedColumnRegistry::ungateableProblems($schema, ['super_admins.role'], []);
        $this->assertCount(1, $inPopulation);
        $this->assertStringContainsString('IS in the derived population now', $inPopulation[0]);

        // A model appeared for the table the entry claims has none.
        $gotAModel = EnumBackedColumnRegistry::ungateableProblems($schema, [], ['bank_reconciliations']);
        $this->assertCount(1, $gotAModel);
        $this->assertStringContainsString('a model now maps to bank_reconciliations', $gotAModel[0]);

        // The column was dropped or renamed.
        unset($schema['super_admins']);
        $gone = EnumBackedColumnRegistry::ungateableProblems($schema, [], []);
        $this->assertCount(1, $gone);
        $this->assertStringContainsString('the live schema has no such column', $gone[0]);
    }

    // ------------------------------------ central-mutates-tenant disjointness (T-2)

    /**
     * The union database's honesty property, pinned. A central migration that
     * ALTERs a tenant table would put a CHECK in the test schema that no real
     * `tenant_<uuid>` database has — a FALSE COVERED. Both recognised idioms
     * (`Schema::table` and raw `ALTER TABLE`) are exercised.
     */
    #[Test]
    public function a_central_migration_that_mutates_a_tenant_table_is_detected(): void
    {
        $root = sys_get_temp_dir().'/enum_check_parity_scope_'.bin2hex(random_bytes(6));
        mkdir($root.'/tenant', 0o755, true);

        try {
            file_put_contents($root.'/tenant/2026_01_01_000000_create_pos_receipts.php', "<?php Schema::create('pos_receipts', function (\$t) {});");
            file_put_contents($root.'/2026_01_01_000000_create_tenants.php', "<?php Schema::create('tenants', function (\$t) {});");

            $map = new MigrationTableScopeMap($root);
            $this->assertSame([], $map->centralMutationsOfTenantTables(), 'A clean tree must report no leak.');
            $this->assertSame(MigrationTableScopeMap::SCOPE_TENANT, $map->scopeOf('pos_receipts'));
            $this->assertSame(MigrationTableScopeMap::SCOPE_CENTRAL, $map->scopeOf('tenants'));

            file_put_contents($root.'/2026_02_01_000000_central_touches_tenant.php', "<?php Schema::table('pos_receipts', function (\$t) {});");
            $leaks = (new MigrationTableScopeMap($root))->centralMutationsOfTenantTables();
            $this->assertSame(['pos_receipts ← 2026_02_01_000000_central_touches_tenant.php'], $leaks);

            unlink($root.'/2026_02_01_000000_central_touches_tenant.php');
            file_put_contents($root.'/2026_03_01_000000_central_raw_sql.php', "<?php DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT x CHECK (a > 0)');");
            $rawLeaks = (new MigrationTableScopeMap($root))->centralMutationsOfTenantTables();
            $this->assertSame(['pos_receipts ← 2026_03_01_000000_central_raw_sql.php'], $rawLeaks, 'A raw ALTER TABLE in the central tree must be detected too.');
        } finally {
            foreach (glob($root.'/tenant/*') ?: [] as $file) {
                unlink($file);
            }
            foreach (glob($root.'/*.php') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($root.'/tenant');
            @rmdir($root);
        }
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
                ['column' => 'status', 'values' => ['completed', 'draft'], 'nullable' => false, 'not_validated' => false],
            ],
            'nullable guard first — workshop_work_order_lines.core_deposit_status' => [
                "CHECK (((core_deposit_status IS NULL) OR ((core_deposit_status)::text = ANY ((ARRAY['outstanding'::character varying, 'returned'::character varying])::text[]))))",
                ['column' => 'core_deposit_status', 'values' => ['outstanding', 'returned'], 'nullable' => true, 'not_validated' => false],
            ],
            'nullable guard last' => [
                "CHECK ((((kind)::text = ANY ((ARRAY['a'::character varying])::text[])) OR (kind IS NULL)))",
                ['column' => 'kind', 'values' => ['a'], 'nullable' => true, 'not_validated' => false],
            ],
            'text-cast array, no varchar wrapper' => [
                "CHECK ((mode)::text = ANY (ARRAY['x'::text, 'y'::text]))",
                ['column' => 'mode', 'values' => ['x', 'y'], 'nullable' => false, 'not_validated' => false],
            ],
            'integer-backed enum' => [
                'CHECK ((level = ANY (ARRAY[1, 2, 3])))',
                ['column' => 'level', 'values' => ['1', '2', '3'], 'nullable' => false, 'not_validated' => false],
            ],
            'degenerate single value' => [
                "CHECK (((kind)::text = 'only'::text))",
                ['column' => 'kind', 'values' => ['only'], 'nullable' => false, 'not_validated' => false],
            ],
            // ---- F-2: `NOT VALID` is the MANDATED rollout idiom for the slice-D
            // burn-down batches (add NOT VALID, then VALIDATE CONSTRAINT separately).
            // A parser that returns null here makes every freshly added CHECK
            // invisible for the whole NOT-VALID window — including a WRONG one,
            // which PostgreSQL enforces on every new write from the moment it lands.
            'NOT VALID array form is READ, and flagged — F-2' => [
                "CHECK (((receipt_type)::text = ANY ((ARRAY['sale'::character varying, 'return'::character varying])::text[]))) NOT VALID",
                ['column' => 'receipt_type', 'values' => ['return', 'sale'], 'nullable' => false, 'not_validated' => true],
            ],
            'NOT VALID degenerate form is READ, and flagged — F-2' => [
                "CHECK (((kind)::text = 'only'::text)) NOT VALID",
                ['column' => 'kind', 'values' => ['only'], 'nullable' => false, 'not_validated' => true],
            ],
            // ---- F-3: paren-flattening must not reach inside a quoted literal.
            // A corrupted accepted-set is a FALSE COVERED / FALSE DIVERGENT — the
            // one failure mode that makes the whole gate lie.
            'a literal containing parentheses survives the tokenizer — F-3' => [
                "CHECK (((label)::text = ANY ((ARRAY['a(b)'::character varying, 'z'::character varying])::text[])))",
                ['column' => 'label', 'values' => ['a(b)', 'z'], 'nullable' => false, 'not_validated' => false],
            ],
            'a literal containing runs of whitespace survives the tokenizer — F-3' => [
                "CHECK (((label)::text = ANY ((ARRAY['a  b'::character varying])::text[])))",
                ['column' => 'label', 'values' => ['a  b'], 'nullable' => false, 'not_validated' => false],
            ],
            // ---- F-5: hand-written migrations render an OR-chain of equalities
            // rather than ANY(ARRAY[…]). It IS a single-column value set.
            'OR-chain of equalities is a value set — F-5' => [
                "CHECK (((b)::text = 'p'::text) OR ((b)::text = 'q'::text))",
                ['column' => 'b', 'values' => ['p', 'q'], 'nullable' => false, 'not_validated' => false],
            ],
            'OR-chain with a null guard — F-5' => [
                "CHECK ((b IS NULL) OR ((b)::text = 'p'::text) OR ((b)::text = 'q'::text))",
                ['column' => 'b', 'values' => ['p', 'q'], 'nullable' => true, 'not_validated' => false],
            ],
            'an OR-chain whose branches carry AND clauses is NOT a value set — F-5' => [
                "CHECK ((((receipt_type)::text = 'sale'::text) OR (((receipt_type)::text = 'return'::text) AND (original_receipt_id IS NOT NULL))))",
                null,
            ],
            'an OR-chain naming two DIFFERENT columns is NOT a single-column value set — F-5' => [
                "CHECK (((a)::text = 'p'::text) OR ((b)::text = 'q'::text))",
                null,
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
        DB::statement("CREATE TABLE {$table} (id int, status varchar(32), tone varchar(32) NULL, level int, pending varchar(32), label varchar(32))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_status_chk CHECK (status IN ('open', 'closed'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_tone_chk CHECK (tone IS NULL OR tone IN ('warm'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_level_chk CHECK (level IN (1, 2))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_shape_chk CHECK (id > 0)");
        // F-2: the slice-D burn-down batches are MANDATED to add every CHECK as
        // NOT VALID first. If the reader cannot see one, the entire NOT-VALID
        // window is a blind spot and a WRONG check ships green.
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_pending_chk CHECK (pending IN ('draft', 'live')) NOT VALID");
        // F-3: a literal containing a parenthesis must survive the read verbatim.
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_label_chk CHECK (label IN ('a(b)', 'z'))");

        try {
            $read = (new PgValueSetCheckReader)->read(DB::connection());

            $this->assertArrayHasKey($table, $read, 'The reader found NO value-set CHECK on a table that has three.');
            $this->assertSame(['closed', 'open'], $read[$table]['status']['accepted']);
            $this->assertSame([$table.'_status_chk'], $read[$table]['status']['constraints']);
            $this->assertSame(['warm'], $read[$table]['tone']['accepted']);
            $this->assertTrue($read[$table]['tone']['nullable']);
            $this->assertSame(['1', '2'], $read[$table]['level']['accepted']);
            $this->assertArrayNotHasKey('id', $read[$table], 'A non-value-set CHECK must not be read as one.');

            // F-2 — the NOT VALID arm. Seen, and distinguishable from a validated one.
            $this->assertArrayHasKey('pending', $read[$table], 'A NOT VALID CHECK is INVISIBLE to the reader — the whole burn-down window is a blind spot.');
            $this->assertSame(['draft', 'live'], $read[$table]['pending']['accepted']);
            $this->assertTrue($read[$table]['pending']['not_validated'], 'A NOT VALID CHECK must be flagged, not silently reported as validated.');
            $this->assertFalse($read[$table]['status']['not_validated'], 'A validated CHECK must not be flagged NOT VALID.');

            // F-3 — a literal containing a parenthesis is read verbatim.
            $this->assertSame(['a(b)', 'z'], $read[$table]['label']['accepted'], 'The tokenizer corrupted a literal containing a parenthesis.');

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_status_chk");
            $afterDrop = (new PgValueSetCheckReader)->read(DB::connection());
            $this->assertArrayNotHasKey('status', $afterDrop[$table] ?? [], 'A dropped CHECK must disappear from the read.');
        } finally {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
    }
}
