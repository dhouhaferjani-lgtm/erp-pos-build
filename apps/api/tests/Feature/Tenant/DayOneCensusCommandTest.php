<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Application\Services\DayOneCensus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\Feature\Tenant\Concerns\BuildsFreshTenantCensusFixture;
use Tests\TestCase;

final class DayOneCensusCommandTest extends TestCase
{
    use BuildsFreshTenantCensusFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildFreshTenantCensusFixture();
    }

    #[Test]
    public function clean_tenant_prints_every_invariant_as_ok_and_exits_zero(): void
    {
        $exitCode = Artisan::call('tenant:census-day-one', ['--fail-on-drift' => true]);
        $output = Artisan::output();

        foreach ([
            'units_visible_min_19',
            'tax_configurations_seeded',
            'required_purposes_tagged',
            'refund_purposes_seeded_by_country_template',
            'one_drawer_per_pos_location_and_one_safe',
            'cash_tender_coherent',
            'no_numbered_drafts',
            'onboarding_checklist_consistent',
        ] as $key) {
            self::assertStringContainsString($key, $output);
        }

        self::assertStringContainsString('OK', $output);
        self::assertStringContainsString("DAY-ONE CENSUS {$this->tenant->id} {$this->firstCompany->id}: CLEAN", $output);
        self::assertStringContainsString("DAY-ONE CENSUS {$this->tenant->id} {$this->secondCompany->id}: CLEAN", $output);
        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function missing_safe_prints_the_invariant_5_failure_and_fail_on_drift_exits_one(): void
    {
        PaymentRepository::query()
            ->where('company_id', $this->secondCompany->id)
            ->where('type', RepositoryType::Safe)
            ->delete();

        $exitCode = Artisan::call('tenant:census-day-one', [
            '--company' => $this->secondCompany->id,
            '--fail-on-drift' => true,
        ]);
        $output = Artisan::output();

        self::assertStringContainsString('one_drawer_per_pos_location_and_one_safe', $output);
        self::assertStringContainsString('FAIL', $output);
        self::assertStringContainsString("DAY-ONE CENSUS {$this->tenant->id} {$this->secondCompany->id}: DRIFT(1)", $output);
        self::assertSame(1, $exitCode);
    }

    #[Test]
    public function unattributed_drawer_prints_the_location_failure_and_fail_on_drift_exits_one(): void
    {
        PaymentRepository::query()
            ->where('company_id', $this->firstCompany->id)
            ->where('location_id', $this->secondLocation->id)
            ->where('type', RepositoryType::CashRegister)
            ->update(['location_id' => null]);

        $exitCode = Artisan::call('tenant:census-day-one', [
            '--company' => $this->firstCompany->id,
            '--fail-on-drift' => true,
        ]);
        $output = Artisan::output();

        self::assertStringContainsString('one_drawer_per_pos_location_and_one_safe', $output);
        self::assertStringContainsString($this->secondLocation->id, $output);
        self::assertStringContainsString('FAIL', $output);
        self::assertStringContainsString("DAY-ONE CENSUS {$this->tenant->id} {$this->firstCompany->id}: DRIFT(1)", $output);
        self::assertSame(1, $exitCode);
    }

    #[Test]
    public function drawer_without_a_gl_link_prints_failure_and_fail_on_drift_exits_one(): void
    {
        PaymentRepository::query()
            ->where('company_id', $this->firstCompany->id)
            ->where('location_id', $this->secondLocation->id)
            ->where('type', RepositoryType::CashRegister)
            ->update(['gl_account_id' => null]);

        $exitCode = Artisan::call('tenant:census-day-one', [
            '--company' => $this->firstCompany->id,
            '--fail-on-drift' => true,
        ]);
        $output = Artisan::output();

        self::assertStringContainsString('one_drawer_per_pos_location_and_one_safe', $output);
        self::assertStringContainsString('GL-linked', $output);
        self::assertStringContainsString('gl_linked=0', $output);
        self::assertStringContainsString("DAY-ONE CENSUS {$this->tenant->id} {$this->firstCompany->id}: DRIFT(1)", $output);
        self::assertSame(1, $exitCode);
    }

    #[Test]
    public function non_cash_code_with_the_sole_cash_tender_flag_prints_coherence_failure(): void
    {
        PaymentMethod::query()
            ->where('company_id', $this->firstCompany->id)
            ->where('is_active', true)
            ->where('is_cash_tender', true)
            ->update(['code' => 'CASH_ALT']);

        $exitCode = Artisan::call('tenant:census-day-one', [
            '--company' => $this->firstCompany->id,
            '--fail-on-drift' => true,
        ]);
        $output = Artisan::output();

        self::assertStringContainsString('cash_tender_coherent', $output);
        self::assertStringContainsString('active_cash_tenders=1', $output);
        self::assertStringContainsString('flagged_code=CASH_ALT', $output);
        self::assertStringContainsString('cash_code_methods=0', $output);
        self::assertStringContainsString("DAY-ONE CENSUS {$this->tenant->id} {$this->firstCompany->id}: DRIFT(1)", $output);
        self::assertSame(1, $exitCode);
    }

    #[Test]
    public function company_without_an_active_pos_location_prints_drift_instead_of_vacuous_clean(): void
    {
        DB::table('locations')
            ->where('company_id', $this->firstCompany->id)
            ->where('pos_enabled', true)
            ->update(['is_active' => false]);

        $exitCode = Artisan::call('tenant:census-day-one', [
            '--company' => $this->firstCompany->id,
            '--fail-on-drift' => true,
        ]);
        $output = Artisan::output();

        self::assertStringContainsString('active_pos_locations>=1', $output);
        self::assertStringContainsString('active_pos_locations=0', $output);
        self::assertStringContainsString("DAY-ONE CENSUS {$this->tenant->id} {$this->firstCompany->id}: DRIFT(1)", $output);
        self::assertSame(1, $exitCode);
    }

    #[Test]
    public function invalid_company_option_exits_two_without_querying_the_uuid_column(): void
    {
        $exitCode = Artisan::call('tenant:census-day-one', ['--company' => 'not-a-uuid']);

        self::assertStringContainsString('--company must be a valid UUID', Artisan::output());
        self::assertSame(2, $exitCode);
    }

    #[Test]
    public function zero_company_tenant_prints_a_grep_shaped_verdict_and_exits_one(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $tenancy = app(Tenancy::class);
        $originalTenant = $tenancy->tenant;
        config(['database.connections.census_empty_tenant' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::connection('census_empty_tenant')->statement(<<<'SQL'
            CREATE TABLE companies (
                id text,
                tenant_id text,
                name text,
                country_code text,
                default_tax_configuration_id text,
                created_at text
            )
            SQL);

        DB::setDefaultConnection('census_empty_tenant');
        $tenancy->tenant = $this->tenant;

        try {
            $exitCode = Artisan::call('tenant:census-day-one');
            $output = Artisan::output();
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge('census_empty_tenant');
            $tenancy->tenant = $originalTenant;
        }

        self::assertStringContainsString("DAY-ONE CENSUS {$this->tenant->id} -: NO-COMPANY", $output);
        self::assertSame(1, $exitCode);
    }

    #[Test]
    public function report_only_mode_keeps_exit_zero_while_printing_drift(): void
    {
        PaymentRepository::query()
            ->where('company_id', $this->firstCompany->id)
            ->where('type', RepositoryType::Safe)
            ->delete();

        $exitCode = Artisan::call('tenant:census-day-one', ['--company' => $this->firstCompany->id]);
        $output = Artisan::output();

        self::assertStringContainsString('FAIL', $output);
        self::assertStringContainsString("DAY-ONE CENSUS {$this->tenant->id} {$this->firstCompany->id}: DRIFT(1)", $output);
        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function census_resolves_the_current_connection_after_command_construction(): void
    {
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.census_central_liveness' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        DB::setDefaultConnection('census_central_liveness');
        $census = app(DayOneCensus::class);
        DB::setDefaultConnection($originalConnection);

        try {
            self::assertNotEmpty(
                $census->inspect(),
                'tenants:run constructs commands centrally, then the census must query the tenant connection bound later.',
            );
        } finally {
            DB::purge('census_central_liveness');
            DB::setDefaultConnection($originalConnection);
        }
    }
}
