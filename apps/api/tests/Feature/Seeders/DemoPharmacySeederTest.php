<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\DemoPharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TDD test for DemoPharmacySeeder — Tunisia tenant + company + COA + tax config.
 *
 * Mirrors the booting/tenant-context style of ParapharmacyMultiBranchSeederTest.
 */
final class DemoPharmacySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_a_tunisia_tenant_with_coa_and_tax(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();

        $tenant->run(function () {
            $company = Company::firstOrFail();
            $this->assertSame('TN', $company->country_code);
            $this->assertSame('TND', $company->currency);

            // Tunisia COA system-purpose accounts present
            $this->assertTrue(Account::where('code', '411')->exists(), 'Account 411 (Clients) must exist');
            $this->assertTrue(Account::where('code', '401')->exists(), 'Account 401 (Fournisseurs) must exist');
            $this->assertTrue(Account::where('code', '419')->exists(), 'Account 419 (Clients créditeurs) must exist');
        });
    }

    public function test_company_has_tunisia_identity(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();

        $tenant->run(function () {
            $company = Company::firstOrFail();
            $this->assertSame('PharmaBio Tunisie SARL', $company->name);
            $this->assertSame('TN', $company->country_code);
            $this->assertSame('TND', $company->currency);
        });
    }

    public function test_warehouse_location_created_for_tunisia(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();

        $tenant->run(function () {
            // Task 2: warehouse only (Task 3 will add the 4 shops)
            $this->assertSame(1, \App\Modules\Company\Domain\Location::count(), 'Task 2 creates warehouse location only');
            $warehouse = \App\Modules\Company\Domain\Location::firstOrFail();
            $this->assertSame('WH-01', $warehouse->code);
            $this->assertFalse((bool) $warehouse->pos_enabled, 'Warehouse must NOT be POS-enabled');
        });
    }
}
