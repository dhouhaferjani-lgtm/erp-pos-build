<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Application\Services\DayOneCensus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
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
            'payment_methods_seeded',
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
