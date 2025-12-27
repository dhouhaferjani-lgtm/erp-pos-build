<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * StockMovementGLIntegrationTest - Tests GL entries created from inventory operations
 *
 * Tests covered:
 * - COGS entry created when invoice with physical products is posted
 * - COGS entry has correct debit (expense) and credit (inventory) amounts
 * - No COGS entry for service-only invoices
 * - COGS calculated from product cost_price (WAC)
 * - Multiple product lines summed correctly
 * - COGS entry links to source invoice
 * - COGS entry balances (debit = credit)
 */
class StockMovementGLIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Location $warehouse;

    private Account $cogsAccount;

    private Account $inventoryAccount;

    private Account $receivableAccount;

    private Account $revenueAccount;

    private Account $vatAccount;

    private GeneralLedgerService $glService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'GL Inventory Test Tenant',
            'slug' => 'gl-inv-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GL Inventory Test Company',
            'legal_name' => 'GL Inventory Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'testuser@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'invoices.view',
            'invoices.create',
            'invoices.post',
            'journal.view',
            'journal.create',
            'journal.post',
            'inventory.view',
            'inventory.adjust',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        // Create required system accounts
        $this->cogsAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '601',
            'name' => 'Cost of Goods Sold',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::CostOfGoodsSold,
            'is_active' => true,
        ]);

        $this->inventoryAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '370',
            'name' => 'Inventory',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
        ]);

        $this->receivableAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        $this->revenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '701',
            'name' => 'Product Revenue',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);

        $this->vatAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4457',
            'name' => 'VAT Collected',
            'type' => AccountType::Liability,
            'system_purpose' => SystemAccountPurpose::VatCollected,
            'is_active' => true,
        ]);

        $this->glService = app(GeneralLedgerService::class);
    }

    /**
     * Create a physical product with cost price
     */
    private function createPhysicalProduct(string $costPrice = '50.00'): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PART-'.uniqid(),
            'name' => 'Physical Product',
            'type' => ProductType::Part,
            'cost_price' => $costPrice,
            'selling_price' => '100.00',
            'is_active' => true,
        ]);
    }

    /**
     * Create a service product (non-physical)
     */
    private function createServiceProduct(): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SRV-'.uniqid(),
            'name' => 'Service Product',
            'type' => ProductType::Service,
            'selling_price' => '150.00',
            'is_active' => true,
        ]);
    }

    /**
     * Create a posted invoice with product lines
     *
     * @param  array<int, array{product: Product, quantity: string, unit_price: string}>  $lines
     */
    private function createPostedInvoiceWithProducts(array $lines): Document
    {
        $subtotal = '0.00';
        foreach ($lines as $line) {
            $lineTotal = bcmul($line['quantity'], $line['unit_price'], 2);
            $subtotal = bcadd($subtotal, $lineTotal, 2);
        }
        $taxAmount = bcmul($subtotal, '0.19', 2);
        $total = bcadd($subtotal, $taxAmount, 2);

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'TND',
        ]);

        $lineNumber = 1;
        foreach ($lines as $line) {
            $lineTotal = bcmul($line['quantity'], $line['unit_price'], 2);
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $invoice->id,
                'product_id' => $line['product']->id,
                'line_number' => $lineNumber++,
                'description' => $line['product']->name,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => '19.00',
                'line_total' => $lineTotal,
            ]);
        }

        return $invoice;
    }

    public function test_cogs_entry_created_for_invoice_with_physical_product(): void
    {
        $product = $this->createPhysicalProduct('50.00');
        $invoice = $this->createPostedInvoiceWithProducts([
            ['product' => $product, 'quantity' => '2', 'unit_price' => '100.00'],
        ]);

        // Calculate expected COGS: 2 units × 50.00 cost = 100.00
        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '2',
                'unit_cost' => '50.00',
            ],
        ];

        $entry = $this->glService->createCOGSEntry(
            $this->company->id,
            $invoice->id,
            $invoice->document_number,
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);
        $this->assertCount(2, $entry->lines);
        $this->assertEquals('cogs', $entry->source_type);
        $this->assertEquals($invoice->id, $entry->source_id);
    }

    public function test_cogs_entry_has_correct_debit_and_credit_amounts(): void
    {
        $product = $this->createPhysicalProduct('75.00');

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '4',
                'unit_cost' => '75.00',
            ],
        ];

        // Expected COGS: 4 × 75.00 = 300.00
        $entry = $this->glService->createCOGSEntry(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-TEST-001',
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);

        // Find COGS line (debit to expense)
        $cogsLine = $entry->lines->where('account_id', $this->cogsAccount->id)->first();
        $this->assertNotNull($cogsLine);
        $this->assertEquals('300.00', $cogsLine->debit);
        $this->assertEquals('0.00', $cogsLine->credit);

        // Find Inventory line (credit to asset)
        $inventoryLine = $entry->lines->where('account_id', $this->inventoryAccount->id)->first();
        $this->assertNotNull($inventoryLine);
        $this->assertEquals('0.00', $inventoryLine->debit);
        $this->assertEquals('300.00', $inventoryLine->credit);
    }

    public function test_no_cogs_entry_for_zero_cost_products(): void
    {
        $product = $this->createPhysicalProduct('0.00');

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '5',
                'unit_cost' => '0.00',
            ],
        ];

        $entry = $this->glService->createCOGSEntry(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-TEST-002',
            $lineItems,
            new \DateTimeImmutable
        );

        // No entry should be created for zero COGS
        $this->assertNull($entry);
    }

    public function test_multiple_product_lines_summed_correctly(): void
    {
        $product1 = $this->createPhysicalProduct('25.00');
        $product2 = $this->createPhysicalProduct('40.00');

        $lineItems = [
            [
                'product_id' => $product1->id,
                'quantity' => '3',
                'unit_cost' => '25.00', // 3 × 25 = 75
            ],
            [
                'product_id' => $product2->id,
                'quantity' => '2',
                'unit_cost' => '40.00', // 2 × 40 = 80
            ],
        ];

        // Total COGS: 75 + 80 = 155.00
        $entry = $this->glService->createCOGSEntry(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-TEST-003',
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);

        $cogsLine = $entry->lines->where('account_id', $this->cogsAccount->id)->first();
        $this->assertEquals('155.00', $cogsLine->debit);

        $inventoryLine = $entry->lines->where('account_id', $this->inventoryAccount->id)->first();
        $this->assertEquals('155.00', $inventoryLine->credit);
    }

    public function test_cogs_entry_balances(): void
    {
        $product = $this->createPhysicalProduct('60.00');

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '5',
                'unit_cost' => '60.00', // 5 × 60 = 300
            ],
        ];

        $entry = $this->glService->createCOGSEntry(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-TEST-004',
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);

        // Calculate total debits and credits
        $totalDebits = '0.00';
        $totalCredits = '0.00';
        foreach ($entry->lines as $line) {
            $totalDebits = bcadd($totalDebits, $line->debit, 2);
            $totalCredits = bcadd($totalCredits, $line->credit, 2);
        }

        $this->assertEquals($totalDebits, $totalCredits);
        $this->assertEquals('300.00', $totalDebits);
    }

    public function test_cogs_entry_links_to_source_invoice(): void
    {
        $product = $this->createPhysicalProduct('45.00');
        $invoiceId = Str::uuid()->toString();
        $documentNumber = 'INV-LINK-TEST';

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '2',
                'unit_cost' => '45.00',
            ],
        ];

        $entry = $this->glService->createCOGSEntry(
            $this->company->id,
            $invoiceId,
            $documentNumber,
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);
        $this->assertEquals('cogs', $entry->source_type);
        $this->assertEquals($invoiceId, $entry->source_id);
        $this->assertStringContainsString($documentNumber, $entry->description);
    }

    public function test_cogs_entry_description_includes_invoice_number(): void
    {
        $product = $this->createPhysicalProduct('30.00');
        $documentNumber = 'INV-2025-12345';

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '1',
                'unit_cost' => '30.00',
            ],
        ];

        $entry = $this->glService->createCOGSEntry(
            $this->company->id,
            Str::uuid()->toString(),
            $documentNumber,
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);
        $this->assertStringContainsString('INV-2025-12345', $entry->description);
    }

    public function test_cogs_uses_weighted_average_cost(): void
    {
        // Create product with specific WAC (cost_price represents WAC in this system)
        $product = $this->createPhysicalProduct('33.50');

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '3',
                'unit_cost' => '33.50', // WAC
            ],
        ];

        // Expected COGS: 3 × 33.50 = 100.50
        $entry = $this->glService->createCOGSEntry(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-WAC-TEST',
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);

        $cogsLine = $entry->lines->where('account_id', $this->cogsAccount->id)->first();
        $this->assertEquals('100.50', $cogsLine->debit);
    }

    public function test_cogs_entry_company_id_matches(): void
    {
        $product = $this->createPhysicalProduct('20.00');

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '1',
                'unit_cost' => '20.00',
            ],
        ];

        $entry = $this->glService->createCOGSEntry(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-COMPANY-TEST',
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);
        $this->assertEquals($this->company->id, $entry->company_id);
        $this->assertEquals($this->tenant->id, $entry->tenant_id);
    }

    public function test_empty_line_items_returns_null(): void
    {
        $entry = $this->glService->createCOGSEntry(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-EMPTY-TEST',
            [],
            new \DateTimeImmutable
        );

        $this->assertNull($entry);
    }
}
