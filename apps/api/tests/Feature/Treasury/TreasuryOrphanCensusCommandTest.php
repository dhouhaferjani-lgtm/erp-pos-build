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
 *      command issues and failing on anything that is not a SELECT.
 *
 * Property (3) is the one that makes it safe to run fleet-wide against
 * production tenant databases, which is the whole point of the lane.
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

        $offending = [];
        DB::listen(function ($query) use (&$offending): void {
            if (preg_match('/^\s*(select|savepoint|release|rollback)\b/i', $query->sql) !== 1) {
                $offending[] = $query->sql;
            }
        });

        Artisan::call('treasury:orphan-census', ['--json' => true]);

        self::assertSame([], $offending, 'treasury:orphan-census must be strictly read-only');
    }

    public function test_exit_code_is_success_even_when_orphans_are_found(): void
    {
        $this->seedPaymentWithBogusRepository((string) Str::uuid());

        self::assertSame(0, Artisan::call('treasury:orphan-census', ['--json' => true]));
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
        Artisan::call('treasury:orphan-census', ['--json' => true]);

        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
