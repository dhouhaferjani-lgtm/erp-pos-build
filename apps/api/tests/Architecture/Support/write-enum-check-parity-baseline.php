<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Architecture\Support\EnumBackedColumnRegistry;
use Tests\Architecture\Support\EnumCheckParityAcknowledgements;
use Tests\Architecture\Support\EnumCheckParityAnalyzer;
use Tests\Architecture\Support\MigrationTableScopeMap;
use Tests\Architecture\Support\PgValueSetCheckReader;

/**
 * BOOTSTRAP-ONLY writer for the enum<->CHECK parity baseline AND the derived
 * register artifact.
 *
 *   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 \
 *   DB_DATABASE=autoerp_<lane>_test DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
 *   APP_ENV=testing CACHE_STORE=array \
 *   php tests/Architecture/Support/write-enum-check-parity-baseline.php
 *
 * The database it is pointed at MUST be a freshly migrated throwaway (the writer
 * refuses any name outside `autoerp_*test`): it reads the live `pg_constraint`
 * catalogue, so a half-migrated or hand-patched database writes a WRONG baseline.
 *
 * NOT part of CI and NOT part of any normal fix flow. The baseline is a
 * SHRINK-ONLY ratchet: entries leave it as the CHECK-adding batches close gaps,
 * and re-running this writer to ABSORB a new gap is not a fix — it is the exact
 * move the ratchet exists to stop. Growing the baseline is an owner decision on
 * the record.
 *
 * Writes three files:
 *   tests/Architecture/baselines/enum-check-parity-baseline.json          — the tenant ratchet
 *   tests/Architecture/baselines/enum-check-parity-central-baseline.json  — the central ratchet (T-3)
 *   tests/Architecture/baselines/enum-check-parity-register.md            — the burn-down denominator
 *
 * It READS, and never writes, `enum-check-parity-acknowledgements.json`. An
 * acknowledgement is a hand-authored claim about the code, asserted un-baselined by
 * `EnumCheckParityTest::acknowledgements_still_describe_the_live_schema()`;
 * generating it would make it self-referential and worthless.
 */
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) DB::connection()->getDatabaseName();
if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "[enum-check-parity] refusing to write a baseline from a non-PostgreSQL connection.\n");
    exit(2);
}
if (preg_match('/^autoerp_[a-z0-9_]*test$/', $database) !== 1) {
    fwrite(STDERR, "[enum-check-parity] refusing to read '{$database}': point this at a throwaway autoerp_*test database.\n");
    exit(2);
}

$scopes = new MigrationTableScopeMap($root.'/database/migrations');
$registry = new EnumBackedColumnRegistry($root.'/app', $scopes);
$analyzer = new EnumCheckParityAnalyzer;

$schemaColumns = [];
foreach (DB::select('SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = current_schema()') as $row) {
    /** @var object{table_name: string, column_name: string} $row */
    $schemaColumns[$row->table_name][] = $row->column_name;
}

$checks = (new PgValueSetCheckReader)->read(DB::connection());
$all = $registry->derive();
$tenant = $registry->deriveTenantScoped();
$central = array_values(array_filter($all, static fn (array $e): bool => $e['scope'] === MigrationTableScopeMap::SCOPE_CENTRAL));
$excluded = $registry->excludedEnumColumns();

$acknowledgements = EnumCheckParityAcknowledgements::fromFile(
    $root.'/tests/Architecture/baselines/enum-check-parity-acknowledgements.json'
);
$verdictMap = $acknowledgements->verdictMap();

$findings = $analyzer->analyze($tenant, $checks, $schemaColumns, $verdictMap);
$failures = $analyzer->failures($findings);

$centralFindings = $analyzer->analyze($central, $checks, $schemaColumns, $verdictMap);
$centralFailures = $analyzer->failures($centralFindings);

$keys = array_values(array_unique(array_column($failures, 'key')));
sort($keys, SORT_STRING);
$centralKeys = array_values(array_unique(array_column($centralFailures, 'key')));
sort($centralKeys, SORT_STRING);

