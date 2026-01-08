<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Services\UninvoicedDeliveryNoteService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for year-end uninvoiced delivery note reporting (Tunisia compliance).
 *
 * Tunisia/France accounting requires:
 * - All delivery notes must have matching invoices by fiscal year end
 * - Uninvoiced DNs at year-end require adjustment entries:
 *   - Debit: 418 - Clients, produits non encore facturés
 *   - Credit: 70x - Ventes (sales revenue by category)
 * - These entries are reversed at start of new fiscal year
 */
class UninvoicedDNReportTest extends TestCase
{
    use RefreshDatabase;

    private UninvoicedDeliveryNoteService $service;

    private DocumentConverterRegistry $converterRegistry;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(UninvoicedDeliveryNoteService::class);
        $this->converterRegistry = app(DocumentConverterRegistry::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->seedRequiredAccounts();
    }

    public function test_get_uninvoiced_delivery_notes_returns_empty_for_no_dns(): void
    {
        $result = $this->service->getUninvoicedDeliveryNotes($this->company->id);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_uninvoiced_delivery_notes_returns_confirmed_dns_without_invoice(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);
        $dn2 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00'],
        ]);

        $result = $this->service->getUninvoicedDeliveryNotes($this->company->id);

        $this->assertCount(2, $result);
        $ids = array_column($result, 'id');
        $this->assertContains($dn1->id, $ids);
        $this->assertContains($dn2->id, $ids);
    }

    public function test_get_uninvoiced_delivery_notes_excludes_invoiced_dns(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);
        $dn2 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00'],
        ]);

        // Invoice only dn1
        $this->converterRegistry->convert($dn1, DocumentType::Invoice, ['delivery_note_ids' => [$dn1->id]]);

        $result = $this->service->getUninvoicedDeliveryNotes($this->company->id);

        $this->assertCount(1, $result);
        $this->assertEquals($dn2->id, $result[0]['id']);
    }

    public function test_get_uninvoiced_delivery_notes_excludes_draft_dns(): void
    {
        // Draft DN should not appear
        $this->createDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ], DocumentStatus::Draft);

        $confirmedDn = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00'],
        ]);

        $result = $this->service->getUninvoicedDeliveryNotes($this->company->id);

        $this->assertCount(1, $result);
        $this->assertEquals($confirmedDn->id, $result[0]['id']);
    }

    public function test_get_uninvoiced_delivery_notes_filters_by_date_range(): void
    {
        // DN from December 2024
        $dn2024 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ], Carbon::parse('2024-12-15'));

        // DN from January 2025
        $dn2025 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00'],
        ], Carbon::parse('2025-01-10'));

        // Get only DNs from fiscal year 2024
        $result = $this->service->getUninvoicedDeliveryNotes(
            $this->company->id,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-12-31')
        );

        $this->assertCount(1, $result);
        $this->assertEquals($dn2024->id, $result[0]['id']);
    }

    public function test_get_uninvoiced_delivery_notes_only_for_specified_company(): void
    {
        $otherCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);
        $otherPartner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        // DN for our company
        $ourDn = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);

        // DN for other company
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherPartner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-OTHER-'.time(),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
        ]);

        $result = $this->service->getUninvoicedDeliveryNotes($this->company->id);

        $this->assertCount(1, $result);
        $this->assertEquals($ourDn->id, $result[0]['id']);
    }

    public function test_calculate_uninvoiced_total_returns_sum_of_all_uninvoiced_dns(): void
    {
        $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'], // 500
        ]);
        $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00'], // 500
        ]);
        $this->createConfirmedDeliveryNote([
            ['description' => 'Product C', 'quantity' => '2.00', 'unit_price' => '200.00'], // 400
        ]);

        $totals = $this->service->calculateUninvoicedTotals($this->company->id);

        $this->assertEquals('1400.00', $totals['subtotal']);
    }

    public function test_calculate_uninvoiced_total_includes_tax(): void
    {
        $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00', 'tax_rate' => '19.00'],
        ]);

        $totals = $this->service->calculateUninvoicedTotals($this->company->id);

        $this->assertEquals('1000.00', $totals['subtotal']);
        $this->assertEquals('190.00', $totals['tax_amount']);
        $this->assertEquals('1190.00', $totals['total']);
    }

    public function test_calculate_uninvoiced_total_returns_zero_when_all_invoiced(): void
    {
        $dn = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);

        $this->converterRegistry->convert($dn, DocumentType::Invoice, ['delivery_note_ids' => [$dn->id]]);

        $totals = $this->service->calculateUninvoicedTotals($this->company->id);

        $this->assertEquals('0.00', $totals['subtotal']);
        $this->assertEquals('0.00', $totals['tax_amount']);
        $this->assertEquals('0.00', $totals['total']);
    }

    public function test_generate_year_end_report_includes_all_required_data(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00', 'tax_rate' => '19.00'],
        ]);
        $dn2 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00', 'tax_rate' => '19.00'],
        ]);

        $report = $this->service->generateYearEndReport($this->company->id);

        // Report structure
        $this->assertArrayHasKey('company_id', $report);
        $this->assertArrayHasKey('generated_at', $report);
        $this->assertArrayHasKey('uninvoiced_delivery_notes', $report);
        $this->assertArrayHasKey('totals', $report);
        $this->assertArrayHasKey('by_partner', $report);

        // Totals
        $this->assertEquals('1000.00', $report['totals']['subtotal']);
        $this->assertEquals('190.00', $report['totals']['tax_amount']);
        $this->assertEquals('1190.00', $report['totals']['total']);

        // DNs
        $this->assertCount(2, $report['uninvoiced_delivery_notes']);

        // By partner breakdown
        $this->assertArrayHasKey($this->partner->id, $report['by_partner']);
        $this->assertEquals('1190.00', $report['by_partner'][$this->partner->id]['total']);
    }

    public function test_generate_year_end_adjustment_creates_journal_entry(): void
    {
        $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00', 'tax_rate' => '19.00'],
        ]);

        $entry = $this->service->generateYearEndAdjustment(
            $this->company->id,
            Carbon::parse('2024-12-31')
        );

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals('uninvoiced_dn_adjustment', $entry->source_type);
        $this->assertEquals('2024-12-31', $entry->entry_date->toDateString());

        // Verify entry has correct lines
        $this->assertCount(2, $entry->lines);

        // Debit: 418 - Clients, produits non encore facturés
        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertEquals('1190.00', $debitLine->debit);

        // Credit: 70x - Ventes
        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertEquals('1190.00', $creditLine->credit);
    }

    public function test_generate_year_end_adjustment_returns_null_when_no_uninvoiced_dns(): void
    {
        $entry = $this->service->generateYearEndAdjustment(
            $this->company->id,
            Carbon::parse('2024-12-31')
        );

        $this->assertNull($entry);
    }

    public function test_generate_year_end_adjustment_uses_correct_accounts(): void
    {
        $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        $entry = $this->service->generateYearEndAdjustment(
            $this->company->id,
            Carbon::parse('2024-12-31')
        );

        $account418 = Account::where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::UninvoicedRevenue)
            ->first();

        $salesAccount = Account::where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::ProductRevenue)
            ->first();

        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $creditLine = $entry->lines->firstWhere('credit', '>', 0);

        $this->assertEquals($account418->id, $debitLine->account_id);
        $this->assertEquals($salesAccount->id, $creditLine->account_id);
    }

    public function test_generate_reversal_entry_creates_opposite_entry(): void
    {
        $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        // Generate year-end adjustment
        $adjustmentEntry = $this->service->generateYearEndAdjustment(
            $this->company->id,
            Carbon::parse('2024-12-31')
        );

        // Generate reversal for new year
        $reversalEntry = $this->service->generateReversalEntry(
            $adjustmentEntry,
            Carbon::parse('2025-01-01')
        );

        $this->assertInstanceOf(JournalEntry::class, $reversalEntry);
        $this->assertEquals('uninvoiced_dn_reversal', $reversalEntry->source_type);
        $this->assertEquals('2025-01-01', $reversalEntry->entry_date->toDateString());

        // Verify reversal has opposite debits/credits
        $originalDebit = $adjustmentEntry->lines->firstWhere('debit', '>', 0);
        $reversalCredit = $reversalEntry->lines->where('account_id', $originalDebit->account_id)->first();
        $this->assertEquals($originalDebit->debit, $reversalCredit->credit);

        $originalCredit = $adjustmentEntry->lines->firstWhere('credit', '>', 0);
        $reversalDebit = $reversalEntry->lines->where('account_id', $originalCredit->account_id)->first();
        $this->assertEquals($originalCredit->credit, $reversalDebit->debit);
    }

    public function test_report_groups_by_partner(): void
    {
        // Create DNs for two different partners
        $partner2 = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]); // Partner 1: 1000

        $this->createConfirmedDeliveryNoteForPartner($partner2, [
            ['description' => 'Product B', 'quantity' => '5.00', 'unit_price' => '200.00'],
        ]); // Partner 2: 1000

        $report = $this->service->generateYearEndReport($this->company->id);

        $this->assertCount(2, $report['by_partner']);
        $this->assertEquals('1000.00', $report['by_partner'][$this->partner->id]['total']);
        $this->assertEquals('1000.00', $report['by_partner'][$partner2->id]['total']);
    }

    /**
     * Seed the required accounts for year-end adjustment entries.
     */
    private function seedRequiredAccounts(): void
    {
        // Account 418 - Clients, produits non encore facturés
        Account::create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'code' => '418',
            'name' => 'Clients, produits non encore facturés',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::UninvoicedRevenue,
            'is_active' => true,
        ]);

        // Account 70x - Ventes de produits
        Account::create([
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
            'code' => '701',
            'name' => 'Ventes de produits finis',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);
    }

    /**
     * Create a delivery note with the given lines.
     *
     * @param  array<int, array{description: string, quantity: string, unit_price: string, tax_rate?: string}>  $lines
     */
    private function createDeliveryNote(
        array $lines,
        DocumentStatus $status = DocumentStatus::Draft,
        ?Carbon $date = null
    ): Document {
        $dn = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => $status,
            'document_number' => 'DN-'.time().'-'.random_int(1000, 9999),
            'document_date' => $date ?? now(),
            'currency' => 'TND',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
        ]);

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($lines as $index => $lineData) {
            $lineTotal = bcmul($lineData['quantity'], $lineData['unit_price'], 2);
            $subtotal = bcadd($subtotal, $lineTotal, 2);

            $taxRate = $lineData['tax_rate'] ?? '0.00';
            if (bccomp($taxRate, '0.00', 2) > 0) {
                $lineTax = bcmul($lineTotal, bcdiv($taxRate, '100', 4), 2);
                $taxAmount = bcadd($taxAmount, $lineTax, 2);
            }

            DocumentLine::create([
                'document_id' => $dn->id,
                'line_number' => $index + 1,
                'description' => $lineData['description'],
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => $taxRate,
                'line_total' => $lineTotal,
            ]);
        }

        $total = bcadd($subtotal, $taxAmount, 2);
        $dn->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ]);

        return $dn->fresh(['lines']);
    }

    /**
     * Create a confirmed delivery note with the given lines.
     *
     * @param  array<int, array{description: string, quantity: string, unit_price: string, tax_rate?: string}>  $lines
     */
    private function createConfirmedDeliveryNote(array $lines, ?Carbon $date = null): Document
    {
        return $this->createDeliveryNote($lines, DocumentStatus::Confirmed, $date);
    }

    /**
     * Create a confirmed delivery note for a specific partner.
     *
     * @param  array<int, array{description: string, quantity: string, unit_price: string, tax_rate?: string}>  $lines
     */
    private function createConfirmedDeliveryNoteForPartner(Partner $partner, array $lines): Document
    {
        $dn = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-'.time().'-'.random_int(1000, 9999),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
        ]);

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($lines as $index => $lineData) {
            $lineTotal = bcmul($lineData['quantity'], $lineData['unit_price'], 2);
            $subtotal = bcadd($subtotal, $lineTotal, 2);

            $taxRate = $lineData['tax_rate'] ?? '0.00';
            if (bccomp($taxRate, '0.00', 2) > 0) {
                $lineTax = bcmul($lineTotal, bcdiv($taxRate, '100', 4), 2);
                $taxAmount = bcadd($taxAmount, $lineTax, 2);
            }

            DocumentLine::create([
                'document_id' => $dn->id,
                'line_number' => $index + 1,
                'description' => $lineData['description'],
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => $taxRate,
                'line_total' => $lineTotal,
            ]);
        }

        $total = bcadd($subtotal, $taxAmount, 2);
        $dn->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ]);

        return $dn->fresh(['lines']);
    }
}
