<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lane Q-12 (triage F5) — `treasury:orphan-census`.
 *
 * DS-1's nine bare-uuid treasury columns carry no FK and no orphan detector.
 * The DS-1 constraint lane (`NOT VALID` + `VALIDATE CONSTRAINT`) is go/no-go
 * gated on the orphan population, because Postgres validates an FK against
 * every existing row and aborts the whole per-tenant migration on the first
 * orphan, mid-fleet. This command is the detector; these tests pin its three
 * load-bearing properties:
 *
 *   1. it FINDS a seeded orphan and attributes it to the right column;
 *   2. it reports all-zero for a clean tenant;
 *   3. it is STRICTLY READ-ONLY — asserted by listening to every query the
 *      command issues and failing on anything that is not a SELECT, and
 *      (gate r1 F-3) by first proving the listener actually observed the
 *      census reading each of the three source tables, so the pin cannot pass
 *      vacuously;
 *   4. INCOMPLETE COVERAGE is never dressed up as a clean fleet — a run that
 *      skipped a tenant, or that visited none at all, reports
 *      `complete: false` with a named `reason` and exits FAILURE, while
 *      orphan FINDINGS alone keep exit 0 (the deviation the r1 gate ACCEPTED).
 *
 * Property (3) is the one that makes it safe to run fleet-wide against
 * production tenant databases, which is the whole point of the lane.
 * Property (4) is what keeps its output usable as a go/no-go: every zero this
 * command prints must be a MEASURED zero.
 */
final class TreasuryOrphanCensusCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Census Tenant',
            'slug' => 'census-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Census Co',
            'legal_name' => 'Census Co LLC',
            'tax_id' => 'TAXCENSUS',
            'country_code' => 'TN',
            'locale' => 'fr_FR',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    public function test_clean_tenant_reports_all_zero_orphans(): void
    {
        $payload = $this->runCensusJson();

        self::assertSame(0, $payload['totals']['orphans']);
        self::assertTrue($payload['complete']);

        foreach ($payload['tenants'][0]['columns'] as $column) {
            if ($column['status'] === 'checked') {
                self::assertSame(0, $column['orphans'], "{$column['table']}.{$column['column']} should be clean");
            }
        }
    }

    public function test_seeded_orphan_is_found_and_attributed_to_its_column(): void
    {
        $bogusRepositoryId = (string) Str::uuid();
        $paymentId = $this->seedPaymentWithBogusRepository($bogusRepositoryId);

        $payload = $this->runCensusJson();

        $entry = $this->column($payload, 'payments', 'repository_id');

        self::assertSame('checked', $entry['status']);
        self::assertSame(1, $entry['orphans']);
        self::assertSame('payment_repositories', $entry['target_table']);
        self::assertSame([['id' => $paymentId, 'value' => $bogusRepositoryId]], $entry['samples']);

        // Attribution: no OTHER column may be blamed for this one orphan.
        self::assertSame(1, $payload['totals']['orphans']);
        self::assertSame(0, $this->column($payload, 'payments', 'instrument_id')['orphans']);
        self::assertSame(0, $this->column($payload, 'payments', 'journal_entry_id')['orphans']);
    }

    public function test_json_output_shape_is_pinned(): void
    {
        $payload = $this->runCensusJson();

        self::assertSame('treasury:orphan-census', $payload['command']);
        self::assertArrayHasKey('generated_at', $payload);
        self::assertArrayHasKey('complete', $payload);
        self::assertArrayHasKey('reason', $payload);
        self::assertNull($payload['reason'], 'a complete run names no incompleteness reason');
        self::assertSame(
            ['columns_checked', 'columns_unresolvable', 'orphans', 'tenants_skipped', 'tenants_visited'],
            $this->sortedKeys($payload['totals']),
        );

        $columns = $payload['tenants'][0]['columns'];
        self::assertCount(9, $columns, 'the census must cover exactly DS-1\'s nine columns');

        self::assertSame(
            [
                ['payment_methods', 'default_account_id'],
                ['payment_methods', 'default_journal_id'],
                ['payment_methods', 'fee_account_id'],
                ['payment_repositories', 'account_id'],
                ['payment_repositories', 'location_id'],
                ['payment_repositories', 'responsible_user_id'],
                ['payments', 'instrument_id'],
                ['payments', 'journal_entry_id'],
                ['payments', 'repository_id'],
            ],
            $this->sortedPairs($columns),
        );

        foreach ($columns as $column) {
            self::assertSame(
                ['column', 'non_null', 'orphans', 'samples', 'status', 'table', 'target_column', 'target_table'],
                $this->sortedKeys($column),
            );
        }

        // `payment_methods.default_journal_id` has NO target table in this
        // schema (there is no `journals` table — see PaymentMethodController
        // :90). The census must SAY SO rather than silently reporting zero,
        // because "zero orphans" would be a false GO for the FK lane.
        $journal = $this->column($payload, 'payment_methods', 'default_journal_id');
        self::assertSame('target_table_missing', $journal['status']);
        self::assertNull($journal['orphans']);
    }

    public function test_command_issues_only_select_statements(): void
    {
        $this->seedPaymentWithBogusRepository((string) Str::uuid());

        /** @var list<string> $observed */
        $observed = [];
        /** @var list<string> $offending */
        $offending = [];

        DB::listen(function ($query) use (&$observed, &$offending): void {
            $observed[] = (string) $query->sql;

            if (preg_match('/^\s*(select|savepoint|release|rollback)\b/i', (string) $query->sql) !== 1) {
                $offending[] = (string) $query->sql;
            }
        });

        Artisan::call('treasury:orphan-census', ['--json' => true]);

        // NON-VACUITY FIRST (gate r1 F-3). `assertSame([], $offending)` on its
        // own is satisfied by an EMPTY observation set, so the day the listener
        // stops firing — a Laravel dispatcher change, a connection built
        // outside the shared event bus, a command that stops querying at all —
        // the lane's central safety claim ("strictly read-only, fleet-safe
        // against production tenant databases") would go green forever while
        // proving nothing. Pin that the detector actually watched the command
        // work: it must have seen statements, and it must have seen a SELECT
        // against each of the three DS-1 source tables.
        self::assertGreaterThan(
            0,
            count($observed),
            'the read-only listener observed ZERO statements — the read-only pin would pass vacuously',
        );

        // Compared on the OUTER `from` target, not on "the name appears
        // somewhere in the SQL": every `payments` census query names
        // `payment_repositories` in its `not exists` subquery, so a substring
        // test would report coverage of a source table the census had stopped
        // reading (verified by probe — see the lane report).
        $scanned = array_values(array_unique(array_filter(array_map(
            static function (string $sql): ?string {
                if (preg_match('/^\s*select\b/i', $sql) !== 1) {
                    return null;
                }

                return preg_match('/\bfrom\s+"([a-z_]+)"/i', $sql, $m) === 1 ? $m[1] : null;
            },
            $observed,
        ))));

        foreach (['payment_repositories', 'payment_methods', 'payments'] as $sourceTable) {
            self::assertContains(
                $sourceTable,
                $scanned,
                "no SELECT was observed reading FROM {$sourceTable} — the census did not measure that table, ".
                'so a clean read-only verdict says nothing about it',
            );
        }

        self::assertSame([], $offending, 'treasury:orphan-census must be strictly read-only');
    }

    public function test_exit_code_is_success_even_when_orphans_are_found(): void
    {
        $this->seedPaymentWithBogusRepository((string) Str::uuid());

        self::assertSame(0, Artisan::call('treasury:orphan-census', ['--json' => true]));
    }

    /**
     * C1 / gate r1 F-1 — an EMPTY tenant directory must never read as a GO.
     *
     * `forEachTenantNarrowed()` never enters its loop when the directory is
     * empty, so nothing is visited, nothing is skipped and the aggregate stays
     * SUCCESS. Before the fix that produced the single most dangerous artifact
     * this command can emit: `complete: true`, `orphans: 0`, exit 0 — from a
     * run that measured NOTHING. The realistic trigger is a wrong
     * `DB_CENTRAL_DATABASE` / `DB_*` export in an ops shell, i.e. the most
     * common deploy-time mistake there is, and the FK lane is told to read this
     * JSON as go/no-go.
     */
    public function test_empty_tenant_directory_is_reported_incomplete_and_fails(): void
    {
        // Empty the directory the command iterates (`Tenant::all()`), through
        // the same connection the model reads. Raw builder on purpose: model
        // deletion events would drag tenant-database teardown into a test that
        // is only about the directory being empty.
        (new Tenant)->getConnection()->table('tenants')->delete();

        $run = $this->runCensus();

        self::assertNotSame(
            0,
            $run['exit'],
            'a census that visited zero tenants must exit non-zero — exit 0 is read as GO by the DS-1 constraint lane',
        );

        $payload = $run['payload'];

        self::assertFalse($payload['complete'], 'a zero-tenant run measured nothing and cannot be complete');
        self::assertSame('no_tenants_in_directory', $payload['reason']);

        /** @var array<string, int> $totals */
        $totals = $payload['totals'];
        self::assertSame(0, $totals['tenants_visited']);
        self::assertSame(0, $totals['tenants_skipped']);
        self::assertSame(0, $totals['columns_checked']);
        self::assertSame(0, $totals['orphans'], 'the zero here is an ARTEFACT of measuring nothing, not a clean fleet');
        self::assertSame([], $payload['tenants']);
    }

    /**
     * C2 / gate r1 F-2 — pins the ACCEPTED exit-code deviation, both halves.
     *
     * The brief specified "exit 0 always (census, not a gate)"; the gate ruled
     * the implementation's split ACCEPTED: orphan FINDINGS keep exit 0 (an
     * operator must be able to run this fleet-wide without a red pipeline —
     * {@see self::test_exit_code_is_success_even_when_orphans_are_found()}),
     * but INCOMPLETE COVERAGE exits FAILURE, because a partial "zero orphans"
     * is the false GO that strands a fleet migration in mixed schema state.
     * That deviation is the lane's most consequential design decision and it
     * was asserted nowhere — exactly the property a future refactor drops in
     * silence. This test is that assertion.
     *
     * Driven honestly: flipping the command into db-per-tenant mode makes
     * `forEachTenantNarrowed()` probe for the tenant's own database, which the
     * single-schema test harness has never provisioned — so the tenant lands
     * on the SKIPPED branch (or, if the probe itself cannot be answered, on
     * the probe-fault branch, which is also skipped + FAILURE). Either way the
     * command is looking at the real "coverage was incomplete" state, not a
     * stubbed one.
     */
    public function test_incomplete_coverage_sets_complete_false_and_exits_failure(): void
    {
        $this->seedPaymentWithBogusRepository((string) Str::uuid());

        config(['tenancy_resolver.db_per_tenant' => true]);

        $run = $this->runCensus();

        self::assertNotSame(0, $run['exit'], 'incomplete coverage must fail the run');

        $payload = $run['payload'];

        self::assertFalse($payload['complete']);
        self::assertSame('tenants_skipped', $payload['reason']);

        /** @var array<string, int> $totals */
        $totals = $payload['totals'];
        self::assertSame(0, $totals['tenants_visited']);
        self::assertSame(1, $totals['tenants_skipped']);

        // The false GO in miniature: a real orphan exists in this tenant's
        // data and the run still totals zero, because it never looked. Only
        // `complete`/`reason`/the exit code separate this from a clean fleet.
        self::assertSame(0, $totals['orphans']);
    }

    private function seedPaymentWithBogusRepository(string $bogusRepositoryId): string
    {
        $methodId = (string) Str::uuid();
        DB::table('payment_methods')->insert([
            'id' => $methodId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-CENSUS',
            'name' => 'Cash',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $partnerId = (string) Str::uuid();
        DB::table('partners')->insert([
            'id' => $partnerId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CUST-CENSUS',
            'name' => 'Census Customer',
            'type' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentId = (string) Str::uuid();
        DB::table('payments')->insert([
            'id' => $paymentId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partnerId,
            'payment_method_id' => $methodId,
            'repository_id' => $bogusRepositoryId,
            'amount' => '10.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $paymentId;
    }

    /** @return array<string, mixed> */
    private function runCensusJson(): array
    {
        return $this->runCensus()['payload'];
    }

    /**
     * Run the census in `--json` mode and return BOTH halves of its contract:
     * the payload and the exit code. The exit code is not decoration here —
     * it is the only signal an ops/CI wrapper reads without parsing JSON.
     *
     * @param  array<string, mixed>  $options
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function runCensus(array $options = []): array
    {
        $exit = Artisan::call('treasury:orphan-census', $options + ['--json' => true]);

        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return ['exit' => $exit, 'payload' => $decoded];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function column(array $payload, string $table, string $column): array
    {
        /** @var list<array<string, mixed>> $columns */
        $columns = $payload['tenants'][0]['columns'];

        foreach ($columns as $entry) {
            if ($entry['table'] === $table && $entry['column'] === $column) {
                return $entry;
            }
        }

        self::fail("census has no entry for {$table}.{$column}");
    }

    /**
     * @param  array<string, mixed>  $assoc
     * @return list<string>
     */
    private function sortedKeys(array $assoc): array
    {
        $keys = array_keys($assoc);
        sort($keys);

        return $keys;
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return list<array{0: string, 1: string}>
     */
    private function sortedPairs(array $columns): array
    {
        $pairs = array_map(
            static fn (array $c): array => [(string) $c['table'], (string) $c['column']],
            $columns,
        );
        sort($pairs);

        return $pairs;
    }
}
