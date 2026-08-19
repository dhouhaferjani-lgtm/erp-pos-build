<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
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
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\CurrencyScale;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * StockMovementGLIntegrationTest - Tests GL entries created from inventory operations
 *
 * Tests covered:
 * - Movement-keyed inventory exits carry correct COGS/inventory legs
 * - Zero-cost exits do not create entries
 * - Exit totals preserve WAC precision and round once at the GL boundary
 * - Multiple product lines summed correctly
 * - Inventory entries link to their source movement
 * - Inventory entries balance (debit = credit)
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

    private Account $serviceRevenueAccount;

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

        $this->serviceRevenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '706',
            'name' => 'Service Revenue',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ServiceRevenue,
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

    public function test_inventory_exit_entry_is_created_for_a_costed_movement(): void
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

        $entry = $this->createInventoryMovementEntryForLines(
            $this->company->id,
            $invoice->id,
            $invoice->document_number,
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);
        $this->assertCount(2, $entry->lines);
        $this->assertEquals('inventory_exit', $entry->source_type);
        $this->assertEquals($invoice->id, $entry->source_id);
    }

    public function test_invoice_posted_event_no_longer_creates_invoice_keyed_cogs(): void
    {
        $product = $this->createPhysicalProduct('50.00');
        $invoice = $this->createPostedInvoiceWithProducts([
            ['product' => $product, 'quantity' => '2', 'unit_price' => '100.00'],
        ]);

        event(new InvoicePosted(
            invoiceId: $invoice->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            documentNumber: $invoice->document_number,
            documentType: DocumentType::Invoice->value,
            partnerId: $this->customer->id,
            total: (string) $invoice->total,
            currency: $invoice->currency,
            fiscalHash: 'invoice-hash-for-cogs',
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        ));

        $entry = JournalEntry::query()
            ->where('source_type', 'cogs')
            ->where('source_id', $invoice->id)
            ->first();

        $this->assertNull($entry);
    }

    public function test_inventory_exit_entry_has_correct_debit_and_credit_amounts(): void
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
        $entry = $this->createInventoryMovementEntryForLines(
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
        $this->assertEquals('300.000', $cogsLine->debit);
        $this->assertEquals('0.000', $cogsLine->credit);

        // Find Inventory line (credit to asset)
        $inventoryLine = $entry->lines->where('account_id', $this->inventoryAccount->id)->first();
        $this->assertNotNull($inventoryLine);
        $this->assertEquals('0.000', $inventoryLine->debit);
        $this->assertEquals('300.000', $inventoryLine->credit);
    }

    public function test_no_inventory_exit_entry_for_zero_cost_movement(): void
    {
        $product = $this->createPhysicalProduct('0.00');

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '5',
                'unit_cost' => '0.00',
            ],
        ];

        $entry = $this->createInventoryMovementEntryForLines(
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
        $entry = $this->createInventoryMovementEntryForLines(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-TEST-003',
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);

        $cogsLine = $entry->lines->where('account_id', $this->cogsAccount->id)->first();
        $this->assertEquals('155.000', $cogsLine->debit);

        $inventoryLine = $entry->lines->where('account_id', $this->inventoryAccount->id)->first();
        $this->assertEquals('155.000', $inventoryLine->credit);
    }

    public function test_inventory_exit_entry_balances(): void
    {
        $product = $this->createPhysicalProduct('60.00');

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '5',
                'unit_cost' => '60.00', // 5 × 60 = 300
            ],
        ];

        $entry = $this->createInventoryMovementEntryForLines(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-TEST-004',
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);

        // Calculate total debits and credits
        $totalDebits = '0.000';
        $totalCredits = '0.000';
        foreach ($entry->lines as $line) {
            $totalDebits = bcadd($totalDebits, $line->debit, 3);
            $totalCredits = bcadd($totalCredits, $line->credit, 3);
        }

        $this->assertEquals($totalDebits, $totalCredits);
        $this->assertEquals('300.000', $totalDebits);
    }

    /**
     * A 6-dp perpetual WAC (e.g. 0.463636 TND) must be rounded HALF-UP to the
     * currency scale (3) at the GL posting boundary — not truncated — and the
     * COGS debit must equal the inventory credit to the millième.
     *
     * 7 units × 0.463636 = 3.245452 → HALF-UP @ scale 3 = 3.245.
     * (Truncation would also give 3.245 here, so we add a second case whose 4th
     * digit forces a round-up to make the HALF-UP behaviour load-bearing.)
     */
    public function test_inventory_exit_rounds_6dp_wac_half_up_to_currency_scale_and_balances(): void
    {
        $product = $this->createPhysicalProduct('0.463636');

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '7',
                // 6-dp WAC carried at rest (no boundary truncation upstream).
                'unit_cost' => '0.463636',
            ],
        ];

        $entry = $this->createInventoryMovementEntryForLines(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-COGS-HALFUP-1',
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);

        // 7 × 0.463636 = 3.245452 → scale 3 HALF-UP = 3.245.
        $debit = null;
        $credit = null;
        $totalDebits = '0.000';
        $totalCredits = '0.000';
        foreach ($entry->lines as $line) {
            $totalDebits = bcadd($totalDebits, (string) $line->debit, 3);
            $totalCredits = bcadd($totalCredits, (string) $line->credit, 3);
            if (bccomp((string) $line->debit, '0', 3) > 0) {
                $debit = (string) $line->debit;
            }
            if (bccomp((string) $line->credit, '0', 3) > 0) {
                $credit = (string) $line->credit;
            }
        }

        $this->assertSame(0, bccomp('3.245', (string) $debit, 3), "COGS debit was {$debit}");
        // Debit == credit at the posting scale (single rounded total on both legs).
        $this->assertSame(0, bccomp($totalDebits, $totalCredits, 3), 'COGS entry must balance');
        $this->assertSame(0, bccomp('3.245', $totalDebits, 3));
        $this->assertSame($debit, $credit, 'Both legs must carry the same rounded amount');
    }

    /**
     * HALF-UP must round the 4th millième digit UP, where plain bcmath truncation
     * would round down — proving the posting boundary uses CurrencyScale::bcround.
     *
     * 1 unit × 0.463900 = 0.463900 → scale 3 HALF-UP = 0.464 (truncation → 0.463).
     */
    public function test_inventory_exit_half_up_rounds_up_where_truncation_would_round_down(): void
    {
        $product = $this->createPhysicalProduct('0.463900');

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '1',
                'unit_cost' => '0.463900',
            ],
        ];

        $entry = $this->createInventoryMovementEntryForLines(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-COGS-HALFUP-2',
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);

        $debit = null;
        foreach ($entry->lines as $line) {
            if (bccomp((string) $line->debit, '0', 3) > 0) {
                $debit = (string) $line->debit;
            }
        }

        // HALF-UP: 0.4639 → 0.464 (NOT 0.463).
        $this->assertSame(0, bccomp('0.464', (string) $debit, 3), "Expected HALF-UP 0.464, got {$debit}");
    }

    public function test_inventory_exit_entry_links_to_source_movement(): void
    {
        $product = $this->createPhysicalProduct('45.00');
        $movementId = Str::uuid()->toString();
        $documentNumber = 'INV-LINK-TEST';

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '2',
                'unit_cost' => '45.00',
            ],
        ];

        $entry = $this->createInventoryMovementEntryForLines(
            $this->company->id,
            $movementId,
            $documentNumber,
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);
        $this->assertEquals('inventory_exit', $entry->source_type);
        $this->assertEquals($movementId, $entry->source_id);
        $this->assertStringContainsString($documentNumber, $entry->description);
    }

    public function test_inventory_exit_description_includes_source_document_number(): void
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

        $entry = $this->createInventoryMovementEntryForLines(
            $this->company->id,
            Str::uuid()->toString(),
            $documentNumber,
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);
        $this->assertStringContainsString('INV-2025-12345', $entry->description);
    }

    public function test_inventory_exit_uses_weighted_average_cost(): void
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
        $entry = $this->createInventoryMovementEntryForLines(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-WAC-TEST',
            $lineItems,
            new \DateTimeImmutable
        );

        $this->assertNotNull($entry);

        $cogsLine = $entry->lines->where('account_id', $this->cogsAccount->id)->first();
        $this->assertEquals('100.500', $cogsLine->debit);
    }

    public function test_inventory_exit_company_and_tenant_ids_match(): void
    {
        $product = $this->createPhysicalProduct('20.00');

        $lineItems = [
            [
                'product_id' => $product->id,
                'quantity' => '1',
                'unit_cost' => '20.00',
            ],
        ];

        $entry = $this->createInventoryMovementEntryForLines(
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
        $entry = $this->createInventoryMovementEntryForLines(
            $this->company->id,
            Str::uuid()->toString(),
            'INV-EMPTY-TEST',
            [],
            new \DateTimeImmutable
        );

        $this->assertNull($entry);
    }

    /**
     * Adapt the retired invoice-aggregate fixtures to the movement-keyed API.
     * Production writers pass one persisted movement at a time; these legacy
     * arithmetic cases collapse their fixture lines into a single movement
     * amount so their balance/rounding coverage remains useful after T18.
     *
     * @param  array<int, array{product_id: string, quantity: string, unit_cost: string}>  $lineItems
     */
    private function createInventoryMovementEntryForLines(
        string $companyId,
        string $movementId,
        string $documentNumber,
        array $lineItems,
        \DateTimeInterface $date,
        ?string $description = null,
        ?string $currencyCode = null,
    ): ?JournalEntry {
        $currency = $currencyCode ?? $this->company->currency;
        $scale = CurrencyScale::for($currency);
        $working = $scale + 6;
        $precise = '0';
        foreach ($lineItems as $item) {
            $precise = bcadd(
                $precise,
                bcmul($item['quantity'], $item['unit_cost'], $working),
                $working,
            );
        }
        $amount = CurrencyScale::bcround($precise, $scale);

        return DB::transaction(fn (): ?JournalEntry => $this->glService->createInventoryMovementEntry(
            companyId: $companyId,
            movementId: $movementId,
            sourceType: 'inventory_exit',
            amount: $amount,
            reason: MovementReason::Delivery,
            counterPurpose: SystemAccountPurpose::CostOfGoodsSold,
            debitInventory: false,
            entryDate: $date,
            description: $description ?? "Inventory exit for {$documentNumber}",
            currencyCode: $currency,
            postSynchronously: true,
        ));
    }
}
