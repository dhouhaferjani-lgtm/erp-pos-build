<?php

declare(strict_types=1);

namespace Tests\Feature\Uom;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Closure;
use Database\Seeders\UomSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

final class UnitsInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_unprovisioned_database_is_untouched(): void
    {
        $this->runMigration();

        $this->assertSame(0, DB::table('units')->count());
        $this->assertSame(0, DB::table('unit_categories')->count());
    }

    public function test_unit_less_provisioned_tenant_is_seeded_after_the_census(): void
    {
        $company = $this->provisionedCompany();
        $logSpy = Log::spy();

        $output = $this->runMigration();

        $codes = DB::table('units')->pluck('code')->map(
            static fn (mixed $code): string => strtolower((string) $code)
        )->all();
        $this->assertCount(19, $codes);
        foreach (['pc', 'g', 'kg', 'ml', 'l', 'mm', 'cm', 'm', 'min', 'hr'] as $expected) {
            $this->assertContains($expected, $codes, "The invariant backfill must include '{$expected}'.");
        }

        $logSpy->shouldHaveReceived('info', ['units.visibility_census companies=1 empty=1']);
        $logSpy->shouldHaveReceived('warning', [
            'units.empty_for_company',
            ['company' => $company->id],
        ]);
        $logSpy->shouldHaveReceived('info', ['units-seeded company_id='.$company->id]);
        $this->assertSame('', $output);
    }

    public function test_already_correct_tenant_logs_zero_empty_companies_and_writes_nothing(): void
    {
        $this->provisionedCompany();
        (new UomSeeder)->run();
        $units = DB::table('units')->count();
        $categories = DB::table('unit_categories')->count();
        $logSpy = Log::spy();

        $output = $this->runMigration();

        $this->assertSame($units, DB::table('units')->count());
        $this->assertSame($categories, DB::table('unit_categories')->count());
        $logSpy->shouldHaveReceived('info', ['units.visibility_census companies=1 empty=0']);
        $this->assertSame('', $output);
    }

    public function test_second_up_is_a_clean_no_op_after_backfill(): void
    {
        $this->provisionedCompany();
        $this->runMigration();
        $units = DB::table('units')->count();
        $categories = DB::table('unit_categories')->count();
        $logSpy = Log::spy();

        $output = $this->runMigration();

        $this->assertSame($units, DB::table('units')->count());
        $this->assertSame($categories, DB::table('unit_categories')->count());
        $logSpy->shouldHaveReceived('info', ['units.visibility_census companies=1 empty=0']);
        $this->assertSame('', $output);
    }

    public function test_half_state_is_logged_but_not_seeded_and_does_not_throw(): void
    {
        $company = $this->provisionedCompany();
        (new UomSeeder)->run();
        DB::table('units')->delete();
        $categories = DB::table('unit_categories')->count();
        $logSpy = Log::spy();

        $output = $this->runMigration();

        $this->assertSame(0, DB::table('units')->count());
        $this->assertSame($categories, DB::table('unit_categories')->count());
        $logSpy->shouldHaveReceived('info', ['units.visibility_census companies=1 empty=1']);
        $logSpy->shouldHaveReceived('warning', [
            'units.empty_for_company',
            ['company' => $company->id],
        ]);
        $this->assertSame('', $output);
    }

    public function test_mid_seed_failure_is_logged_and_leaves_both_tables_empty(): void
    {
        $this->provisionedCompany();
        $failure = new RuntimeException('Injected failure after the second unit insert.');
        $this->failAfterSecondUnitInsert($failure);
        $logSpy = Log::spy();

        $output = $this->runMigration();

        $this->assertSame(0, DB::table('units')->count());
        $this->assertSame(0, DB::table('unit_categories')->count());
        $logSpy->shouldHaveReceived('error', ['units.visibility_census companies=1 empty=1 seed_failed=1']);
        $this->assertSame('', $output);
    }

    public function test_failed_seed_is_retried_to_completion_on_the_next_up(): void
    {
        $company = $this->provisionedCompany();
        $this->failAfterSecondUnitInsert(
            new RuntimeException('Injected one-shot unit seed failure.'),
        );

        $this->runMigration();

        $this->assertSame(0, DB::table('units')->count());
        $this->assertSame(0, DB::table('unit_categories')->count());

        $logSpy = Log::spy();
        $output = $this->runMigration();

        $this->assertSame(19, DB::table('units')->count());
        $this->assertSame(5, DB::table('unit_categories')->count());
        $logSpy->shouldHaveReceived('info', ['units-seeded company_id='.$company->id]);
        $this->assertSame('', $output);
    }

    private function provisionedCompany(): Company
    {
        $tenant = Tenant::create([
            'name' => 'Units Invariant Tenant',
            'slug' => 'units-invariant-'.uniqid(),
            'status' => TenantStatus::Active,
            'country_code' => 'TN',
            'currency_code' => 'TND',
        ]);

        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Units Invariant Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);
    }

    private function failAfterSecondUnitInsert(RuntimeException $failure): void
    {
        $unitInsertCount = 0;

        DB::listen(static function (QueryExecuted $query) use (&$unitInsertCount, $failure): void {
            if (preg_match('/insert into\s+["`]?units["`]?/i', $query->sql) !== 1) {
                return;
            }

            $unitInsertCount++;
            if ($unitInsertCount === 2) {
                throw $failure;
            }
        });
    }

    private function runMigration(): string
    {
        $migration = require __DIR__.'/../../../database/migrations/tenant/2026_08_30_100300_ensure_units_visible_per_company.php';
        if (! is_object($migration) || ! is_callable([$migration, 'up'])) {
            self::fail('The units invariant migration must return an object with an up() method.');
        }

        ob_start();
        Closure::fromCallable([$migration, 'up'])();

        $output = ob_get_clean();

        return $output === false ? '' : $output;
    }
}
