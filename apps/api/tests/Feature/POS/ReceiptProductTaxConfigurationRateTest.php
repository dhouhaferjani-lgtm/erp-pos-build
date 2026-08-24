<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Campaign defect N-1 (P0), POS arm — the arm where the wrong number is SEALED.
 *
 * `ReceiptCreationService` reads `$product->tax_rate` unconditionally and
 * `StoreReceiptRequest` has no `lines.*.tax_rate` rule, so a till cannot
 * override it: whatever `products.tax_rate` holds is what lands in
 * `pos_receipt_lines` and `pos_receipt_vat_details` and is then hash-chained.
 * There is no client-side correction available, which is why the repair has to
 * be at the product write path.
 *
 * This test drives the REAL create endpoint (not a factory) so it proves the
 * whole chain: operator picks TVA 7 % -> `products.tax_rate` -> receipt line ->
 * NF525 VAT breakdown.
 */
final class ReceiptProductTaxConfigurationRateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        (new CountriesSeeder)->run();

        $this->tenant = Tenant::create([
            'name' => 'N1 POS VAT Tenant',
            'slug' => 'n1-pos-vat-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
            'default_tax_rate' => '19.00',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'N1 POS User',
            'email' => 'n1-pos-vat@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->seed(TunisiaTaxConfigurationSeeder::class);

        $location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.0000',
        ]);
    }

    public function test_a_seven_percent_product_is_sold_and_sealed_at_seven_percent(): void
    {
        $sevenPercent = TaxConfiguration::query()
            ->where('country_code', 'TN')
            ->where('code', 'TVA_7')
            ->firstOrFail();

        // Created exactly the way the operator creates it — through the API.
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Sérum physiologique 5ml x20',
                'sku' => 'N1-POS-SERU',
                'sale_price' => '6.200',
                'default_tax_configuration_id' => $sevenPercent->id,
            ])
            ->assertCreated();

        $product = Product::query()->where('sku', 'N1-POS-SERU')->firstOrFail();

        /** @var ReceiptCreationService $service */
        $service = app(ReceiptCreationService::class);

        $service->createReceipt(
            terminalId: $this->terminal->id,
            lines: [
                [
                    'product_id' => $product->id,
                    'quantity' => '1',
                    'unit_price' => '6.634',
                ],
            ],
        );

        /** @var Receipt $receipt */
        $receipt = Receipt::query()->latest('created_at')->firstOrFail();

        $lineRates = $receipt->lines()
            ->pluck('tax_rate')
            ->map(static fn (mixed $rate): string => (string) $rate)
            ->all();

        $this->assertSame(
            ['7.00'],
            $lineRates,
            'pos_receipt_lines.tax_rate is hash-chained — it must carry the product\'s real 7 % band.',
        );

        $vatRates = $receipt->vatDetails()
            ->pluck('tax_rate')
            ->map(static fn (mixed $rate): string => (string) $rate)
            ->all();

        $this->assertSame(
            ['7.00'],
            $vatRates,
            'The NF525 VAT breakdown must declare the 7 % band, not the 19 % company default.',
        );
    }
}
