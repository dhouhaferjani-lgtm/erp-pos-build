<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\EnumBackedColumnRegistry;
use Tests\Architecture\Support\EnumCheckParityAcknowledgements;
use Tests\Architecture\Support\EnumCheckParityAnalyzer;
use Tests\Architecture\Support\MigrationTableScopeMap;
use Tests\Architecture\Support\PgValueSetCheckReader;
use Tests\TestCase;

/**
 * SLICE D ENTRY GATE — the `pg_constraint` enum↔CHECK parity ratchet.
 *
 * The state-machine sweep (`docs/handoff/AUDIT-state-machine-sweep-local-2026-08-23.md` §#26)
 * found that the overwhelming majority of enum-governed columns have no DB CHECK:
 * the enum is the only thing standing between a bad write and a row the enum
 * cannot hydrate. This test does NOT fix that. It MEASURES it, freezes the
 * measurement as a SHRINK-ONLY baseline, and makes three things fail immediately:
 *
 *   1. a NEW enum-backed column landing with no CHECK,
 *   2. an existing CHECK being REMOVED,
 *   3. an existing CHECK being WIDENED (or narrowed, or otherwise skewed) away
 *      from its enum.
 *
 * None of those three can be absorbed by regenerating the baseline in the same
 * change: keys carry their verdict, so a widened CHECK on a baselined-MISSING
 * column produces a key the baseline does not contain, and closing a gap makes
 * the old entry STALE, which fails until it is deleted. The baseline therefore
 * only ever shrinks — it is the burn-down denominator the CHECK-adding batches
 * (separate, censused, NOT VALID + VALIDATE lanes) count down.
 *
 * WHAT IS ASSERTED, EXACTLY: every column of a TENANT table that an
 * `app/**\/Domain/Enums/*` or `app/**\/Shared/Enums/*` enum governs — derived
 * mechanically, see `Support/EnumBackedColumnRegistry`, which reports by name
 * every cast-target enum it still excludes.
 *
 * THE TEST DATABASE IS A UNION OF BOTH MIGRATION TREES (T-2, corrected). Under
 * `APP_ENV=testing`, `AppServiceProvider::boot()` loads `database/migrations/tenant`
 * ALONGSIDE the default central tree, so one `migrate` builds a database holding
 * both. The tenant DDL is byte-for-byte what `tenants:migrate` applies in
 * production — same directory, same files — so the tenant verdicts are faithful.
 * What the union adds is the CENTRAL tree, which is a SEPARATE population with its
 * own baseline, asserted by `central_scope_enum_columns_match_…` below. Central was
 * never excluded because it "migrates through a different path" in this database;
 * it is a different SCOPE. The property that keeps the union honest — no central
 * migration mutates a tenant table — is asserted by
 * `no_central_migration_mutates_a_tenant_scoped_table()`.
 *
 * TWO VERDICTS ARE ACKNOWLEDGED, NOT BASELINED — `INTENDED_NARROWER` (a deliberate
 * partition gate) and `COVERED_BY_COMPOSITE` (a cross-column constraint that pins
 * the value set). They are NOT waivers: `EnumCheckParityAcknowledgements` states a
 * claim about the live schema and `acknowledgements_still_describe_the_live_schema()`
 * asserts every clause of it, un-baselined. See that class for why filing either
 * one as debt actively misleads the burn-down.
 *
 * ANTI-GROWTH CEILING (O-31 / gate r2 §R2-2) — the third direction, matching
 * `DocumentPerActionBaselineRatchetTest`. Directions 1-3 above are all defeatable
 * on their own: land a new uncovered column AND its hand-added baseline entry in
 * one diff and everything above is green (MATCHED GROWTH). So
 * `the_parity_artifacts_never_grow_against_the_owner_pinned_seed()` compares the
 * working copies of ALL THREE artifacts — tenant baseline, central baseline and
 * the acknowledgements — against the copies at a commit named by the OWNER-SET
 * repository variable `ENUM_CHECK_PARITY_PROTECTED_SEED`. A commit rather than a
 * blob, deliberately: one variable and one durable tag then cover all three files.
 * The variable is the AUTHORITY precisely because it lives outside every candidate
 * diff; `docs/handoff/progress/slice-d-parity.progress.yaml` carries a
 * NON-AUTHORITATIVE mirror for the paper trail, and mirror drift FAILS. Until the
 * owner arms the variable this gate fails CLOSED, which is the designed forcing
 * function — see that YAML for the re-pin triggers.
 *
 * ⚠️ THE ACKNOWLEDGEMENTS ARE PART OF THE CEILING ON PURPOSE. Gate r2 proved that
 * widening `fiscal_event_quarantine_class_phase1_allowed` to all six cases AND
 * deleting its acknowledgement in the SAME diff was fully green: the widened CHECK
 * reads COVERED, needs no baseline key, and the only machine-readable statement of
 * the fiscal ledger/quarantine partition vanishes. Under the pin the deletion is
 * red. `the_named_acknowledgements_still_resolve()` holds the cheap half of that
 * guarantee even while the variable is unset.
 *
 * ⚠️ THE DETECTOR ITSELF IS CANDIDATE-DELETABLE — the same residual
 * `DocumentPerActionBaselineRatchetTest` discloses. Nothing asserts this file
 * exists; deleting it plus its baseline leaves the suite green with no gate.
 *
 * PG-ONLY BY NATURE: `pg_constraint` is PostgreSQL's catalogue and SQLite does not
 * enforce these CHECKs at all, so the test self-skips with an explicit message on
 * any other driver rather than passing vacuously.
 *
 * Liveness (conv. 08) lives in `EnumCheckParityDetectorLivenessTest`.
 */
