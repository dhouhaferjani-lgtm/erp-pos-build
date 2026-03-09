<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Seeder;

class SmartPaymentTestDataSeeder extends Seeder
{
    /**
     * Seed Smart Payment test data
     * Creates posted invoices with open balances for testing payment allocation
     */
    public function run(): void
    {
        $this->command->info('Creating Smart Payment test data...');

        // Get demo tenant and company
        $tenant = Tenant::where('slug', 'demo-garage')->first();
        if (! $tenant) {
            $this->command->error('Demo tenant not found! Run DatabaseSeeder first.');

            return;
        }

        $company = Company::where('tenant_id', $tenant->id)->first();
        if (! $company) {
            $this->command->error('Demo company not found! Run DatabaseSeeder first.');

            return;
        }

        // Get a test customer
        $customer = Partner::where('company_id', $company->id)
            ->where('type', 'customer')
            ->first();

        if (! $customer) {
            $this->command->error('No customers found! Run DatabaseSeeder first.');

            return;
        }

        // Get a test product
        $product = Product::where('company_id', $company->id)
            ->where('is_physical', true)
            ->where('is_active', true)
            ->first();

        if (! $product) {
            $this->command->error('No products found! Run DatabaseSeeder first.');

            return;
        }

        // Create 3 posted invoices with open balances
        $invoices = $this->createTestInvoices($tenant, $company, $customer, $product);

        $this->command->info("Created {$invoices->count()} test invoices for Smart Payment testing");
        $this->command->info("Customer: {$customer->name} (ID: {$customer->id})");

        foreach ($invoices as $invoice) {
            $this->command->info("  - {$invoice->document_number}: €{$invoice->total} (Balance: €{$invoice->balance_due})");
        }
    }

    private function createTestInvoices(Tenant $tenant, Company $company, Partner $customer, Product $product)
    {
        $invoices = collect();

        // Invoice 1: Older invoice, overdue (for FIFO testing)
        $invoice1 = Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'partner_id' => $customer->id,
            'document_number' => 'TEST-INV-001',
            'document_date' => now()->subDays(45)->format('Y-m-d'),
            'due_date' => now()->subDays(15)->format('Y-m-d'), // Overdue by 15 days
            'currency' => 'EUR',
            'subtotal' => '1680.67',
            'tax_amount' => '319.33',
            'total' => '2000.00',
            'balance_due' => '2000.00', // Fully unpaid
        ]);

        DocumentLine::create([
            'document_id' => $invoice1->id,
            'product_id' => $product->id,
            'description' => 'Test product for FIFO payment allocation',
            'quantity' => 10,
            'unit_price' => '168.07',
            'tax_rate' => '19.00',
            'line_total' => '2000.00',
            'line_number' => 1,
        ]);

        $invoices->push($invoice1);

        // Invoice 2: Recent invoice (for FIFO testing)
        $invoice2 = Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'partner_id' => $customer->id,
            'document_number' => 'TEST-INV-002',
            'document_date' => now()->subDays(20)->format('Y-m-d'),
            'due_date' => now()->addDays(10)->format('Y-m-d'), // Not overdue yet
            'currency' => 'EUR',
            'subtotal' => '1260.50',
            'tax_amount' => '239.50',
            'total' => '1500.00',
            'balance_due' => '1500.00', // Fully unpaid
        ]);

        DocumentLine::create([
            'document_id' => $invoice2->id,
            'product_id' => $product->id,
            'description' => 'Test product for FIFO payment allocation',
            'quantity' => 10,
            'unit_price' => '126.05',
            'tax_rate' => '19.00',
            'line_total' => '1500.00',
            'line_number' => 1,
        ]);

        $invoices->push($invoice2);

        // Invoice 3: Newer invoice (for FIFO testing)
        $invoice3 = Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'partner_id' => $customer->id,
            'document_number' => 'TEST-INV-003',
            'document_date' => now()->subDays(5)->format('Y-m-d'),
            'due_date' => now()->addDays(25)->format('Y-m-d'), // Not overdue
            'currency' => 'EUR',
            'subtotal' => '2100.84',
            'tax_amount' => '399.16',
            'total' => '2500.00',
            'balance_due' => '2500.00', // Fully unpaid
        ]);

        DocumentLine::create([
            'document_id' => $invoice3->id,
            'product_id' => $product->id,
            'description' => 'Test product for FIFO payment allocation',
            'quantity' => 10,
            'unit_price' => '210.08',
            'tax_rate' => '19.00',
            'line_total' => '2500.00',
            'line_number' => 1,
        ]);

        $invoices->push($invoice3);

        // Invoice 4: For credit note testing (smaller amount)
        $invoice4 = Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'partner_id' => $customer->id,
            'document_number' => 'TEST-INV-CREDIT',
            'document_date' => now()->subDays(10)->format('Y-m-d'),
            'due_date' => now()->addDays(20)->format('Y-m-d'),
            'currency' => 'EUR',
            'subtotal' => '1000.00',
            'tax_amount' => '190.00',
            'total' => '1190.00',
            'balance_due' => '1190.00', // Fully unpaid
        ]);

        DocumentLine::create([
            'document_id' => $invoice4->id,
            'product_id' => $product->id,
            'description' => 'Test product for credit note',
            'quantity' => 10,
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '1190.00',
            'line_number' => 1,
        ]);

        $invoices->push($invoice4);

        return $invoices;
    }
}
