<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\EnumBackedColumnRegistry;
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
 * WHAT IS ASSERTED, EXACTLY: every column of a TENANT table that a
 * `app/**\/Domain/Enums/*` enum governs — derived mechanically, see
 * `Support/EnumBackedColumnRegistry`. Central-database tables are REPORTED, never
 * asserted: they migrate through a different path and are a different lane's
 * problem (lane D-1 brief, "Out of scope").
 *
 * ⚠️ DISCLOSED RESIDUAL — no anti-growth ceiling yet. `DocumentPerActionBaselineRatchetTest`
 * carries a third direction: the working baseline is compared against a blob named
 * by an OWNER-SET repository variable, so "land a violation AND its baseline entry
 * in one change" cannot pass. This gate has no such pin, because none has been
 * bootstrapped for it — MATCHED GROWTH (a new uncovered column plus a hand-added
 * baseline entry in the same diff) passes here and is caught only by review of the
 * baseline diff. Arming it is an owner action (repository variable + pin tag), and
 * it is reported as an owes rather than faked with a self-referential pin that the
 * same diff could edit.
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

    /**
     * Derivation walks every model file under app/ and constructs each model; the
     * schema read walks information_schema. Both are pure functions of a schema
     * this class never mutates, so they are computed ONCE per class rather than
     * once per test method — four independent derivations in one process is how
     * this suite ran itself out of the 2 GiB phpunit.xml memory limit.
     *
     * @var array{findings: list<array<string, mixed>>, entries: list<array<string, mixed>>, schema: array<string, list<string>>}|null
     */
    private static ?array $analysis = null;

    public static function tearDownAfterClass(): void
    {
        self::$analysis = null;

        parent::tearDownAfterClass();
    }

    /**
     * @return array{findings: list<array<string, mixed>>, entries: list<array<string, mixed>>, schema: array<string, list<string>>}
     */
    private function analysis(): array
    {
        if (self::$analysis !== null) {
            return self::$analysis;
        }

        $registry = $this->registry();
        $entries = $registry->derive();
        $schema = $this->schemaColumns();
        $tenant = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['scope'] === MigrationTableScopeMap::SCOPE_TENANT,
        ));

        $findings = (new EnumCheckParityAnalyzer)->analyze(
            $tenant,
            (new PgValueSetCheckReader)->read(DB::connection()),
            $schema,
        );

        return self::$analysis = ['findings' => $findings, 'entries' => $entries, 'schema' => $schema];
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
     * an enum that already disagree, i.e. a live read or write bomb. They are
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
            ."NARROWER = the enum can produce a value the DB rejects (write bomb).\n",
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

    private function registry(): EnumBackedColumnRegistry
    {
        return new EnumBackedColumnRegistry(app_path(), $this->scopeMap());
    }

    private function scopeMap(): MigrationTableScopeMap
    {
        return new MigrationTableScopeMap(database_path('migrations'));
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
    private function baselineKeys(): array
    {
        $path = base_path(self::BASELINE_RELATIVE);
        $this->assertFileExists($path, 'The enum<->CHECK parity baseline is missing: '.self::BASELINE_RELATIVE);

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