final class EnumCheckParityTest extends TestCase
{
    use RefreshDatabase;

    private const BASELINE_RELATIVE = 'tests/Architecture/baselines/enum-check-parity-baseline.json';

    private const CENTRAL_BASELINE_RELATIVE = 'tests/Architecture/baselines/enum-check-parity-central-baseline.json';

    private const ACKNOWLEDGEMENTS_RELATIVE = 'tests/Architecture/baselines/enum-check-parity-acknowledgements.json';

    /**
     * O-31 — the OWNER-SET repository variable holding the 40-hex seed COMMIT the
     * three artifacts are ceilinged against. Never a tracked file: a contributor
     * who can edit the artifacts must not be able to edit the ceiling too.
     */
    private const PROTECTED_SEED_ENV = 'ENUM_CHECK_PARITY_PROTECTED_SEED';

    private const MIRROR_YAML_RELATIVE = 'docs/handoff/progress/slice-d-parity.progress.yaml';

    private const MIRROR_FIELD = 'enum_check_parity_seed_commit';

    private const PIN_TAG_FIELD = 'enum_check_parity_pin_tag';

    /**
     * The three protected artifacts, REPOSITORY-relative (the pin is resolved with
     * `git cat-file <seed>:<path>` from the repository root, not from `apps/api`).
     */
    private const PROTECTED_ARTIFACTS = [
        'tenant baseline' => 'apps/api/'.self::BASELINE_RELATIVE,
        'central baseline' => 'apps/api/'.self::CENTRAL_BASELINE_RELATIVE,
        'acknowledgements' => 'apps/api/'.self::ACKNOWLEDGEMENTS_RELATIVE,
    ];

    /**
     * The rot guard's named entries — table, column and the verdict each one is
     * relabelled to. Deliberately hard-coded: gate r2 §R2-2 proved that the only
     * existing guard (`assertNotSame([], $entries)`) catches the file being EMPTIED
     * and nothing else, so deleting ONE entry — the one carrying the fiscal
     * ledger/quarantine partition — was free.
     */
    private const NAMED_ACKNOWLEDGEMENTS = [
        ['table' => 'fiscal_event_quarantine', 'column' => 'integrity_exception_class', 'kind' => EnumCheckParityAcknowledgements::KIND_INTENDED_NARROWER],
        ['table' => 'pos_receipts', 'column' => 'receipt_type', 'kind' => EnumCheckParityAcknowledgements::KIND_COVERED_BY_COMPOSITE],
    ];

    /**
     * Derivation walks every model file under app/ and constructs each model; the
     * schema read walks information_schema. Both are pure functions of a schema
     * this class never mutates, so they are computed ONCE per class rather than
     * once per test method — four independent derivations in one process is how
     * this suite ran itself out of the 2 GiB phpunit.xml memory limit.
     *
     * @var array{findings: list<array<string, mixed>>, central: list<array<string, mixed>>, entries: list<array<string, mixed>>, schema: array<string, list<string>>, checks: array<string, array<string, array<string, mixed>>>}|null
     */
    private static ?array $analysis = null;

    public static function tearDownAfterClass(): void
    {
        self::$analysis = null;

        parent::tearDownAfterClass();
    }

