<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FulfillmentStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests that document fulfillment status uses is_physical
 * (not product type) to determine whether delivery is required.
 */
class DocumentFulfillmentIsPhysicalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => \App\Modules\Company\Domain\Enums\MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
        ]);
    }

    private function createInvoiceWithProduct(Product $product): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'partner_id' => $this->customer->id,
            'document_number' => 'INV-' . uniqid(),
            'document_date' => now(),
            'total' => '100.00',
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'line_total' => '100.00',
        ]);

        return $document;
    }

    public function test_invoice_with_physical_product_requires_fulfillment(): void
    {
        $physicalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Physical Product',
            'sku' => 'PHY-001',
            'is_physical' => true,
            'sale_price' => '100.00',
        ]);

        $document = $this->createInvoiceWithProduct($physicalProduct);

        $this->assertEquals(FulfillmentStatus::NotFulfilled, $document->getFulfillmentStatus());
    }

    public function test_invoice_with_non_physical_product_does_not_require_fulfillment(): void
    {
        $nonPhysicalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Digital Service',
            'sku' => 'DIG-001',
            'is_physical' => false,
            'sale_price' => '50.00',
        ]);

        $document = $this->createInvoiceWithProduct($nonPhysicalProduct);

        $this->assertEquals(FulfillmentStatus::NotApplicable, $document->getFulfillmentStatus());
    }

    public function test_invoice_with_null_type_physical_product_requires_fulfillment(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Generic Product',
            'sku' => 'GEN-001',
            'is_physical' => true,
            'sale_price' => '75.00',
        ]);

        // Confirm type is null (the new default)
        $this->assertNull($product->type);

        $document = $this->createInvoiceWithProduct($product);

        // A null-type but is_physical=true product should still require delivery
        $this->assertEquals(FulfillmentStatus::NotFulfilled, $document->getFulfillmentStatus());
    }
}