$baselinePath = $root.'/tests/Architecture/baselines/enum-check-parity-baseline.json';
if (! is_dir(dirname($baselinePath))) {
    mkdir(dirname($baselinePath), 0o755, true);
}
file_put_contents($baselinePath, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents(
    $root.'/tests/Architecture/baselines/enum-check-parity-central-baseline.json',
    json_encode($centralKeys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
);

// ------------------------------------------------------------------ register --
$counts = [];
foreach ($findings as $finding) {
    $counts[$finding['verdict']] = ($counts[$finding['verdict']] ?? 0) + 1;
}
ksort($counts);

$statusNamed = array_values(array_filter($tenant, static fn (array $e): bool => str_ends_with($e['column'], 'status')));
$statusNamedKeys = array_fill_keys(array_map(static fn (array $e): string => $e['table'].'.'.$e['column'], $statusNamed), true);
$statusCovered = 0;
foreach ($findings as $finding) {
    if (isset($statusNamedKeys[$finding['table'].'.'.$finding['column']]) && $finding['verdict'] === EnumCheckParityAnalyzer::VERDICT_COVERED) {
        $statusCovered++;
    }
}

$lines = [];
$lines[] = '# Enum ↔ CHECK parity register — the slice-D burn-down denominator';
$lines[] = '';
$lines[] = '> GENERATED by `tests/Architecture/Support/write-enum-check-parity-baseline.php`. Do not hand-edit.';
$lines[] = '> Derived mechanically from model `$casts` (+ the `GOVERNED_AUDIT_COLUMNS` supplement) against a';
$lines[] = '> freshly migrated throwaway database. Regenerate after any CHECK-adding batch.';
$lines[] = '';
$lines[] = sprintf(
    'Generated: %s · schema: %d tables declared across BOTH migration trees (%d central + %d tenant) · registry: %d enum-governed columns total.',
    date('Y-m-d'),
    count($scopes->map()),
    count(array_filter($scopes->map(), static fn (string $v): bool => $v === MigrationTableScopeMap::SCOPE_CENTRAL)),
    count(array_filter($scopes->map(), static fn (string $v): bool => $v === MigrationTableScopeMap::SCOPE_TENANT)),
    count($all)
);
$lines[] = '';
$lines[] = '## Denominator';
$lines[] = '';
$lines[] = '| Scope | Columns | Baseline | Note |';
$lines[] = '|---|---:|---:|---|';
$lines[] = sprintf('| tenant (ASSERTED) | %d | %d | the gate\'s primary population |', count($tenant), count($keys));
$lines[] = sprintf(
    '| central (ASSERTED separately) | %d | %d | a different SCOPE, not a different migration path — see below |',
    count($central),
    count($centralKeys)
);
$lines[] = '';
$lines[] = '**The test database is a UNION of both migration trees.** Under `APP_ENV=testing`,';
$lines[] = '`AppServiceProvider::boot()` loads `database/migrations/tenant` alongside the default central tree, so a';
$lines[] = 'single `migrate` builds one database holding both. The tenant DDL is byte-for-byte the directory';
$lines[] = '`tenants:migrate` applies in production, so the tenant verdicts are faithful; what the union adds is the';
$lines[] = 'central tree, which is asserted as its own population against its own baseline. The property that keeps';
$lines[] = 'the union honest — **no central migration mutates a tenant-scoped table** — is asserted by';
$lines[] = '`EnumCheckParityTest::no_central_migration_mutates_a_tenant_scoped_table()`; if it ever broke, this gate';
$lines[] = 'would read a CHECK that no real `tenant_<uuid>` database has.';
$lines[] = '';
$lines[] = '| Verdict | Tenant columns |';
$lines[] = '|---|---:|';
foreach ($counts as $verdict => $count) {
    $lines[] = sprintf('| %s | %d |', $verdict, $count);
}
$lines[] = '';
$lines[] = sprintf('**Baseline size: %d.** That is the number that must go DOWN. `status`-suffixed columns: %d of %d covered.', count($keys), $statusCovered, count($statusNamed));
$lines[] = '';
$lines[] = '## Acknowledged verdicts — NOT debt, and NOT waivers';
$lines[] = '';
$lines[] = 'Two verdicts are neither covered nor a gap. They are declared in';
$lines[] = '`enum-check-parity-acknowledgements.json` and every clause of each declaration is asserted, un-baselined,';
$lines[] = 'by `EnumCheckParityTest::acknowledgements_still_describe_the_live_schema()`. A change to EITHER side —';
$lines[] = 'the CHECK\'s admitted set, the enum\'s cases, or the governing predicate — fails the gate.';
$lines[] = '';
$lines[] = '| Column | Verdict | Pinned by | Claim |';
$lines[] = '|---|---|---|---|';
foreach ($acknowledgements->entries() as $entry) {
    /** @var array{table: string, column: string, kind: string, reason: string, constraint?: string, constraints?: list<string>} $entry */
    $pinned = $entry['constraint'] ?? implode(', ', $entry['constraints'] ?? []);
    $lines[] = sprintf(
        '| `%s.%s` | %s | `%s` | %s |',
        $entry['table'],
        $entry['column'],
        $entry['kind'],
        $pinned,
        str_replace('|', '\\|', (string) $entry['reason'])
    );
}
$lines[] = '';
$lines[] = '**`fiscal_event_quarantine.integrity_exception_class` MUST NOT BE "FIXED" BY WIDENING ITS CHECK.** The';
$lines[] = 'table is the non-ledger-admissible half of a partition; the other four `IntegrityExceptionClass` cases are';
$lines[] = 'quarantined in-table on `fiscal_events.integrity_exception_class`. Widening the CHECK to all six values';
$lines[] = 'destroys the partition — it lets a `sequence_gap` be written into the table reserved for classes that may';
$lines[] = 'never reach the ledger. The governing predicate is `IntegrityExceptionClass::isAdmissibleToLedger()`';
$lines[] = '(`app/Modules/Fiscal/Domain/Enums/IntegrityExceptionClass.php:26-29`), and the only INSERT path';
$lines[] = '(`OutboxIngestor::insertQuarantineRow()`, `:925`; callers `:359`, `:1154`) writes exactly the two';
$lines[] = 'acknowledged values.';
$lines[] = '';
$lines[] = '## Register — every enum-governed tenant column';
$lines[] = '';
$lines[] = 'Notes: `NOT VALID` = the CHECK is enforced on new writes but was never validated against existing rows';
$lines[] = '(the mandated rollout idiom — it is READ and flagged, never reported as absent). `null-guarded` = the';
$lines[] = 'CHECK carries an explicit `IS NULL` branch.';
$lines[] = '';
$lines[] = '| Table | Column | Enum | Verdict | Notes | Baselined |';
$lines[] = '|---|---|---|---|---|---|';
$baselineSet = array_fill_keys($keys, true);
foreach ($findings as $finding) {
    $notes = [];
    if ($finding['not_validated']) {
        $notes[] = 'NOT VALID';
    }
    if ($finding['nullable']) {
        $notes[] = 'null-guarded';
    }
    if ($finding['acknowledged_as'] !== null) {
        $notes[] = 'acknowledged';
    }
    $lines[] = sprintf(
        '| `%s` | `%s` | `%s` | %s | %s | %s |',
        $finding['table'],
        $finding['column'],
        $finding['enum'],
        $finding['verdict'],
        $notes === [] ? '—' : implode(', ', $notes),
        isset($baselineSet[$finding['key']]) ? 'yes' : '—',
    );
}
$lines[] = '';
$lines[] = '## Un-gateable columns — a DIFFERENT defect class (missing enum, not missing CHECK)';
$lines[] = '';
$lines[] = 'These columns hold a state machine\'s state but have no enum for a CHECK to be compared against, so this';
$lines[] = 'gate structurally cannot assert them in either direction. They are declared in';
$lines[] = '`EnumBackedColumnRegistry::UNGATEABLE_COLUMNS` and rot-guarded by';
$lines[] = '`EnumCheckParityTest::the_ungateable_supplement_still_resolves()`: the day the missing cast is added, the';
$lines[] = 'entry becomes redundant, the test demands its deletion, and the column joins the asserted population.';
$lines[] = '';
$lines[] = '| Table | Column | Why un-gateable |';
$lines[] = '|---|---|---|';
foreach (EnumBackedColumnRegistry::UNGATEABLE_COLUMNS as $declared) {
    $lines[] = sprintf('| `%s` | `%s` | %s |', $declared['table'], $declared['column'], $declared['why']);
}
$lines[] = '';
$lines[] = '## Cast-target enums EXCLUDED BY DESIGN (outside the recognised enum directories)';
$lines[] = '';
$lines[] = 'The derivation recognises enum files under `app/**/Domain/Enums/` and `app/**/Shared/Enums/`. Every';
$lines[] = 'remaining cast-target enum is listed here BY COLUMN so the population boundary is disclosed by name.';
$lines[] = 'Moving such an enum into a recognised directory (or widening the recogniser) pulls its column into the';
$lines[] = 'population — and, if it has no CHECK, into the baseline.';
$lines[] = '';
if ($excluded === []) {
    $lines[] = '_None: every cast-target enum under `app/` is in a recognised directory._';
} else {
    $lines[] = '| Table | Column | Enum | File | Scope |';
    $lines[] = '|---|---|---|---|---|';
    foreach ($excluded as $entry) {
        $lines[] = sprintf(
            '| `%s` | `%s` | `%s` | `%s` | %s |',
            $entry['table'],
            $entry['column'],
            $entry['enum'],
            $entry['file'],
            $entry['scope'],
        );
    }
}
$lines[] = '';
$lines[] = '## Divergences vs the §#26 hand census (2026-08-23 state-machine sweep)';
$lines[] = '';
$lines[] = 'The sweep put the population at **90 status columns, 76 uncovered, 14 constrained**. That census was';
$lines[] = 'grep-based over the migration tree and it is wrong in both directions. Recorded here because the';
$lines[] = 'burn-down denominator has to be the mechanical number, not the remembered one:';
$lines[] = '';
$lines[] = '- **It missed every `$table->enum()` column.** Laravel renders `enum()` on PostgreSQL as a varchar plus';
$lines[] = '  an auto-named `{table}_{column}_check`, which no grep for `ADD CONSTRAINT … CHECK` finds. So';
$lines[] = '  `pos_receipts.fiscal_status` (constrained since `tenant/2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php`)';
$lines[] = '  and the whole `impersonation_*` family were filed as uncovered when they are covered.';
$lines[] = sprintf('- **It counted a different population.** The register above is every column an `app/**/Domain/Enums/*`');
$lines[] = sprintf('  or `app/**/Shared/Enums/*` enum GOVERNS, not every column named `*status*`: %d tenant columns, not 90.', count($tenant));
$lines[] = sprintf('  Restricted to `*status`-suffixed columns the count is %d, of which %d are covered — close to the', count($statusNamed), $statusCovered);
$lines[] = '  sweep\'s 76/14 but not equal, and the gap is columns with no enum cast at all (see the un-gateable section).';
$lines[] = '- **`bank_reconciliations.status` cannot be asserted at all.** The table carries';
$lines[] = '  `bank_reconciliations_status_check` but has NO Eloquent model, so there is no enum to compare it to.';
$lines[] = '  The sweep counted it among the 14 constrained; this gate cannot see it. See the un-gateable section for';
$lines[] = '  the full, rot-guarded list.';
$lines[] = '';
$lines[] = '## Central-database columns — asserted against their own baseline (T-3)';
$lines[] = '';
$lines[] = 'In production these live in `synerivia_central`, reached by the default `migrate`. Here both trees are in';
$lines[] = 'one union database, so the central population is readable and IS asserted — separately, so it cannot';
$lines[] = 'distort the tenant burn-down. Nine of these columns are on the impersonation / support-access privilege';
$lines[] = 'path: their CHECKs are the last DB-level defence on a state machine that gates cross-tenant access.';
$lines[] = '';
$lines[] = '| Table | Column | Enum | Verdict | Baselined |';
$lines[] = '|---|---|---|---|---|';
$centralBaselineSet = array_fill_keys($centralKeys, true);
foreach ($centralFindings as $finding) {
    $lines[] = sprintf(
        '| `%s` | `%s` | `%s` | %s | %s |',
        $finding['table'],
        $finding['column'],
        $finding['enum'],
        $finding['verdict'],
        isset($centralBaselineSet[$finding['key']]) ? 'yes' : '—',
    );
}
$lines[] = '';

file_put_contents($root.'/tests/Architecture/baselines/enum-check-parity-register.md', implode("\n", $lines));

fwrite(STDERR, sprintf(
    "[enum-check-parity] %d tenant columns, %d tenant baseline entries; %d central columns, %d central baseline entries; verdicts: %s\n",
    count($tenant),
    count($keys),
    count($central),
    count($centralKeys),
    json_encode($counts),
));
