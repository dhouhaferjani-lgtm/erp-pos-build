<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\PurchaseOrderService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use Illuminate\Console\Command;

/**
 * @cross-tenant-by-design Manual dev/test command only. It resolves fixtures via `Company::where('name', …)`, and
 *   `companies` is a TENANT table, so post-2026-05-28 (database-per-tenant) it must be run as
 *   `php artisan tenants:run …`; a bare run raises 42P01 on the CENTRAL connection. Never for production.
 */
class TestTaxRecoverability extends Command
{
    protected $signature = 'test:tax-recoverability';

    protected $description = 'Test tax recoverability distribution logic';

    public function handle(PurchaseOrderService $purchaseOrderService): int
    {
        $this->info('=== Testing Tax Recoverability Distribution ===');
        $this->newLine();

        // Test Scenario 1: VAT Registered Company
        $this->testVatRegisteredCompany($purchaseOrderService);

        $this->newLine(2);

        // Test Scenario 2: Non-VAT Registered Company
        $this->testNonVatRegisteredCompany($purchaseOrderService);

        return 0;
    }

    private function testVatRegisteredCompany(PurchaseOrderService $purchaseOrderService): void
    {
        $this->info('📋 SCENARIO 1: VAT Registered Company (Garage Assujetti SARL)');
        $this->line(str_repeat('─', 70));

        $company = Company::where('name', 'Garage Assujetti SARL')->first();
        $supplier = Partner::where('company_id', $company->id)->first();
        $product = Product::where('company_id', $company->id)->where('sku', 'BRAKE-PAD-001')->first();
        $location = Location::where('company_id', $company->id)->first();

        // Clean up any previous test documents
        Document::withTrashed()
            ->where('company_id', $company->id)
            ->where('document_number', 'LIKE', 'PO-TEST-%')
            ->forceDelete();

        $this->info("Company: {$company->name}");
        $this->info("Tax Status: {$company->tax_status->value}");
        $this->info("Product: {$product->name} ({$product->sku})");
        $this->newLine();

        // Create purchase order
        $po = Document::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Draft,
            'partner_id' => $supplier->id,
            'currency' => 'TND',
            'document_date' => now(),
            'document_number' => 'PO-TEST-001',
        ]);

        // Add line
        DocumentLine::create([
            'tenant_id' => $company->tenant_id,
            'document_id' => $po->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => '10',
            'unit_price' => '50.000',
            'line_total' => '500.000',
            'tax_rate' => '19.00',
            'location_id' => $location->id,
        ]);

        $po->refresh();

        $this->info('Created PO with:');
        $this->line('  - Quantity: 10');
        $this->line('  - Unit Price: 50.000 TND');
        $this->line('  - Subtotal: 500.000 TND');
        $this->newLine();

        // Confirm the PO
        $this->info('Confirming purchase order...');
        $confirmedPo = $purchaseOrderService->confirm($po);
        $confirmedPo->refresh();

        $this->newLine();
        $this->info('✓ Purchase Order Confirmed!');
        $this->newLine();

        // Display results
        $line = $confirmedPo->lines->first();
        $this->info('Tax Calculation Results:');
        $this->line("  - Tax Amount: {$confirmedPo->tax_amount} TND");
        $this->line("  - Total: {$confirmedPo->total} TND");
        $this->newLine();

        $this->info('Landed Cost Allocation (Line 1):');
        $this->line("  - Line Total: {$line->line_total} TND");
        $this->line("  - Allocated Costs: {$line->allocated_costs} TND");
        $this->line("  - Non-Recoverable Tax: {$line->non_recoverable_tax} TND");
        $this->line("  - Landed Unit Cost: {$line->landed_unit_cost} TND/unit");
        $this->newLine();

        // Expected values
        $this->comment('Expected Values:');
        $this->line('  - Tax Amount: 96.000 TND (95.000 VAT + 1.000 stamp)');
        $this->line('  - Total: 596.000 TND');
        $this->line('  - Non-Recoverable Tax: 1.000 TND (stamp only, VAT is recoverable)');
        $this->line('  - Landed Unit Cost: 50.100 TND/unit (500 + 1 stamp / 10)');
        $this->newLine();

        // Verification
        $expectedLandedCost = '50.100';
        $expectedNonRecoverable = '1.000';

        if ($line->landed_unit_cost === $expectedLandedCost && $line->non_recoverable_tax === $expectedNonRecoverable) {
            $this->info('✅ PASS: VAT registered company - only stamp duty added to cost');
        } else {
            $this->error('❌ FAIL: Incorrect cost allocation');
            $this->error("  Expected landed cost: {$expectedLandedCost}, Got: {$line->landed_unit_cost}");
            $this->error("  Expected non-recoverable: {$expectedNonRecoverable}, Got: {$line->non_recoverable_tax}");
        }
    }

    private function testNonVatRegisteredCompany(PurchaseOrderService $purchaseOrderService): void
    {
        $this->info('📋 SCENARIO 2: Non-VAT Registered Company (Garage Non-Assujetti)');
        $this->line(str_repeat('─', 70));

        $company = Company::where('name', 'Garage Non-Assujetti')->first();
        $supplier = Partner::where('company_id', $company->id)->first();
        $product = Product::where('company_id', $company->id)->where('sku', 'OIL-FILTER-002')->first();
        $location = Location::where('company_id', $company->id)->first();

        // Clean up any previous test documents
        Document::withTrashed()
            ->where('company_id', $company->id)
            ->where('document_number', 'LIKE', 'PO-TEST-%')
            ->forceDelete();

        $this->info("Company: {$company->name}");
        $this->info("Tax Status: {$company->tax_status->value}");
        $this->info("Product: {$product->name} ({$product->sku})");
        $this->newLine();

        // Create purchase order
        $po = Document::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Draft,
            'partner_id' => $supplier->id,
            'currency' => 'TND',
            'document_date' => now(),
            'document_number' => 'PO-TEST-002',
        ]);

        // Add line
        DocumentLine::create([
            'tenant_id' => $company->tenant_id,
            'document_id' => $po->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => '20',
            'unit_price' => '10.000',
            'line_total' => '200.000',
            'tax_rate' => '19.00',
            'location_id' => $location->id,
        ]);

        $po->refresh();

        $this->info('Created PO with:');
        $this->line('  - Quantity: 20');
        $this->line('  - Unit Price: 10.000 TND');
        $this->line('  - Subtotal: 200.000 TND');
        $this->newLine();

        // Confirm the PO
        $this->info('Confirming purchase order...');
        $confirmedPo = $purchaseOrderService->confirm($po);
        $confirmedPo->refresh();

        $this->newLine();
        $this->info('✓ Purchase Order Confirmed!');
        $this->newLine();

        // Display results
        $line = $confirmedPo->lines->first();
        $this->info('Tax Calculation Results:');
        $this->line("  - Tax Amount: {$confirmedPo->tax_amount} TND");
        $this->line("  - Total: {$confirmedPo->total} TND");
        $this->newLine();

        $this->info('Landed Cost Allocation (Line 1):');
        $this->line("  - Line Total: {$line->line_total} TND");
        $this->line("  - Allocated Costs: {$line->allocated_costs} TND");
        $this->line("  - Non-Recoverable Tax: {$line->non_recoverable_tax} TND");
        $this->line("  - Landed Unit Cost: {$line->landed_unit_cost} TND/unit");
        $this->newLine();

        // Expected values
        $this->comment('Expected Values:');
        $this->line('  - Tax Amount: 39.000 TND (38.000 VAT + 1.000 stamp)');
        $this->line('  - Total: 239.000 TND');
        $this->line('  - Non-Recoverable Tax: 39.000 TND (VAT + stamp both non-recoverable)');
        $this->line('  - Landed Unit Cost: 11.950 TND/unit (200 + 38 VAT + 1 stamp / 20)');
        $this->newLine();

        // Verification
        $expectedLandedCost = '11.950';
        $expectedNonRecoverable = '39.000';

        if ($line->landed_unit_cost === $expectedLandedCost && $line->non_recoverable_tax === $expectedNonRecoverable) {
            $this->info('✅ PASS: Non-VAT registered company - all taxes added to cost');
        } else {
            $this->error('❌ FAIL: Incorrect cost allocation');
            $this->error("  Expected landed cost: {$expectedLandedCost}, Got: {$line->landed_unit_cost}");
            $this->error("  Expected non-recoverable: {$expectedNonRecoverable}, Got: {$line->non_recoverable_tax}");
        }
    }
}
