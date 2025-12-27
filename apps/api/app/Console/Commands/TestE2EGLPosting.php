<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;

final class TestE2EGLPosting extends Command
{
    protected $signature = 'test:e2e-gl-posting';

    protected $description = 'Manual E2E test for Invoice + Credit Note GL posting';

    public function handle(DocumentPostingService $postingService): int
    {
        $this->info('=== E2E Test: Invoice + Credit Note GL Posting ===');

        // Get existing data
        $tenant = Tenant::first();
        $company = Company::first();
        $customer = Partner::where('company_id', $company->id)->first();
        $product = Product::where('company_id', $company->id)->first();

        if ($customer === null || $product === null) {
            $this->error('No test data found. Run seeders first.');

            return 1;
        }

        $this->info("Tenant: {$tenant->name}");
        $this->info("Company: {$company->name}");
        $this->info("Customer: {$customer->name}");
        $this->info("Product: {$product->name}");
        $this->newLine();

        // 1. Create invoice
        $invoice = Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'E2E-INV-'.time(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '1000.00',
            'tax_amount' => '190.00',
            'total' => '1190.00',
            'balance_due' => '1190.00',
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'E2E Test Product',
            'product_id' => $product->id,
            'quantity' => '1.00',
            'unit_price' => '1000.00',
            'tax_rate' => '19.00',
            'line_total' => '1000.00',
        ]);

        $this->info("✓ Created invoice: {$invoice->document_number}");
        $this->info('  Total: EUR 1,190.00 (subtotal: 1,000.00 + tax: 190.00)');
        $this->newLine();

        // 2. Post invoice
        $postedInvoice = $postingService->post($invoice);

        $this->info('✓ Posted invoice');
        $this->info("  Status: {$postedInvoice->status->value}");
        $this->newLine();

        // 3. Check GL entry for invoice
        $invoiceGLEntry = JournalEntry::where('source_id', $invoice->id)
            ->where('source_type', 'invoice')
            ->first();

        if ($invoiceGLEntry === null) {
            $this->error('ERROR: No GL entry created for invoice!');

            return 1;
        }

        $this->info('✓ Invoice GL Entry Created');
        $this->info("  Entry Number: {$invoiceGLEntry->entry_number}");
        $this->info('  Lines:');
        foreach ($invoiceGLEntry->lines as $line) {
            $account = $line->account;
            $this->info("    - {$account->code} {$account->name}: DR {$line->debit} / CR {$line->credit}");
        }
        $this->newLine();

        // 4. Create credit note
        $creditNote = Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $customer->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'E2E-CN-'.time(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '1000.00',
            'tax_amount' => '190.00',
            'total' => '1190.00',
            'balance_due' => '1190.00',
            'source_invoice_id' => $invoice->id,
        ]);

        DocumentLine::create([
            'document_id' => $creditNote->id,
            'line_number' => 1,
            'description' => 'E2E Test Product Return',
            'product_id' => $product->id,
            'quantity' => '1.00',
            'unit_price' => '1000.00',
            'tax_rate' => '19.00',
            'line_total' => '1000.00',
        ]);

        $this->info("✓ Created credit note: {$creditNote->document_number}");
        $this->info('  Total: EUR 1,190.00 (full reversal)');
        $this->newLine();

        // 5. Post credit note
        $postedCreditNote = $postingService->post($creditNote);

        $this->info('✓ Posted credit note');
        $this->info("  Status: {$postedCreditNote->status->value}");
        $this->newLine();

        // 6. Check GL entry for credit note
        $creditNoteGLEntry = JournalEntry::where('source_id', $creditNote->id)
            ->where('source_type', 'credit_note')
            ->first();

        if ($creditNoteGLEntry === null) {
            $this->error('ERROR: No GL entry created for credit note!');

            return 1;
        }

        $this->info('✓ Credit Note GL Entry Created');
        $this->info("  Entry Number: {$creditNoteGLEntry->entry_number}");
        $this->info('  Lines:');
        foreach ($creditNoteGLEntry->lines as $line) {
            $account = $line->account;
            $this->info("    - {$account->code} {$account->name}: DR {$line->debit} / CR {$line->credit}");
        }
        $this->newLine();

        // 7. Verify net impact = 0
        $allLines = JournalLine::whereIn('journal_entry_id', [$invoiceGLEntry->id, $creditNoteGLEntry->id])->get();
        $netByAccount = $allLines->groupBy('account_id')->map(function ($lines) {
            $debit = $lines->sum('debit');
            $credit = $lines->sum('credit');

            return bcSub((string) $debit, (string) $credit, 2);
        });

        $this->info('✓ Net GL Impact Analysis:');
        $allZero = true;
        foreach ($netByAccount as $accountId => $netImpact) {
            $account = \App\Modules\Accounting\Domain\Account::find($accountId);
            $this->info("  - {$account->code} {$account->name}: {$netImpact}");
            if ($netImpact !== '0.00') {
                $allZero = false;
            }
        }

        $this->newLine();

        if ($allZero) {
            $this->info('✅ SUCCESS: All accounts net to ZERO - Perfect reversal!');
        } else {
            $this->error('❌ FAILURE: Accounts do NOT net to zero!');

            return 1;
        }

        // Cleanup
        $invoiceGLEntry->lines()->delete();
        $invoiceGLEntry->delete();
        $creditNoteGLEntry->lines()->delete();
        $creditNoteGLEntry->delete();
        $postedInvoice->lines()->delete();
        $postedInvoice->delete();
        $postedCreditNote->lines()->delete();
        $postedCreditNote->delete();

        $this->newLine();
        $this->info('✓ Cleanup complete');

        return 0;
    }
}
