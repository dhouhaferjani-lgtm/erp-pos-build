<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Credit-note money lane (2026-08-03): root-causes and fixes the 50.174
 * drift (orchestrator smoke finding #1 -- an amount-based credit note
 * entered as 50.000 posted at 50.174, live-repro'd as CN-2026-0018/0019
 * and MTP-IDEM-03), folds the credit note's own document-level duty into
 * Draft totals (ticket 2026-08-02-credit-note-draft-stamp-and-scale4-totals.md
 * finding 3), and verifies the over-credit remaining-amount guard still
 * refuses correctly with the corrected totals.
 *
 * ORCHESTRATOR RULING: "amount" is the VAT-inclusive value the operator
 * wants to credit, EXCLUDING document-level duties -- posted total must be
 * exactly amount + documentTaxTotal, and the internal decomposition must
 * reconstruct amount exactly (largest-remainder / last-line-residual, never
 * lossy truncation).
 */
class CreditNoteMoneyLaneTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_symbol' => 'د.ت',
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'domain' => 'test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(TunisiaTaxConfigurationSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['credit-notes.view', 'credit-notes.create', 'credit-notes.post', 'invoices.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corporation',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customers',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '701',
            'name' => 'Product Sales',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4457',
            'name' => 'VAT Collected',
            'type' => 'liability',
            'system_purpose' => SystemAccountPurpose::VatCollected,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '709',
            'name' => 'Sales Returns',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::SalesReturn,
            'is_active' => true,
        ]);
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '706',
            'name' => 'Service Revenue',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::ServiceRevenue,
            'is_active' => true,
        ]);
    }

    /**
     * Live repro CN-2026-0018/0019: single-line 99.000 net invoice @19% TVA
     * (+1.000 STAMP_TAX_INVOICE, TN). Amount-based credit of 50.000 must
     * reconstruct EXACTLY: draft total == confirmed total == 50.600
     * (amount + 0.600 STAMP_CREDIT_NOTE), and the internal net/VAT
     * decomposition must sum to 50.000 exactly.
     */
    public function test_amount_based_credit_note_reconstructs_exactly_with_tn_stamp(): void
    {
        $invoice = $this->createPostedInvoiceWithLine('INV-9001', qty: '1.0000', unitPrice: '99.000', taxRate: '19.00');

        $create = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'amount' => '50.000',
            'reason' => 'return',
        ]);
        $create->assertCreated();

        // Root-cause regression guard: NEVER 50.174 (the drifted value) and
        // NEVER the bare amount (50.000, item 2's stamp not folded).
        $this->assertSame('50.600', $create->json('data.total'));
        $this->assertNotSame('50.174', $create->json('data.total'));

        // Internal decomposition reconstructs the requested amount EXACTLY:
        // subtotal (VAT-excl net) + VAT-only portion (tax_amount minus the
        // 0.600 CN stamp folded in by item 2) == 50.000 exactly.
        /** @var numeric-string $subtotal */
        $subtotal = (string) $create->json('data.subtotal');
        /** @var numeric-string $taxAmount */
        $taxAmount = (string) $create->json('data.tax_amount');
        $this->assertSame('50.600', bcadd($subtotal, $taxAmount, 3), 'subtotal + tax_amount (VAT + stamp) == posted total');
        $vatOnly = bcsub($taxAmount, '0.600', 3);
        $this->assertSame('50.000', bcadd($subtotal, $vatOnly, 3), 'subtotal + VAT-only reconstructs the requested amount exactly');

        $creditNoteId = $create->json('data.id');

        $confirm = $this->actingAs($this->user)->postJson("/api/v1/credit-notes/{$creditNoteId}/confirm");
        $confirm->assertOk();

        // Draft == confirmed, byte-for-byte.
        $this->assertSame('50.600', $confirm->json('data.total'));
        $this->assertSame($create->json('data.subtotal'), $confirm->json('data.subtotal'));
        $this->assertSame($create->json('data.tax_amount'), $confirm->json('data.tax_amount'));

        $post = $this->actingAs($this->user)->postJson("/api/v1/credit-notes/{$creditNoteId}/post");
        $post->assertOk();
        $this->assertSame('50.600', $post->json('data.total'));
    }

    /**
     * Truncation vector: two lines, same 19% rate, whose proportional shares
     * of a round amount (100.000 / 267.750 net-of-duty invoice value) do NOT
     * divide cleanly -- matches MTP-DOC-16's fixture (10 @ 12.500 + 4 @
     * 25.000). Must still reconstruct exactly via largest-remainder/residual
     * allocation, never drift.
     */
    public function test_amount_based_credit_note_truncation_vector_across_multiple_lines(): void
    {
        $invoice = $this->createPostedInvoice('INV-9002');
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Line A',
            'quantity' => '10.0000',
            'unit_price' => '12.500',
            'tax_rate' => '19.00',
            'line_total' => '125.000',
        ]);
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 2,
            'description' => 'Line B',
            'quantity' => '4.0000',
            'unit_price' => '25.000',
            'tax_rate' => '19.00',
            'line_total' => '100.000',
        ]);
        $invoice->update(['subtotal' => '225.000', 'tax_amount' => '43.750', 'total' => '268.750', 'balance_due' => '268.750']);

        $create = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'amount' => '100.000',
            'reason' => 'return',
        ]);
        $create->assertCreated();
        $this->assertSame('100.600', $create->json('data.total'));

        $creditNoteId = $create->json('data.id');
        $confirm = $this->actingAs($this->user)->postJson("/api/v1/credit-notes/{$creditNoteId}/confirm");
        $confirm->assertOk();
        $this->assertSame('100.600', $confirm->json('data.total'), 'draft == confirmed even when the proration does not divide cleanly');
    }

    /**
     * MTP-DOC-18 guard verification: with the new (larger, stamp-inclusive)
     * draft totals, a genuinely over-credit request must still be refused.
     */
    public function test_over_credit_guard_still_refuses_with_new_stamp_inclusive_totals(): void
    {
        $invoice = $this->createPostedInvoice('INV-9003');
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Line A',
            'quantity' => '10.0000',
            'unit_price' => '12.500',
            'tax_rate' => '19.00',
            'line_total' => '125.000',
        ]);
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 2,
            'description' => 'Line B',
            'quantity' => '4.0000',
            'unit_price' => '25.000',
            'tax_rate' => '19.00',
            'line_total' => '100.000',
        ]);
        $invoice->update(['subtotal' => '225.000', 'tax_amount' => '43.750', 'total' => '268.750', 'balance_due' => '268.750']);

        $cn1 = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'amount' => '100.000',
            'reason' => 'return',
        ]);
        $cn1->assertCreated();
        $this->assertSame('100.600', $cn1->json('data.total'), 'draft cn1 now carries its own stamp');

        // remaining (RAW amount comparison, unchanged guard semantics) =
        // 268.750 - 100.600 = 168.150 < 200.000 -> still refused.
        $overCredit = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'amount' => '200.000',
            'reason' => 'return',
        ]);
        $overCredit->assertStatus(422);

        // A legitimate remaining credit is still accepted.
        $withinRemaining = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'amount' => '150.000',
            'reason' => 'return',
        ]);
        $withinRemaining->assertCreated();
    }

    /**
     * Item 3 (scale hygiene): line-based and standalone credit notes format
     * at the resolved currency scale (3 for TND), never a raw scale-4 leak.
     */
    public function test_line_based_and_standalone_credit_notes_format_at_currency_scale(): void
    {
        $invoice = $this->createPostedInvoice('INV-9004');
        $line = DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Widget',
            'quantity' => '3.0000',
            'unit_price' => '40.000',
            'tax_rate' => '19.00',
            'line_total' => '120.000',
        ]);
        $invoice->update(['subtotal' => '120.000', 'tax_amount' => '23.800', 'total' => '143.800', 'balance_due' => '143.800']);

        $lineBased = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'reason' => 'return',
            'lines' => [['line_id' => $line->id, 'quantity' => 2]],
        ]);
        $lineBased->assertCreated();
        // net 2*40.000=80.000, VAT 19%=15.200 -> 95.200 (VAT-only draft), then
        // the 0.600 CN stamp folds in on top -> 95.800.
        $this->assertSame('80.000', $lineBased->json('data.subtotal'));
        $this->assertSame('95.800', $lineBased->json('data.total'));
        $this->assertDoesNotMatchRegularExpression('/\.\d{4,}$/', (string) $lineBased->json('data.total'));

        $standalone = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'partner_id' => $this->customer->id,
            'reason' => 'service_issue',
            'lines' => [[
                'description' => 'Goodwill credit',
                'quantity' => 2,
                'unit_price' => '30.000',
                'tax_rate' => '19.00',
            ]],
        ]);
        $standalone->assertCreated();
        $this->assertSame('60.000', $standalone->json('data.subtotal'));
        // net 60.000 + VAT 11.400 + 0.600 stamp = 72.000.
        $this->assertSame('72.000', $standalone->json('data.total'));
        $this->assertDoesNotMatchRegularExpression('/\.\d{4,}$/', (string) $standalone->json('data.total'));
    }

    private function createPostedInvoice(string $number): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'status' => DocumentStatus::Posted,
            'document_number' => $number,
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'currency' => 'TND',
        ]);
    }

    /**
     * @param  numeric-string  $qty
     * @param  numeric-string  $unitPrice
     * @param  numeric-string  $taxRate
     */
    private function createPostedInvoiceWithLine(string $number, string $qty, string $unitPrice, string $taxRate): Document
    {
        $invoice = $this->createPostedInvoice($number);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Widget',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'line_total' => bcmul($qty, $unitPrice, 3),
        ]);

        // 99.000 net, 19% VAT (18.810) + 1.000 STAMP_TAX_INVOICE = 118.810 total.
        $invoice->update([
            'subtotal' => '99.000',
            'tax_amount' => '19.810',
            'total' => '118.810',
            'balance_due' => '118.810',
        ]);

        return $invoice->load('lines');
    }
}
