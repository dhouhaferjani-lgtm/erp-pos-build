<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Domain\Terminal;
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

    public function test_catalog_has_a_barcode_mix(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $withBarcode = \App\Modules\Product\Domain\Product::whereNotNull('barcode')->count();
            $withoutBarcode = \App\Modules\Product\Domain\Product::whereNull('barcode')->count();
            $this->assertGreaterThan(0, $withBarcode, 'need scan-resolves demo set');
            $this->assertGreaterThan(0, $withoutBarcode, 'need no-barcode demo set');
            $sample = \App\Modules\Product\Domain\Product::whereNotNull('barcode')->first();
            $this->assertStringStartsWith('619', $sample->barcode); // Tunisia GS1
        });
    }

    public function test_seeds_active_unclaimed_terminals_and_scoped_cashiers(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            // Finding 2: scope terminal query by code for robustness
            $terminals = Terminal::where('code', 'POS01')->get();
            $this->assertCount(4, $terminals);
            foreach ($terminals as $t) {
                $this->assertTrue($t->is_active);
                $this->assertNull($t->hardware_identifier); // unclaimed → POS-claimable
                $this->assertNotNull($t->genesis_seed);
                $this->assertSame('POS01', $t->code);
            }

            // Finding 1: verify ALL 4 cashiers are scoped to EXACTLY their own shop
            $cashierShopPairs = [
                'tunis1.cashier@pharmabio.tn' => 'STORE-TUN1',
                'tunis2.cashier@pharmabio.tn' => 'STORE-TUN2',
                'sousse.cashier@pharmabio.tn' => 'STORE-SOU',
                'sfax.cashier@pharmabio.tn'   => 'STORE-SFA',
            ];

            foreach ($cashierShopPairs as $email => $shopCode) {
                $shop = Location::where('code', $shopCode)->firstOrFail();
                $cashier = User::where('email', $email)->firstOrFail();
                $membership = UserCompanyMembership::where('user_id', $cashier->id)->firstOrFail();

                $this->assertCount(
                    1,
                    $membership->allowed_location_ids,
                    "{$email} must be scoped to exactly 1 location (got ".count($membership->allowed_location_ids).')'
                );
                $this->assertContains(
                    $shop->id,
                    $membership->allowed_location_ids,
                    "{$email} must be scoped to {$shopCode} (id={$shop->id})"
                );
            }
        });
    }

    public function test_distributes_stock_across_locations(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $wh = Location::where('code', 'WH-01')->firstOrFail();
            $tun1 = Location::where('code', 'STORE-TUN1')->firstOrFail();

            $whLevels = StockLevel::where('location_id', $wh->id)->count();
            $shopLevels = StockLevel::where('location_id', $tun1->id)->count();

            $this->assertGreaterThan($shopLevels, $whLevels, 'warehouse holds more SKUs than a shop');
            $this->assertGreaterThan(0, $shopLevels, 'STORE-TUN1 must have at least 1 stock level');

            // All 4 shops must have stock
            foreach (['STORE-TUN1', 'STORE-TUN2', 'STORE-SOU', 'STORE-SFA'] as $code) {
                $shop = Location::where('code', $code)->firstOrFail();
                $count = StockLevel::where('location_id', $shop->id)->count();
                $this->assertGreaterThan(0, $count, "{$code} must have stock levels");
            }
        });
    }

    public function test_seeds_gl_consistent_partner_balances(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $debtor = \App\Modules\Partner\Domain\Partner::where('code', 'CUST-DEBTOR-01')->firstOrFail();
            $this->assertTrue(bccomp($debtor->receivable_balance, '0', 3) === 1, 'CUST-DEBTOR-01 has outstanding receivable > 0');

            // CUST-CREDIT-01 (CustomerAdvance / store credit) is intentionally NOT seeded:
            // PartnerBalanceService stores credit_balance as (debit - credit) on the
            // CustomerAdvance account (negative for a credit advance), but PartnerListPage
            // getNetBalance() computes (receivable - credit_balance) which inverts the sign
            // and renders a store credit as a red debt. Pre-existing production bug — tracked
            // separately. Not included in demo fixtures to avoid confusing demo users.

            // PartnerBalanceService stores payable_balance as (debit - credit) on the
            // SupplierPayable account. A normal payable is a credit entry → balance < 0
            // (negative = we owe them). The PartnerListPage renders this as green.
            $supplier = \App\Modules\Partner\Domain\Partner::where('code', 'SUPP-PAYABLE-01')->firstOrFail();
            $this->assertTrue(bccomp($supplier->payable_balance, '0', 3) === -1, 'SUPP-PAYABLE-01 payable_balance < 0 (we owe them)');

            // GL-consistency: recompute and confirm the cached receivable column still holds
            app(\App\Modules\Accounting\Application\Services\PartnerBalanceService::class)
                ->refreshPartnerBalance($debtor->company_id, $debtor->id);
            $this->assertTrue(bccomp($debtor->fresh()->receivable_balance, '0', 3) === 1);
        });
    }

    public function test_seeds_purchase_order_pipeline(): void
    {
        $this->seed(DemoPharmacySeeder::class);
        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            $byStatus = Document::where('type', 'purchase_order')
                ->get()->groupBy(fn ($d) => $d->status->value);
            $this->assertArrayHasKey('draft', $byStatus->toArray());
            $this->assertArrayHasKey('confirmed', $byStatus->toArray()); // incl. the partially-received one
            $this->assertArrayHasKey('received', $byStatus->toArray());
            // fully-received PO has sum(quantity_received) > 0 on its lines
            $received = Document::where('type', 'purchase_order')
                ->where('status', 'received')->firstOrFail();
            $this->assertGreaterThan(0, (float) $received->lines()->sum('quantity_received'));
        });
    }

    public function test_seeds_are_idempotent_on_double_run(): void
    {
        // Running the seeder twice must not crash on the DEMO-BAL-* unique constraint.
        $this->seed(DemoPharmacySeeder::class);
        $this->seed(DemoPharmacySeeder::class);

        Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
            // Exactly 3 DEMO-BAL-* entries (not 6) confirms the idempotency guard fired.
            $count = \App\Modules\Accounting\Domain\JournalEntry::where('entry_number', 'like', 'DEMO-BAL-%')->count();
            $this->assertSame(3, $count, 'second run must skip — exactly 3 DEMO-BAL entries expected');
        });
    }
}
