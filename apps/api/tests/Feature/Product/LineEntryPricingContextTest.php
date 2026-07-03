<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class LineEntryPricingContextTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Pricing Context Tenant',
            'slug' => 'pricing-context-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pricing Context Company',
            'legal_name' => 'Pricing Context Company LLC',
            'tax_id' => 'PRCTX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
            'default_target_margin' => '30.00',
            'default_minimum_margin' => '15.00',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::firstOrCreate(['name' => 'products.view', 'guard_name' => 'sanctum']);
        $role = Role::firstOrCreate(['name' => 'Line Entry Viewer', 'guard_name' => 'sanctum']);
        $role->syncPermissions(['products.view']);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pricing Context User',
            'email' => 'pricing-context@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole($role);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        $this->partner = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_bulk_pricing_context_returns_cost_history_partner_sale_and_policy(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'SPF 50',
            'sku' => 'SPF-50',
            'cost_price' => '12.500000',
            'last_purchase_cost' => '11.900000',
            'sale_price' => '18.000',
            'target_margin_override' => '30.00',
            'minimum_margin_override' => '15.00',
            'is_active' => true,
        ]);

        $olderDocument = $this->createDocument('INV-OLD', now()->subMonth());
        DocumentLine::create([
            'document_id' => $olderDocument->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => 'Old SPF 50',
            'quantity' => '1.0000',
            'unit_price' => '17.000',
            'tax_rate' => '0.00',
            'line_total' => '17.000',
        ]);

        $latestDocument = $this->createDocument('INV-LATEST', now()->subDay());
        DocumentLine::create([
            'document_id' => $latestDocument->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => 'Latest SPF 50',
            'quantity' => '1.0000',
            'unit_price' => '18.000',
            'tax_rate' => '0.00',
            'line_total' => '18.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/line-entry/pricing-context/bulk', [
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'variant_id' => null,
                        'unit_price' => '13.000',
                    ],
                ],
            ]);

        $key = $product->id;
        $response->assertOk()
            ->assertJsonPath("data.items.{$key}.currency", 'TND')
            ->assertJsonPath("data.items.{$key}.cost_wac", '12.500000')
            ->assertJsonPath("data.items.{$key}.last_purchase_cost", '11.900000')
            ->assertJsonPath("data.items.{$key}.last_sale_to_partner.unit_price", '18.000')
            ->assertJsonPath("data.items.{$key}.last_sale_to_partner.document_no", 'INV-LATEST')
            ->assertJsonPath("data.items.{$key}.suggested_price", '16.250')
            ->assertJsonPath("data.items.{$key}.target_margin_pct", '30.00')
            ->assertJsonPath("data.items.{$key}.minimum_margin_pct", '15.00')
            ->assertJsonPath("data.items.{$key}.policy.level", MarginService::LEVEL_ORANGE)
            ->assertJsonPath("data.items.{$key}.policy.allowed", false)
            ->assertJsonPath("data.items.{$key}.policy.requires_permission", 'pricing.sell_below_minimum_margin');
    }

    public function test_bulk_pricing_context_rejects_foreign_company_products(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'OTHER123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        $foreignProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/line-entry/pricing-context/bulk', [
                'lines' => [
                    [
                        'product_id' => $foreignProduct->id,
                        'unit_price' => '10.000',
                    ],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame(
            ['The selected lines.0.product_id is invalid.'],
            $response->json('error.errors')['lines.0.product_id'] ?? null,
        );
    }

    private function createDocument(string $number, \DateTimeInterface $date): Document
    {
        return Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Posted,
            'document_number' => $number,
            'document_date' => $date,
            'currency' => 'TND',
        ]);
    }
}