    /**
     * @return array{findings: list<array<string, mixed>>, central: list<array<string, mixed>>, entries: list<array<string, mixed>>, schema: array<string, list<string>>, checks: array<string, array<string, array<string, mixed>>>}
     */
    private function analysis(): array
    {
        if (self::$analysis !== null) {
            return self::$analysis;
        }

        $registry = $this->registry();
        $entries = $registry->derive();
        $schema = $this->schemaColumns();
        $checks = (new PgValueSetCheckReader)->read(DB::connection());
        $acknowledgements = $this->acknowledgements()->verdictMap();
        $analyzer = new EnumCheckParityAnalyzer;

        $byScope = static fn (string $scope): array => array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['scope'] === $scope,
        ));

        return self::$analysis = [
            'findings' => $analyzer->analyze($byScope(MigrationTableScopeMap::SCOPE_TENANT), $checks, $schema, $acknowledgements),
            'central' => $analyzer->analyze($byScope(MigrationTableScopeMap::SCOPE_CENTRAL), $checks, $schema, $acknowledgements),
            'entries' => $entries,
            'schema' => $schema,
            'checks' => $checks,
        ];
    }

    private function acknowledgements(): EnumCheckParityAcknowledgements
    {
        return EnumCheckParityAcknowledgements::fromFile(base_path(self::ACKNOWLEDGEMENTS_RELATIVE));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped(
                "The enum<->CHECK parity gate reads pg_constraint and is PostgreSQL-only; driver is {$driver}. "
                .'Run it against the local stack: DB_CONNECTION=pgsql DB_PORT=5433 DB_DATABASE=autoerp_<lane>_test.'
            );
        }
    }

    /**
     * The gate: every enum-governed tenant column either has a CHECK that admits
     * EXACTLY its enum's cases, or is carried in the shrink-only baseline.
     */
    #[Test]
    public function enum_backed_tenant_columns_match_their_check_constraints_or_the_baseline(): void
    {
        $analyzer = new EnumCheckParityAnalyzer;
        $partition = $analyzer->partition($this->analysis()['findings'], $this->baselineKeys());

        $message = '';
        if ($partition['new'] !== []) {
            $lines = [];
            foreach ($partition['new'] as $failure) {
                $lines[] = $this->describe($failure);
            }
            $message .= "\nNEW enum<->CHECK parity failures (".count($partition['new'])." — this gate is a RATCHET:\n"
                ."add the CHECK, or fix the one that is there. Regenerating the baseline to absorb these is not a fix):\n  "
                .implode("\n  ", $lines)."\n";
        }
        if ($partition['stale'] !== []) {
            $message .= "\nSTALE baseline entries (".count($partition['stale'])." — the gap is closed or the verdict changed;\n"
                ."the baseline only SHRINKS, so delete these entries):\n  "
                .implode("\n  ", $partition['stale'])."\n";
        }

        $this->assertSame(
            [],
            [...array_map(static fn (array $f): string => $f['key'], $partition['new']), ...$partition['stale']],
            $message,
        );
    }

    /**
     * WIDER / NARROWER / DIVERGENT are not "not yet done" — they are a CHECK and
     * an enum that already disagree, i.e. a live read or write bomb — unless the
     * divergence is DELIBERATE, in which case it is declared in
     * `enum-check-parity-acknowledgements.json`, rendered as its own verdict, and
     * asserted clause-by-clause by `acknowledgements_still_describe_the_live_schema()`
     * rather than carried here. They are
     * pinned here separately so the burn-down cannot lose them among 190 MISSING
     * rows, and so the exact divergence is printed rather than just counted.
     *
     * This assertion is baselined for exactly the same reason as the main gate:
     * a divergence that exists on dev today cannot be fixed by a test-only lane.
     * A NEW divergence has no baseline entry and fails the gate above.
     */
    #[Test]
    public function every_already_divergent_column_is_an_acknowledged_finding(): void
    {
        $findings = $this->analysis()['findings'];

        $divergent = array_values(array_filter($findings, static fn (array $f): bool => in_array(
            $f['verdict'],
            [
                EnumCheckParityAnalyzer::VERDICT_WIDER,
                EnumCheckParityAnalyzer::VERDICT_NARROWER,
                EnumCheckParityAnalyzer::VERDICT_DIVERGENT,
            ],
            true,
        )));

        $baseline = $this->baselineKeys();
        $unacknowledged = array_values(array_filter(
            $divergent,
            static fn (array $f): bool => ! in_array($f['key'], $baseline, true),
        ));

        $this->assertSame(
            [],
            array_map(fn (array $f): string => $this->describe($f), $unacknowledged),
            "\nA CHECK and its enum DISAGREE and the divergence is not acknowledged in the baseline.\n"
            ."WIDER  = the DB admits a value the enum cannot hydrate (read bomb).\n"
            ."NARROWER = the enum can produce a value the DB rejects (write bomb) — UNLESS it is a\n"
            ."           deliberate partition gate, which belongs in enum-check-parity-acknowledgements.json\n"
            ."           as INTENDED_NARROWER. Widening the CHECK is NOT the fix for that case.\n",
        );
    }

    /**
     * The `GOVERNED_AUDIT_COLUMNS` supplement is the ONE hand-written part of the
     * derivation (the brief mandates covering the transition-audit tables, and
     * `instrument_events` does not cast its status columns). A hand list rots, so
     * every entry must still resolve: the table and column must exist, and the
     * entry must not have been made redundant by someone adding the cast.
     */
    #[Test]
    public function the_governed_audit_supplement_still_resolves(): void
    {
        $schema = $this->analysis()['schema'];
        $entries = $this->analysis()['entries'];
        $byKey = [];
        foreach ($entries as $entry) {
            $byKey[$entry['table'].'.'.$entry['column']] = $entry;
        }

        $problems = [];
        foreach (EnumBackedColumnRegistry::GOVERNED_AUDIT_COLUMNS as $declared) {
            $key = $declared['table'].'.'.$declared['column'];

            if (! isset($schema[$declared['table']]) || ! in_array($declared['column'], $schema[$declared['table']], true)) {
                $problems[] = $key.' — declared in GOVERNED_AUDIT_COLUMNS but the live schema has no such column; remove or repoint the entry.';

                continue;
            }
            if (! enum_exists($declared['enum'])) {
                $problems[] = $key.' — governing enum '.$declared['enum'].' no longer exists.';

                continue;
            }
            if (($byKey[$key]['origin'] ?? '') === 'model-cast+governed-audit') {
                $problems[] = $key.' — the model now CASTS this column, so the hand-written supplement is redundant; delete the entry.';
            }
        }

        $this->assertSame([], $problems, "\n".implode("\n", $problems)."\n");
    }

    /**
     * F-1 / F-4 — an acknowledgement is a CLAIM about the live schema, and every
     * clause of it is asserted here. Un-baselined on purpose: an acknowledgement
     * that could be absorbed by regenerating the baseline would be a waiver with a
     * nicer name.
     *
     * This is the test that makes the two relabelled verdicts safe:
     *  - widen `fiscal_event_quarantine_class_phase1_allowed` → the live accepted
     *    set stops matching the acknowledged one → FAIL (the partition is being
     *    destroyed, which is exactly what a "fix the NARROWER row" batch would do);
     *  - add a 7th `IntegrityExceptionClass` case → the enum-cases pin AND the
     *    `isAdmissibleToLedger()` predicate set move → FAIL;
     *  - drop `pos_receipts_return_logic`, or add a 3rd `ReceiptType` case → the
     *    composite claim stops holding → FAIL.
     */
    #[Test]
    public function acknowledgements_still_describe_the_live_schema(): void
    {
        $population = [];
        foreach ($this->analysis()['entries'] as $entry) {
            $population[$entry['table'].'.'.$entry['column']] = $entry;
        }
        $checks = $this->analysis()['checks'];
        $schema = $this->analysis()['schema'];
        $entries = $this->acknowledgements()->entries();

        $this->assertNotSame([], $entries, 'The acknowledgements file is empty — either both acknowledgements were '
            .'legitimately closed (delete this assertion too) or the file has been emptied to silence the gate.');

        $problems = [];
        foreach ($entries as $entry) {
            /** @var array{key: string, kind: string, table: string, column: string, enum: class-string} $entry */
            $key = $entry['table'].'.'.$entry['column'];
            $columnExists = isset($schema[$entry['table']]) && in_array($entry['column'], $schema[$entry['table']], true);
            $governing = $population[$key] ?? null;

            $predicateCases = null;
            if (isset($entry['predicate'])) {
                /** @var array{method: string, expects: bool} $predicate */
                $predicate = $entry['predicate'];
                $predicateCases = EnumCheckParityAcknowledgements::casesWherePredicate(
                    $entry['enum'],
                    $predicate['method'],
                    $predicate['expects'],
                );
            }

            $problems = [...$problems, ...EnumCheckParityAcknowledgements::problems($entry, [
                'enum_cases' => $governing['cases'] ?? [],
                'accepted' => $checks[$entry['table']][$entry['column']]['accepted'] ?? null,
                'predicate_cases' => $predicateCases,
                'constraint_definitions' => $this->constraintDefinitions($entry['table']),
                'in_population' => $governing !== null && $governing['enum'] === $entry['enum'],
                'column_exists' => $columnExists,
            ])];
        }

        $this->assertSame(
            [],
            $problems,
            "\nAn ENUM<->CHECK ACKNOWLEDGEMENT NO LONGER DESCRIBES THE LIVE SCHEMA.\n"
            ."These rows are relabelled out of the burn-down because a claim about the code was asserted.\n"
            ."Re-derive the claim; do NOT widen a CHECK or edit the acknowledgement to make this pass:\n  "
            .implode("\n  ", $problems)."\n",
        );
    }

    /**
     * T-3 — the CENTRAL scope, asserted separately.
     *
     * Nine of the central enum columns are COVERED today and every one of them is
     * on the impersonation / support-access privilege path — those CHECKs are the
     * last DB-level defence on a grant state machine that gates CROSS-TENANT
     * access. Before this assertion existed, `DROP CONSTRAINT
     * impersonation_grants_status_check` produced NEW=0 STALE=0: a silent
     * regression in the highest-privilege state machine in the product.
     *
     * It is a SEPARATE assertion with a SEPARATE baseline, deliberately: in
     * production central lives in its own database (`synerivia_central`) reached by
     * a different migration path, so folding it into the tenant population would
     * misreport the tenant burn-down denominator. Here both trees migrate into one
     * union database, so both scopes are readable from a single connection.
     */
    #[Test]
    public function central_scope_enum_columns_match_their_check_constraints_or_the_central_baseline(): void
    {
        $partition = (new EnumCheckParityAnalyzer)->partition(
            $this->analysis()['central'],
            $this->baselineKeys(self::CENTRAL_BASELINE_RELATIVE),
        );

        $message = '';
        if ($partition['new'] !== []) {
            $message .= "\nNEW central-scope enum<->CHECK parity failures (".count($partition['new']).'). '
                ."Nine of these columns gate CROSS-TENANT impersonation access;\n"
                ."a CHECK disappearing from one of them is an authz regression, not debt:\n  "
                .implode("\n  ", array_map(fn (array $f): string => $this->describe($f), $partition['new']))."\n";
        }
        if ($partition['stale'] !== []) {
            $message .= "\nSTALE central baseline entries (".count($partition['stale'])." — the gap closed or the verdict changed;\n"
                ."the baseline only SHRINKS, so delete these entries):\n  "
                .implode("\n  ", $partition['stale'])."\n";
        }

        $this->assertSame(
            [],
            [...array_map(static fn (array $f): string => $f['key'], $partition['new']), ...$partition['stale']],
            $message,
        );
    }

    /**
     * T-1 — the enum-file path filter is a population boundary, so it must be
     * ASSERTED, not merely commented. `products.enrichment_status` is a status
     * column on a TENANT table governed by a 6-case backed enum that lives at
     * `app/Shared/Enums/EnrichmentStatus.php`; under the original
     * `Domain/Enums/`-only recogniser it was dropped SILENTLY and its (real,
     * live) missing CHECK was never in the burn-down denominator.
     *
     * Every cast-target enum the recogniser still excludes is reported by
     * `excludedEnumColumns()` and rendered in the register, so the boundary is
     * disclosed by column name rather than by adjective.
     */
    #[Test]
    public function the_enum_path_recogniser_covers_shared_enums_and_reports_what_it_still_excludes(): void
    {
        $population = [];
        foreach ($this->analysis()['entries'] as $entry) {
            $population[$entry['table'].'.'.$entry['column']] = $entry['enum'];
        }

        $this->assertArrayHasKey(
            'products.enrichment_status',
            $population,
            'products.enrichment_status is enum-governed on a TENANT table and must be in the population (T-1).',
        );
        $this->assertSame(EnrichmentStatus::class, $population['products.enrichment_status']);

        // The remaining exclusions are reported by name — never silent.
        $excluded = $this->registry()->excludedEnumColumns();
        $this->assertNotSame(
            [],
            $excluded,
            'excludedEnumColumns() returned nothing: either every cast-target enum is now recognised '
            .'(delete this assertion and the register section) or the reporter has rotted.',
        );
        foreach ($excluded as $entry) {
            $this->assertArrayNotHasKey(
                $entry['table'].'.'.$entry['column'],
                $population,
                'A column reported as EXCLUDED is also in the population — the two lists disagree.',
            );
        }
    }

    /**
     * F-9 / T-5 — the un-gateable columns are a DIFFERENT defect class (missing
     * enum cast, or no model at all) and cannot be asserted by this gate. They are
     * declared explicitly so they are a first-class, rot-guarded section of the
     * register rather than a sentence in a docblock: the day someone adds the cast,
     * the column joins the population and this test demands the entry be deleted.
     */
    #[Test]
    public function the_ungateable_supplement_still_resolves(): void
    {
        $population = [];
        foreach ($this->analysis()['entries'] as $entry) {
            $population[] = $entry['table'].'.'.$entry['column'];
        }

        $problems = EnumBackedColumnRegistry::ungateableProblems(
            $this->analysis()['schema'],
            $population,
            $this->registry()->tablesWithModels(),
        );

        $this->assertSame([], $problems, "\n".implode("\n", $problems)."\n");
    }

    /**
     * T-2 — the testing schema is a UNION of BOTH migration trees (the tenant tree
     * is loaded alongside the default central tree by
     * `AppServiceProvider::boot()` under `environment('testing')`), not a faithful
     * `tenant_<uuid>` database. What keeps that union honest is one property: NO
     * central migration mutates a tenant-scoped table. If it ever did, this gate
     * would read a CHECK that no real tenant database has — a FALSE COVERED, the
     * one failure mode that makes the gate lie. The property was true and
     * unasserted; it is asserted here.
     */
    #[Test]
    public function no_central_migration_mutates_a_tenant_scoped_table(): void
    {
        $leaks = $this->scopeMap()->centralMutationsOfTenantTables();

        $this->assertSame(
            [],
            $leaks,
            "\nA CENTRAL migration mutates a TENANT-scoped table.\n"
            ."The parity gate reads a UNION database (both trees migrate into it in testing), so such a\n"
            ."mutation puts a CHECK in the test schema that NO real tenant_<uuid> database has: the gate\n"
            ."would report COVERED for a column that is unconstrained in production.\n"
            ."Move the DDL into database/migrations/tenant/, do not widen this assertion:\n  "
            .implode("\n  ", $leaks)."\n",
        );
    }

    /**
     * The tenant/central split is what makes "assert on tenant only" meaningful.
     * A table neither migration tree is seen to declare is a DERIVATION BLIND SPOT
     * — the scope map's recognisers missed a new declaration idiom — and must be
     * fixed in the map, never waived by widening the assertion.
     */
    #[Test]
    public function every_enum_backed_table_is_classified_tenant_or_central(): void
    {
        $unknown = [];
        foreach ($this->analysis()['entries'] as $entry) {
            if ($entry['scope'] === MigrationTableScopeMap::SCOPE_UNKNOWN) {
                $unknown[] = $entry['table'].'.'.$entry['column'];
            }
        }
        $unknown = array_values(array_unique($unknown));

        $this->assertSame(
            [],
            $unknown,
            "\nThese enum-backed tables are declared by NEITHER migration tree as MigrationTableScopeMap reads them.\n"
            ."That is a blind spot in the scope map (a create/rename idiom it does not recognise), not a reason to skip:\n  "
            .implode("\n  ", $unknown)."\n",
        );

        $this->assertSame(
            [],
            $this->scopeMap()->collidingTables(),
            'A table is declared in BOTH the central and the tenant migration tree — the topology split is broken.',
        );
    }

    /**
     * O-31 / gate r2 §R2-2 — DIRECTION (c), the anti-growth ceiling, over ALL
     * THREE artifacts at once.
     *
     * Directions 1-3 compare the tree against the tracked artifacts, so a diff
     * that moves BOTH in step passes. This one compares the tracked artifacts
     * against copies the diff cannot reach: the owner sets
     * `ENUM_CHECK_PARITY_PROTECTED_SEED` to a reviewed commit sha and CI maps it
     * in. Three claims, all shrink-only:
     *
     *   (a) every key in the working TENANT baseline is in the pinned one;
     *   (b) every key in the working CENTRAL baseline is in the pinned one;
     *   (c) every ACKNOWLEDGEMENT in the pinned file is still in the working file
     *       with the SAME kind and the SAME predicate. Direction (c) runs the
     *       opposite way round on purpose: an acknowledgement may be ADDED (that
     *       is a reviewed claim about the schema), never silently DROPPED or
     *       RELABELLED. That is the exact move gate r2 proved free — widen the
     *       CHECK and delete the entry in one diff — and it is now red.
     *
     * FAILS CLOSED on: variable unset or empty (the designed forcing function for
     * the owner bootstrap), a malformed sha, a mirror that disagrees with the
     * variable (TAMPER SIGNAL, not a skip condition), or an unreachable object —
     * CI must fetch the durable pin tag before this runs.
     */
    #[Test]
    public function the_parity_artifacts_never_grow_against_the_owner_pinned_seed(): void
    {
        $seed = getenv(self::PROTECTED_SEED_ENV);
        $seed = is_string($seed) ? trim($seed) : '';

        $this->assertNotSame(
            '',
            $seed,
            self::PROTECTED_SEED_ENV." is unset or empty, so the anti-growth ceiling over the three parity\n"
            ."artifacts cannot be read and this gate FAILS CLOSED.\n"
            ."In CI: the owner sets the repository variable and the workflow maps it into the job environment.\n"
            .'Locally: export it from the reviewed seed commit recorded in '.self::MIRROR_YAML_RELATIVE." —\n"
            .'  export '.self::PROTECTED_SEED_ENV.'=$(git rev-parse <'.self::MIRROR_FIELD.'>)',
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$|^[0-9a-f]{64}$/',
            $seed,
            self::PROTECTED_SEED_ENV.' is not a git commit hash: '.$seed,
        );

        $mirror = $this->mirrorPin();
        $this->assertSame(
            $seed,
            $mirror,
            'The progress-YAML mirror ('.self::MIRROR_FIELD.' in '.self::MIRROR_YAML_RELATIVE.') disagrees with '
            .self::PROTECTED_SEED_ENV.".\n"
            ."That is a TAMPER SIGNAL, not a skip condition: the mirror is the paper trail for the value the owner set,\n"
            ."and a diff that moves one without the other is exactly what it exists to expose.\n"
            .'mirror='.var_export($mirror, true).' variable='.$seed,
        );

        $pinned = [];
        foreach (self::PROTECTED_ARTIFACTS as $label => $relative) {
            [$exitCode, $contents] = $this->gitCatFile($seed.':'.$relative);
            $this->assertSame(
                0,
                $exitCode,
                'The protected '.$label.' at '.$seed.':'.$relative." could not be read (git cat-file exit {$exitCode}).\n"
                .'CI must fetch the durable pin tag ('.self::PIN_TAG_FIELD.' in '.self::MIRROR_YAML_RELATIVE
                .') before this gate runs; an unreachable object fails closed.',
            );
            $pinned[$label] = $contents;
        }

        // (a) + (b) — both baselines may only SHRINK.
        $problems = [];
        foreach ([
            'tenant baseline' => self::BASELINE_RELATIVE,
            'central baseline' => self::CENTRAL_BASELINE_RELATIVE,
        ] as $label => $relative) {
            $protectedKeys = [];
            foreach ($this->decodeKeyList($pinned[$label], 'pinned '.$label) as $key) {
                $protectedKeys[$key] = true;
            }

            foreach ($this->baselineKeys($relative) as $key) {
                if (! isset($protectedKeys[$key])) {
                    $problems[] = $label.' GREW: '.$key;
                }
            }
        }

        // (c) — no acknowledgement may be dropped or relabelled.
        $working = $this->acknowledgementIndex(
            (string) file_get_contents(base_path(self::ACKNOWLEDGEMENTS_RELATIVE)),
            'working',
        );
        foreach ($this->acknowledgementIndex($pinned['acknowledgements'], 'pinned') as $key => $pinnedEntry) {
            if (! isset($working[$key])) {
                $problems[] = 'acknowledgement DROPPED: '.$key
                    .' — widening a CHECK and deleting its acknowledgement in one diff is the move this refuses.';

                continue;
            }

            $workingEntry = $working[$key];
            if (($workingEntry['kind'] ?? null) !== ($pinnedEntry['kind'] ?? null)) {
                $problems[] = 'acknowledgement RELABELLED: '.$key.' — kind '
                    .var_export($pinnedEntry['kind'] ?? null, true).' -> '.var_export($workingEntry['kind'] ?? null, true);
            }
            if (($workingEntry['predicate'] ?? null) !== ($pinnedEntry['predicate'] ?? null)) {
                $problems[] = 'acknowledgement PREDICATE MOVED: '.$key.' — '
                    .json_encode($pinnedEntry['predicate'] ?? null).' -> '.json_encode($workingEntry['predicate'] ?? null);
            }
        }

        $this->assertSame(
            [],
            $problems,
            "\nThe parity artifacts have GROWN or LOST GROUND against the owner-pinned seed {$seed}.\n"
            ."Baseline keys may only be REMOVED; acknowledgements may only be ADDED.\n  "
            .implode("\n  ", $problems)."\n"
            ."A new uncovered column is fixed by ADDING THE CHECK, never by extending the baseline in the same diff.\n"
            ."A NARROWER partition row is never 'closed' by widening the CHECK and deleting its acknowledgement.\n"
            .'If the movement is legitimate and reviewed, the fix is an OWNER RE-PIN — a new '
            .self::PROTECTED_SEED_ENV." value AND a newly allocated pin tag, together.\n",
        );
    }

    /**
     * R2-2, the cheap half — armed even while the owner variable is unset.
     *
     * `acknowledgements_still_describe_the_live_schema()` asserts every clause of
     * every entry that is PRESENT, and guards only the file being emptied
     * (`assertNotSame([], $entries)`). Neither notices ONE entry disappearing. So
     * the two live acknowledgements are named here: the quarantine partition
     * (whose deletion lets a `sequence_gap` or a `canonical_hash_mismatch` be
     * written into the table reserved for classes that may never reach the ledger)
     * and the `pos_receipts.receipt_type` composite. Retiring either one is a
     * legitimate act — it just has to be done HERE, in the diff that retires it,
     * where a reviewer can see it.
     */
    #[Test]
    public function the_named_acknowledgements_still_resolve(): void
    {
        $entries = $this->acknowledgements()->entries();

        $byColumn = [];
        foreach ($entries as $entry) {
            /** @var array{kind: string, table: string, column: string} $entry */
            $byColumn[$entry['table'].'.'.$entry['column']] = $entry['kind'];
        }

        $problems = [];
        foreach (self::NAMED_ACKNOWLEDGEMENTS as $named) {
            $key = $named['table'].'.'.$named['column'];

            if (! isset($byColumn[$key])) {
                $problems[] = $key.' — named in NAMED_ACKNOWLEDGEMENTS but GONE from '
                    .self::ACKNOWLEDGEMENTS_RELATIVE.'. If the row was legitimately retired (its CHECK became a real '
                    .'value-set CHECK), delete it from NAMED_ACKNOWLEDGEMENTS in the SAME diff and say why. Never silently.';

                continue;
            }

            if ($byColumn[$key] !== $named['kind']) {
                $problems[] = $key.' — acknowledged as '.$byColumn[$key].', expected '.$named['kind']
                    .'. A relabel changes what is being claimed about the live schema; re-derive the claim.';
            }
        }

        $this->assertSame([], $problems, "\nAN ACKNOWLEDGEMENT NAMED IN THIS GUARD NO LONGER RESOLVES.\n  "
            .implode("\n  ", $problems)."\n");
    }

    /**
     * @return array<string, array<string, mixed>> key => the raw acknowledgement entry
     */
    private function acknowledgementIndex(string $json, string $origin): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'The '.$origin.' acknowledgements artifact is not a JSON array.');

        $out = [];
        foreach ($decoded as $entry) {
            $this->assertIsArray($entry, 'The '.$origin.' acknowledgements artifact contains a non-object entry.');
            $this->assertArrayHasKey('key', $entry, 'The '.$origin.' acknowledgements artifact contains an entry with no key.');
            $out[(string) $entry['key']] = $entry;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function decodeKeyList(string $json, string $origin): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'The '.$origin.' is not a JSON array of keys.');

        $keys = [];
        foreach ($decoded as $entry) {
            $this->assertIsString($entry, 'The '.$origin.' contains a non-string entry.');
            $keys[] = $entry;
        }

        return $keys;
    }

    private function mirrorPin(): ?string
    {
        $path = $this->repositoryRoot().'/'.self::MIRROR_YAML_RELATIVE;
        if (! is_file($path)) {
            return null;
        }

        $contents = (string) file_get_contents($path);
        if (preg_match('/^'.preg_quote(self::MIRROR_FIELD, '/').':\s*([0-9a-f]{40,64})\s*$/m', $contents, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function gitCatFile(string $rev): array
    {
        $command = sprintf(
            'git -C %s cat-file -p %s 2>/dev/null',
            escapeshellarg($this->repositoryRoot()),
            escapeshellarg($rev),
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            return [1, ''];
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout];
    }

    private function repositoryRoot(): string
    {
        return dirname(base_path(), 2);
    }

    private function registry(): EnumBackedColumnRegistry
    {
        return new EnumBackedColumnRegistry(app_path(), $this->scopeMap());
    }

    private function scopeMap(): MigrationTableScopeMap
    {
        return new MigrationTableScopeMap(database_path('migrations'));
    }

    /**
     * Every CHECK constraint on one table, name => rendered definition. Used by the
     * COVERED_BY_COMPOSITE acknowledgement, whose whole claim is that a named
     * cross-column constraint still names every case of the enum.
     *
     * @return array<string, string>
     */
    private function constraintDefinitions(string $table): array
    {
        $rows = DB::connection()->select(
            <<<'SQL'
            SELECT con.conname AS constraint_name,
                   pg_get_constraintdef(con.oid) AS definition
              FROM pg_constraint con
              JOIN pg_class rel ON rel.oid = con.conrelid
              JOIN pg_namespace ns ON ns.oid = rel.relnamespace
             WHERE con.contype = 'c'
               AND ns.nspname = current_schema()
               AND rel.relname = ?
            SQL,
            [$table],
        );

        $out = [];
        foreach ($rows as $row) {
            /** @var object{constraint_name: string, definition: string} $row */
            $out[$row->constraint_name] = $row->definition;
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private function schemaColumns(): array
    {
        $rows = DB::connection()->select(
            <<<'SQL'
            SELECT table_name, column_name
              FROM information_schema.columns
             WHERE table_schema = current_schema()
             ORDER BY table_name, column_name
            SQL
        );

        $out = [];
        foreach ($rows as $row) {
            /** @var object{table_name: string, column_name: string} $row */
            $out[$row->table_name][] = $row->column_name;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function baselineKeys(string $relative = self::BASELINE_RELATIVE): array
    {
        $path = base_path($relative);
        $this->assertFileExists($path, 'The enum<->CHECK parity baseline is missing: '.$relative);

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'The enum<->CHECK parity baseline is not a JSON array of keys.');

        $keys = [];
        foreach ($decoded as $entry) {
            $this->assertIsString($entry, 'The enum<->CHECK parity baseline contains a non-string entry.');
            $keys[] = $entry;
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $failure
     */
    private function describe(array $failure): string
    {
        /** @var array{key: string, enum: class-string, verdict: string, enum_cases: list<string>, accepted: list<string>|null, constraints: list<string>} $failure */
        return match ($failure['verdict']) {
            EnumCheckParityAnalyzer::VERDICT_MISSING => sprintf(
                '%s  no value-set CHECK; enum %s admits [%s]',
                $failure['key'],
                $failure['enum'],
                implode(', ', $failure['enum_cases']),
            ),
            EnumCheckParityAnalyzer::VERDICT_ABSENT => sprintf(
                '%s  the registry names this column (enum %s) but the live schema does not have it',
                $failure['key'],
                $failure['enum'],
            ),
            default => sprintf(
                '%s  CHECK %s admits [%s]; enum %s admits [%s]',
                $failure['key'],
                implode('+', $failure['constraints']),
                implode(', ', $failure['accepted'] ?? []),
                $failure['enum'],
                implode(', ', $failure['enum_cases']),
            ),
        };
    }
}
