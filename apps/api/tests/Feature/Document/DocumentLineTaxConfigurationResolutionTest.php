<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DocumentLineTaxConfigurationResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        (new CountriesSeeder)->run();

        $this->tenant = Tenant::create([
            'name' => 'Tax Resolution Tenant',
            'slug' => 'tax-resolution-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'country_code' => 'TN',
            'currency_code' => 'TND',
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tax Resolution Company',
            'legal_name' => 'Tax Resolution Company SARL',
            'country_code' => 'TN',
            'locale' => 'fr_FR',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tax Resolution User',
            'email' => 'tax-resolution@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['invoices.view', 'invoices.create']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Tax Resolution Customer',
            'type' => PartnerType::Customer,
            'email' => 'tax-customer@example.com',
        ]);
    }

    public function test_invoice_lines_resolve_tax_rate_from_explicit_rate_line_config_product_default_then_company_default(): void
    {
        $lineConfig = $this->taxConfiguration('LINE_7', '7.00');
        $productConfig = $this->taxConfiguration('PRODUCT_19', '19.00');
        $companyConfig = $this->taxConfiguration('COMPANY_5', '5.00');

        $this->company->update([
            'default_tax_configuration_id' => $companyConfig->id,
            'default_tax_rate' => '5.00',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Configured Product',
            'default_tax_configuration_id' => $productConfig->id,
            'tax_rate' => null,
            'sale_price' => '100.000',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'partner_id' => $this->partner->id,
            'document_date' => '2026-07-01',
            'lines' => [
                [
                    'description' => 'Explicit tax rate',
                    'quantity' => '1.0000',
                    'unit_price' => '100.000',
                    'tax_rate' => '13.00',
                ],
                [
                    'description' => 'Line tax configuration',
                    'quantity' => '1.0000',
                    'unit_price' => '100.000',
                    'tax_configuration_id' => $lineConfig->id,
                ],
                [
                    'description' => 'Product default tax configuration',
                    'product_id' => $product->id,
                    'quantity' => '1.0000',
                    'unit_price' => '100.000',
                ],
                [
                    'description' => 'Company default tax configuration',
                    'quantity' => '1.0000',
                    'unit_price' => '100.000',
                ],
            ],
        ]);

        $response->assertCreated();

        $documentId = (string) $response->json('data.id');
        $rates = DocumentLine::query()
            ->where('document_id', $documentId)
            ->orderBy('line_number')
            ->pluck('tax_rate')
            ->map(fn (mixed $rate): string => (string) $rate)
            ->all();

        $this->assertSame(['13.00', '7.00', '19.00', '5.00'], $rates);
        $response->assertJsonPath('data.tax_amount', '44.000');
        $response->assertJsonPath('data.total', '444.000');
    }

    private function taxConfiguration(string $code, string $rate): TaxConfiguration
    {
        return TaxConfiguration::create([
            'country_code' => 'TN',
            'tax_type' => 'PERCENTAGE',
            'name' => $code,
            'code' => $code,
            'percentage_rate' => $rate,
            'fixed_amount' => null,
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => ['TAX_INVOICE'],
            'is_default' => false,
            'is_active' => true,
            'is_stamp_duty' => false,
            'is_recoverable' => true,
        ]);
    }
}
