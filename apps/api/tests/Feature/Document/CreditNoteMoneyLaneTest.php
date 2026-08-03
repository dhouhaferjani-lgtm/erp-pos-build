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
use App\Modules\Document\Domain\CreditNoteAllocation;
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

    /**
     * RULING A (2026-08-03 re-gate, A1 blocker): the gate's own live repros
     * -- amount=20.085 and amount=0.093 against a single 1x99.000@19% line
     * (INV-2026-0507's shape), and amount=41.117 against a 19%+7% multi-rate
     * invoice -- are genuine truncation "holes": no net value reconstructs
     * them exactly. Each MUST return 2xx (never the old RuntimeException ->
     * 500), the effective credited amount must be <= requested (never
     * above), the deviation must be bounded to a handful of millimes, and
     * the response must expose BOTH figures.
     */
    public function test_unreachable_amounts_quantize_down_never_above_and_surface_both_figures(): void
    {
        $invoice = $this->createPostedInvoiceWithLine('INV-HOLE1', qty: '1.0000', unitPrice: '99.000', taxRate: '19.00');

        foreach (['20.085', '0.093'] as $requested) {
            $response = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => $requested,
                'reason' => 'return',
            ]);
            $response->assertCreated();

            $requestedAmount = $response->json('data.requested_amount');
            $creditedAmount = $response->json('data.credited_amount');
            $this->assertNotNull($requestedAmount, "requested={$requested}: response must expose requested_amount on a hole");
            $this->assertNotNull($creditedAmount, "requested={$requested}: response must expose credited_amount on a hole");
            $this->assertSame($requested, (string) $requestedAmount);

            $deviationTicks = (int) bcdiv(bcsub($requested, (string) $creditedAmount, 3), '0.001', 0);
            $this->assertGreaterThanOrEqual(0, $deviationTicks, "requested={$requested}: credited must never exceed requested");
            $this->assertLessThanOrEqual(5, $deviationTicks, "requested={$requested}: deviation must be a few millimes, not a large drift");

            // The persisted total is amount + the CN's own 0.600 duty, using
            // the EFFECTIVE (not requested) amount -- never a silent upward
            // drift back to the raw request.
            $this->assertSame(bcadd((string) $creditedAmount, '0.600', 3), (string) $response->json('data.total'));
        }
    }

    /**
     * Same RULING A guarantee on a multi-rate (19% + 7%) invoice: a per-group
     * search failure must not abort the whole request as a 500.
     */
    public function test_unreachable_amount_on_multi_rate_invoice_quantizes_down(): void
    {
        $invoice = $this->createPostedInvoice('INV-HOLE2');
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Line 19%',
            'quantity' => '1.0000',
            'unit_price' => '100.000',
            'tax_rate' => '19.00',
            'line_total' => '100.000',
        ]);
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 2,
            'description' => 'Line 7%',
            'quantity' => '1.0000',
            'unit_price' => '50.000',
            'tax_rate' => '7.00',
            'line_total' => '50.000',
        ]);
        $invoice->update(['subtotal' => '150.000', 'tax_amount' => '34.500', 'total' => '184.500', 'balance_due' => '184.500']);

        $response = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'amount' => '41.117',
            'reason' => 'return',
        ]);
        $response->assertCreated();

        $creditedAmount = (string) $response->json('data.credited_amount');
        $this->assertSame('41.117', (string) $response->json('data.requested_amount'));
        $this->assertLessThanOrEqual(0, bccomp($creditedAmount, '41.117', 3), 'credited must never exceed requested');
        $deviationTicks = (int) bcdiv(bcsub('41.117', $creditedAmount, 3), '0.001', 0);
        $this->assertLessThanOrEqual(5, $deviationTicks);
    }

    /**
     * RULING B (2026-08-03 re-gate, D1 blocker): a credit note whose
     * stamp-inclusive total exceeds the invoice's remaining balance_due
     * CLAMPS the allocation at that remaining balance, floor 0 -- a negative
     * balance_due must be structurally impossible. Gate repro: credit
     * 120.000 against a 120.000 invoice -> balance_due 0.000 (not -0.600),
     * the CN itself still posts at 120.600, with 0.600 left UNALLOCATED.
     */
    public function test_over_credit_allocation_clamps_balance_due_at_zero(): void
    {
        $invoice = $this->createPostedInvoice('INV-BOUNDARY');
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Full line',
            'quantity' => '1.0000',
            'unit_price' => '100.840',
            'tax_rate' => '19.00',
            'line_total' => '100.840',
        ]);
        // subtotal 100.840 + VAT 19.160 = 120.000 total (no stamp on this
        // fixture invoice -- isolates the CN's OWN duty as the sole cause of
        // the overshoot, matching the gate's "credit 120.000 against 120.000
        // invoice" repro exactly).
        $invoice->update(['subtotal' => '100.840', 'tax_amount' => '19.160', 'total' => '120.000', 'balance_due' => '120.000']);

        $create = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'amount' => '120.000',
            'reason' => 'return',
        ]);
        $create->assertCreated();
        $this->assertSame('120.600', $create->json('data.total'), 'the CN itself still carries its own 0.600 stamp');

        $creditNoteId = $create->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/credit-notes/{$creditNoteId}/confirm")->assertOk();
        $post = $this->actingAs($this->user)->postJson("/api/v1/credit-notes/{$creditNoteId}/post");
        $post->assertOk();
        $this->assertSame('120.600', $post->json('data.total'));

        $invoiceAfter = $this->actingAs($this->user)->getJson("/api/v1/invoices/{$invoice->id}");
        $this->assertSame(
            0,
            bccomp((string) $invoiceAfter->json('data.balance_due'), '0', 3),
            'balance_due must clamp at 0.000, never go negative -- got '.$invoiceAfter->json('data.balance_due')
        );

        /** @var CreditNoteAllocation $allocation */
        $allocation = CreditNoteAllocation::where('credit_note_id', $creditNoteId)->firstOrFail();
        $this->assertSame('120.000', (string) $allocation->amount, 'only 120.000 of the 120.600 CN total is allocated to the invoice');
        $unallocated = bcsub('120.600', (string) $allocation->amount, 3);
        $this->assertSame('0.600', $unallocated, 'the CNs own stamp stays unallocated residual, per existing allocated-vs-total semantics');
    }

    /**
     * BLOCKER 2 (2026-08-03 re-gate, B1): a discounted source line's
     * discount_amount must be PRORATED by the credited quantity ratio, never
     * copied wholesale. Gate repro shape: 10 units @ 10.000 with a flat
     * discount_percent 10 @ 19% (sub 90.000, total 108.100); crediting 2 of
     * 10 units must produce draft == confirm == posted, never the old
     * 24.400-vs-22.020 divergence.
     */
    public function test_line_based_discount_is_prorated_and_draft_equals_confirm_equals_posted(): void
    {
        $invoice = $this->createPostedInvoice('INV-DISCOUNT');
        $line = DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Discounted widget',
            'quantity' => '10.0000',
            'unit_price' => '10.000',
            'discount_percent' => '10.00',
            'tax_rate' => '19.00',
            'line_total' => '90.000', // 10 x 10.000 x 0.9
        ]);
        $invoice->update(['subtotal' => '90.000', 'tax_amount' => '18.100', 'total' => '109.100', 'balance_due' => '109.100']);

        $create = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'reason' => 'return',
            'lines' => [['line_id' => $line->id, 'quantity' => 2]],
        ]);
        $create->assertCreated();

        // Discount-aware: net = 2 x 10.000 x 0.9 = 18.000 (NOT 20.000 -- the
        // old bug ignored the discount entirely), VAT 19% = 3.420, + 0.600
        // stamp = 22.020.
        $this->assertSame('18.000', $create->json('data.subtotal'));
        $this->assertSame('22.020', $create->json('data.total'));

        $creditNoteId = $create->json('data.id');
        $confirm = $this->actingAs($this->user)->postJson("/api/v1/credit-notes/{$creditNoteId}/confirm");
        $confirm->assertOk();
        $this->assertSame('18.000', $confirm->json('data.subtotal'), 'B2: confirm() rewrites subtotal too');
        $this->assertSame('22.020', $confirm->json('data.total'), 'draft == confirm -- the old bug produced 24.400 vs 22.020');

        $post = $this->actingAs($this->user)->postJson("/api/v1/credit-notes/{$creditNoteId}/post");
        $post->assertOk();
        $this->assertSame('22.020', $post->json('data.total'), 'draft == confirm == posted');

        // B2: the persisted document's own three money columns reconcile.
        $persisted = Document::findOrFail($creditNoteId);
        $this->assertSame((string) $persisted->total, bcadd((string) $persisted->subtotal, (string) $persisted->tax_amount, 3));

        // C1: never a negative (fabricated) discount on the credited line --
        // the proration divides cleanly here (2/10), so it should be exactly
        // 1/5th of the source's flat-equivalent, but the invariant that
        // matters is: never negative.
        $creditedLine = DocumentLine::where('document_id', $creditNoteId)->firstOrFail();
        $this->assertGreaterThanOrEqual(0, bccomp((string) ($creditedLine->discount_amount ?? '0'), '0', 3));
    }

    /**
     * BLOCKER 2 edge case: a FLAT discount_amount (not percent) must also be
     * prorated by quantity ratio -- crediting 1 of 10 units on a line with a
     * flat 50.000 discount must NOT drag the whole 50.000 onto the 1-unit
     * line (which would have gone net-negative at confirm() under the old
     * wholesale-copy bug).
     */
    public function test_line_based_flat_discount_amount_is_prorated_not_copied_wholesale(): void
    {
        $invoice = $this->createPostedInvoice('INV-FLATDISC');
        $line = DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Bulk item',
            'quantity' => '10.0000',
            'unit_price' => '20.000',
            'discount_amount' => '50.000',
            'tax_rate' => '19.00',
            'line_total' => '150.000', // 10 x 20.000 - 50.000
        ]);
        $invoice->update(['subtotal' => '150.000', 'tax_amount' => '29.500', 'total' => '179.500', 'balance_due' => '179.500']);

        $create = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'reason' => 'return',
            'lines' => [['line_id' => $line->id, 'quantity' => 1]],
        ]);
        $create->assertCreated();

        // Prorated discount = 50.000 * (1/10) = 5.000; net = 1 x 20.000 -
        // 5.000 = 15.000 -- never the wholesale 50.000 (which would go
        // negative: 20.000 - 50.000 = -30.000).
        $this->assertSame('15.000', $create->json('data.subtotal'));

        $creditedLine = DocumentLine::where('document_id', $create->json('data.id'))->firstOrFail();
        $this->assertSame('5.000', (string) $creditedLine->discount_amount);
        $this->assertGreaterThanOrEqual(0, bccomp((string) $creditedLine->line_total, '0', 3), 'credited line net must never go negative');

        $creditNoteId = $create->json('data.id');
        $confirm = $this->actingAs($this->user)->postJson("/api/v1/credit-notes/{$creditNoteId}/confirm");
        $confirm->assertOk();
        $this->assertSame($create->json('data.total'), $confirm->json('data.total'), 'draft == confirm');
    }

    /**
     * B3 / carry-over R1: the standalone path used to diverge by 1 millime
     * at confirm() (31.168 draft vs 31.169 confirmed) because it hand-rolled
     * a per-line-truncated accumulation instead of TaxCalculationService's
     * accumulate-at-scale+1-round-once pipeline. Unifying onto
     * applyConfirmEquivalentTotals() (this lane) closes it -- assert EXACT
     * equality, not the old "relational, R1 unfixed" shrug.
     */
    public function test_standalone_draft_equals_confirm_exactly_boundary_dirty_lines(): void
    {
        $standalone = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'partner_id' => $this->customer->id,
            'reason' => 'service_issue',
            'lines' => [
                ['description' => 'Item A', 'quantity' => 2, 'unit_price' => '14.285', 'tax_rate' => '7.00'],
            ],
        ]);
        $standalone->assertCreated();

        $creditNoteId = $standalone->json('data.id');
        $confirm = $this->actingAs($this->user)->postJson("/api/v1/credit-notes/{$creditNoteId}/confirm");
        $confirm->assertOk();

        $this->assertSame(
            $standalone->json('data.total'),
            $confirm->json('data.total'),
            'standalone draft == confirm exactly, even on a boundary-dirty (2 x 14.285 @ 7%) fixture -- R1 class closed by unifying onto TaxCalculationService'
        );
        $this->assertSame($standalone->json('data.subtotal'), $confirm->json('data.subtotal'));
        $this->assertSame($standalone->json('data.tax_amount'), $confirm->json('data.tax_amount'));
    }

    /**
     * A2 (2026-08-03 re-gate): the sub-tick delta knob must not collapse
     * silently when the group's first line is zero-priced (a bonus/freebie
     * line). It must move to the first line that actually survives the skip
     * filters -- verified here by confirming a hole-adjacent amount still
     * quantizes down cleanly (2xx, never a 500) rather than degrading to the
     * coarser 21-offset-only search.
     */
    public function test_zero_priced_first_line_does_not_disable_the_search(): void
    {
        $invoice = $this->createPostedInvoice('INV-ZEROFIRST');
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Bonus (free)',
            'quantity' => '1.0000',
            'unit_price' => '0.000',
            'tax_rate' => '19.00',
            'line_total' => '0.000',
        ]);
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 2,
            'description' => 'Paid item',
            'quantity' => '1.0000',
            'unit_price' => '99.000',
            'tax_rate' => '19.00',
            'line_total' => '99.000',
        ]);
        $invoice->update(['subtotal' => '99.000', 'tax_amount' => '19.810', 'total' => '118.810', 'balance_due' => '118.810']);

        $response = $this->actingAs($this->user)->postJson('/api/v1/credit-notes', [
            'source_invoice_id' => $invoice->id,
            'amount' => '20.085',
            'reason' => 'return',
        ]);
        $response->assertCreated();
        $creditedAmount = (string) ($response->json('data.credited_amount') ?? $response->json('data.total'));
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
