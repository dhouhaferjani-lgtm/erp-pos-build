<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
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
            // Task 3: 1 warehouse + 4 shops = 5 total locations
            $this->assertSame(5, \App\Modules\Company\Domain\Location::count(), '5 locations: 1 warehouse + 4 shops');
            $warehouse = \App\Modules\Company\Domain\Location::where('code', 'WH-01')->firstOrFail();
            $this->assertSame('WH-01', $warehouse->code);
            $this->assertFalse((bool) $warehouse->pos_enabled, 'Warehouse must NOT be POS-enabled');
        });
    }

    public function test_seeds_warehouse_and_four_shops_with_valid_matricule(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $locations = \App\Modules\Company\Domain\Location::all();
            $this->assertCount(5, $locations);
            $wh = $locations->firstWhere('code', 'WH-01');
            $this->assertSame('warehouse', $wh->type->value);
            $this->assertFalse($wh->pos_enabled);
            $this->assertNull($wh->tax_id); // inherits company

            $tunisRegex = '/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/';
            foreach (['STORE-TUN1', 'STORE-TUN2', 'STORE-SOU', 'STORE-SFA'] as $code) {
                $shop = $locations->firstWhere('code', $code);
                $this->assertNotNull($shop, "Shop location {$code} must exist");
                $this->assertSame('shop', $shop->type->value);
                $this->assertTrue($shop->pos_enabled);
                $this->assertMatchesRegularExpression($tunisRegex, str_replace('/', '', (string) $shop->tax_id));
            }
        });
    }

    public function test_tenant_central_row_has_tunisia_identity(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        // Central-DB fields — must be asserted OUTSIDE $tenant->run() which
        // swaps the default connection to the per-tenant DB.
        $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();
        $this->assertSame('PharmaBio Tunisie SARL', $tenant->name);
        $this->assertSame('1234567AM000', $tenant->tax_id);
    }

    public function test_test_users_have_tunisia_email_domain(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();

        $tenant->run(function () use ($tenant) {
            $this->assertTrue(
                User::where('email', 'owner@pharmabio.tn')->where('tenant_id', $tenant->id)->exists(),
                'owner@pharmabio.tn must exist for this tenant'
            );
            $this->assertFalse(
                User::where('email', 'owner@pharmabio.fr')->where('tenant_id', $tenant->id)->exists(),
                'owner@pharmabio.fr must NOT exist for this tenant'
            );
        });
    }

    public function test_seeds_active_unclaimed_terminals_and_scoped_cashiers(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $terminals = \App\Modules\POS\Domain\Terminal::all();
            $this->assertCount(4, $terminals);
            foreach ($terminals as $t) {
                $this->assertTrue($t->is_active);
                $this->assertNull($t->hardware_identifier); // unclaimed → POS-claimable
                $this->assertNotNull($t->genesis_seed);
                $this->assertSame('POS01', $t->code);
            }

            $tun1 = \App\Modules\Company\Domain\Location::where('code', 'STORE-TUN1')->firstOrFail();
            $cashier = User::where('email', 'tunis1.cashier@pharmabio.tn')->firstOrFail();
            $membership = \App\Modules\Company\Domain\UserCompanyMembership::where('user_id', $cashier->id)->firstOrFail();
            $this->assertContains($tun1->id, $membership->allowed_location_ids);
        });
    }
}
