<?php

declare(strict_types=1);

namespace Tests\Feature\ModelUpdates;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 0.3: Tests for model company relationships.
 *
 * All business models should have:
 * - company_id in fillable array
 * - company() relationship method
 * - forCompany() scope (optional but recommended)
 */
class CompanyRelationshipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

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
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);
    }

    /**
     * Test Partner has company relationship.
     */
    public function test_partner_has_company_relationship(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
        ]);

        $this->assertNotNull($partner->company);
        $this->assertEquals($this->company->id, $partner->company->id);
    }

    /**
     * Test Product has company relationship.
     */
    public function test_product_has_company_relationship(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'type' => ProductType::Part,
        ]);

        $this->assertNotNull($product->company);
        $this->assertEquals($this->company->id, $product->company->id);
    }

    /**
     * Test Vehicle has company relationship.
     */
    public function test_vehicle_has_company_relationship(): void
    {
        $vehicle = Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'license_plate' => 'ABC-123',
            'brand' => 'Toyota',
            'model' => 'Camry',
            'fuel_type' => 'gasoline',
        ]);

        $this->assertNotNull($vehicle->company);
        $this->assertEquals($this->company->id, $vehicle->company->id);
    }

    /**
     * Test Document has company relationship.
     */
    public function test_document_has_company_relationship(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
        ]);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'partner_id' => $partner->id,
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'currency' => 'TND',
            'subtotal' => 100,
            'tax_amount' => 19,
            'total' => 119,
        ]);

        $this->assertNotNull($document->company);
        $this->assertEquals($this->company->id, $document->company->id);
    }

    /**
     * Test Document has location relationship.
     */
    public function test_document_has_location_relationship(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
        ]);

        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Shop',
            'type' => 'shop',
            'is_default' => true,
            'is_active' => true,
        ]);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-002',
            'partner_id' => $partner->id,
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'currency' => 'TND',
            'subtotal' => 100,
            'tax_amount' => 19,
            'total' => 119,
        ]);

        $this->assertNotNull($document->location);
        $this->assertEquals($location->id, $document->location->id);
    }

    /**
     * Test Account has company relationship.
     */
    public function test_account_has_company_relationship(): void
    {
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1000',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $this->assertNotNull($account->company);
        $this->assertEquals($this->company->id, $account->company->id);
    }

    /**
     * Test JournalEntry has company relationship.
     */
    public function test_journal_entry_has_company_relationship(): void
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_date' => now(),
            'entry_number' => 'JE-001',
            'description' => 'Test entry',
        ]);

        $this->assertNotNull($entry->company);
        $this->assertEquals($this->company->id, $entry->company->id);
    }

    /**
     * Test Partner has forCompany scope.
     */
    public function test_partner_has_for_company_scope(): void
    {
        $company2 = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Second Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Partner 1',
            'type' => PartnerType::Customer,
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
            'name' => 'Partner 2',
            'type' => PartnerType::Customer,
        ]);

        $company1Partners = Partner::forCompany($this->company->id)->get();
        $company2Partners = Partner::forCompany($company2->id)->get();

        $this->assertCount(1, $company1Partners);
        $this->assertCount(1, $company2Partners);
        $this->assertEquals('Partner 1', $company1Partners->first()->name);
        $this->assertEquals('Partner 2', $company2Partners->first()->name);
    }
}
