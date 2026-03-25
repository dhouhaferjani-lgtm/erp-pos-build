<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test tax recoverability impact on product cost calculations
 *
 * Scenarios:
 * 1. VAT Registered Company (Assujetti) - TVA is recoverable
 * 2. Non-VAT Registered Company - TVA is non-recoverable (adds to cost)
 * 3. Stamp duties are ALWAYS non-recoverable for both
 */
class TaxRecoverabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $vatRegisteredCompany;

    private Company $nonVatRegisteredCompany;

    private Location $vatLocation;

    private Location $nonVatLocation;

    private Partner $supplier;

    private Product $product1;

    private Product $product2;

    private TaxConfiguration $vat19;

    private TaxConfiguration $stampDuty;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);

        // Create tenant
        $this->tenant = Tenant::factory()->create();

        // Create user
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Create VAT Registered Company (Tunisia)
        $this->vatRegisteredCompany = Company::factory()->for($this->tenant)->create([
            'name' => 'Garage Assujetti SARL',
            'country_code' => 'TN',
            'currency' => 'TND',
            'tax_status' => CompanyTaxStatus::REGISTERED,
            'vat_number' => 'TN1234567M',
            'default_tax_rate' => '19.00',
        ]);

        // Create Non-VAT Registered Company (Tunisia)
        $this->nonVatRegisteredCompany = Company::factory()->for($this->tenant)->create([
            'name' => 'Garage Non-Assujetti',
            'country_code' => 'TN',
            'currency' => 'TND',
            'tax_status' => CompanyTaxStatus::NON_REGISTERED,
            'vat_number' => null,
            'default_tax_rate' => '0.00',
        ]);

        // Create locations for each company
        $this->vatLocation = Location::factory()->for($this->vatRegisteredCompany)->create([
            'name' => 'Main Warehouse',
            'code' => 'WH-VAT',
            'is_default' => true,
        ]);

        $this->nonVatLocation = Location::factory()->for($this->nonVatRegisteredCompany)->create([
            'name' => 'Main Warehouse',
            'code' => 'WH-NONVAT',
            'is_default' => true,
        ]);

        // Create supplier
        $this->supplier = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->vatRegisteredCompany->id,
            'type' => PartnerType::Supplier,
            'name' => 'Auto Parts Supplier TN',
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        // Create products
        $this->product1 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->vatRegisteredCompany->id,
            'name' => 'Brake Pads Set',
            'sku' => 'BRAKE-PAD-001',
            'purchase_price' => '50.000',
            'sale_price' => '100.000',
            'tax_rate' => '19.00',
        ]);

        $this->product2 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->nonVatRegisteredCompany->id,
            'name' => 'Oil Filter',
            'sku' => 'OIL-FILTER-001',
            'purchase_price' => '10.000',
            'sale_price' => '25.000',
            'tax_rate' => '19.00',
        ]);

        // Create tax configurations (Tunisia)
        $this->vat19 = TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'TVA 19%',
            'code' => 'TVA_19',
            'tax_type' => 'PERCENTAGE',
            'percentage_rate' => '19.00',
            'fixed_amount' => null,
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => ['PURCHASE_INVOICE'],
            'is_default' => true,
            'is_active' => true,
            'is_stamp_duty' => false,
            'is_recoverable' => true, // VAT is recoverable for registered companies
        ]);

        $this->stampDuty = TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'Timbre Fiscal - Facture',
            'code' => 'STAMP_INVOICE',
            'tax_type' => 'FIXED_AMOUNT',
            'percentage_rate' => null,
            'fixed_amount' => '1.000',
            'applies_to' => 'DOCUMENT_TOTAL',
            'sequence_order' => 99,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => ['PURCHASE_INVOICE'],
            'is_default' => false,
            'is_active' => true,
            'is_stamp_duty' => true,
            'is_recoverable' => false, // Stamp duties are NEVER recoverable
        ]);

        $this->actingAs($this->user);
    }

    /**
     * Test Scenario 1: VAT Registered Company
     *
     * Purchase: 10 units @ 50.000 TND each = 500.000 TND
     * TVA 19%: 95.000 TND (RECOVERABLE - does NOT add to cost)
     * Stamp Duty: 1.000 TND (NON-RECOVERABLE - ADDS to cost)
     *
     * Expected:
     * - Subtotal: 500.000 TND
     * - TVA: 95.000 TND
     * - Stamp: 1.000 TND
     * - Total Invoice: 596.000 TND
     * - Product Cost per unit: 50.100 TND (500 + 1 stamp / 10 units)
     * - Total Stock Value: 501.000 TND (cost includes stamp only)
     */
    public function test_vat_registered_company_recovers_vat_but_not_stamp_duty(): void
    {
        $this->markTestSkipped('Pending purchase invoice implementation');

        // TODO: Create purchase invoice via API
        // $response = $this->postJson('/api/v1/purchase/invoices', [
        //     'company_id' => $this->vatRegisteredCompany->id,
        //     'supplier_id' => $this->supplier->id,
        //     'date' => now()->toDateString(),
        //     'lines' => [
        //         [
        //             'product_id' => $this->product1->id,
        //             'quantity' => 10,
        //             'unit_price' => '50.000',
        //             'tax_rate' => '19.00',
        //         ],
        //     ],
        // ]);

        // $response->assertStatus(201);
        // $invoiceId = $response->json('data.id');

        // // Post the invoice
        // $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post")
        //     ->assertStatus(200);

        // // Verify calculations
        // $invoice = Document::find($invoiceId);
        // $this->assertEquals('500.000', $invoice->subtotal);
        // $this->assertEquals('95.000', $invoice->tax_amount); // VAT
        // $this->assertEquals('1.000', $invoice->stamp_duty_amount);
        // $this->assertEquals('596.000', $invoice->total);

        // // Verify stock level and cost
        // $stockLevel = StockLevel::where('product_id', $this->product1->id)
        //     ->where('location_id', $this->vatLocation->id)
        //     ->first();

        // $this->assertEquals(10, $stockLevel->quantity);
        // // Cost = (500.000 subtotal + 1.000 stamp) / 10 units = 50.100 per unit
        // $this->assertEquals('50.100', $stockLevel->unit_cost);
        // $this->assertEquals('501.000', $stockLevel->total_value);
    }

    /**
     * Test Scenario 2: Non-VAT Registered Company
     *
     * Purchase: 20 units @ 10.000 TND each = 200.000 TND
     * TVA 19%: 38.000 TND (NON-RECOVERABLE - ADDS to cost)
     * Stamp Duty: 1.000 TND (NON-RECOVERABLE - ADDS to cost)
     *
     * Expected:
     * - Subtotal: 200.000 TND
     * - TVA: 38.000 TND
     * - Stamp: 1.000 TND
     * - Total Invoice: 239.000 TND
     * - Product Cost per unit: 11.950 TND (200 + 38 VAT + 1 stamp / 20 units)
     * - Total Stock Value: 239.000 TND (cost includes VAT + stamp)
     */
    public function test_non_vat_registered_company_absorbs_all_taxes_into_cost(): void
    {
        $this->markTestSkipped('Pending purchase invoice implementation');

        // TODO: Create purchase invoice via API
        // $response = $this->postJson('/api/v1/purchase/invoices', [
        //     'company_id' => $this->nonVatRegisteredCompany->id,
        //     'supplier_id' => $this->supplier->id,
        //     'date' => now()->toDateString(),
        //     'lines' => [
        //         [
        //             'product_id' => $this->product2->id,
        //             'quantity' => 20,
        //             'unit_price' => '10.000',
        //             'tax_rate' => '19.00', // Will be non-recoverable
        //         ],
        //     ],
        // ]);

        // $response->assertStatus(201);
        // $invoiceId = $response->json('data.id');

        // // Post the invoice
        // $this->postJson("/api/v1/purchase/invoices/{$invoiceId}/post")
        //     ->assertStatus(200);

        // // Verify calculations
        // $invoice = Document::find($invoiceId);
        // $this->assertEquals('200.000', $invoice->subtotal);
        // $this->assertEquals('38.000', $invoice->tax_amount); // VAT
        // $this->assertEquals('1.000', $invoice->stamp_duty_amount);
        // $this->assertEquals('239.000', $invoice->total);

        // // Verify stock level and cost
        // $stockLevel = StockLevel::where('product_id', $this->product2->id)
        //     ->where('location_id', $this->nonVatLocation->id)
        //     ->first();

        // $this->assertEquals(20, $stockLevel->quantity);
        // // Cost = (200.000 subtotal + 38.000 VAT + 1.000 stamp) / 20 units = 11.950 per unit
        // $this->assertEquals('11.950', $stockLevel->unit_cost);
        // $this->assertEquals('239.000', $stockLevel->total_value);
    }

    /**
     * Test Scenario 3: Average Weighted Cost - VAT Registered
     *
     * Purchase 1: 10 units @ 50.000 + 1.000 stamp = 501.000 total (50.100/unit)
     * Purchase 2: 5 units @ 60.000 + 1.000 stamp = 301.000 total (60.200/unit)
     *
     * Expected Average Cost:
     * (501.000 + 301.000) / (10 + 5) = 802.000 / 15 = 53.467 TND per unit
     */
    public function test_average_weighted_cost_vat_registered(): void
    {
        $this->markTestSkipped('Pending purchase invoice implementation');

        // TODO: Create first purchase
        // ... (10 units @ 50.000)

        // TODO: Create second purchase
        // ... (5 units @ 60.000)

        // // Verify average cost
        // $stockLevel = StockLevel::where('product_id', $this->product1->id)
        //     ->where('location_id', $this->vatLocation->id)
        //     ->first();

        // $this->assertEquals(15, $stockLevel->quantity);
        // $this->assertEquals('53.467', $stockLevel->unit_cost);
        // $this->assertEquals('802.000', $stockLevel->total_value);
    }

    /**
     * Test Scenario 4: Average Weighted Cost - Non-VAT Registered
     *
     * Purchase 1: 20 units @ 10.000 + 38.000 VAT + 1.000 stamp = 239.000 (11.950/unit)
     * Purchase 2: 10 units @ 12.000 + 45.600 VAT + 1.000 stamp = 286.600 (14.330/unit)
     *
     * Expected Average Cost:
     * (239.000 + 286.600) / (20 + 10) = 525.600 / 30 = 17.520 TND per unit
     */
    public function test_average_weighted_cost_non_vat_registered(): void
    {
        $this->markTestSkipped('Pending purchase invoice implementation');

        // TODO: Create first purchase
        // ... (20 units @ 10.000)

        // TODO: Create second purchase
        // ... (10 units @ 12.000)

        // // Verify average cost
        // $stockLevel = StockLevel::where('product_id', $this->product2->id)
        //     ->where('location_id', $this->nonVatLocation->id)
        //     ->first();

        // $this->assertEquals(30, $stockLevel->quantity);
        // $this->assertEquals('17.520', $stockLevel->unit_cost);
        // $this->assertEquals('525.600', $stockLevel->total_value);
    }

    /**
     * Test Scenario 5: Verify tax configuration impact
     *
     * If we change VAT from recoverable to non-recoverable for a registered company,
     * the cost should increase
     */
    public function test_changing_vat_recoverability_affects_cost(): void
    {
        $this->markTestSkipped('Pending purchase invoice implementation');

        // Change VAT to non-recoverable
        $this->vat19->update(['is_recoverable' => false]);

        // Create purchase invoice
        // Expected: VAT now ADDS to cost even for registered company

        // Verify cost includes VAT
        // Product Cost = (500.000 subtotal + 95.000 VAT + 1.000 stamp) / 10 = 59.600 per unit
    }
}
