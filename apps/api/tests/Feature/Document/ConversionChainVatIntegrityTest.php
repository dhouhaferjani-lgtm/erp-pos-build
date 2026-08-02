<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Services\Conversion\Concerns\CopiesDocumentData;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Documents-defects lane, defect 3: quote -> order -> invoice conversion
 * silently zeroed VAT on the resulting invoice's confirm(), even for a
 * fully-configured rate.
 *
 * Root cause (two independent, coherent mechanisms — both fixed here):
 *
 * 1. `CopiesDocumentData::createTargetDocument()` never set `fiscal_category`
 *    on a converted document, so it landed on the DB column default
 *    (`NON_FISCAL`) instead of the target type's real category (e.g.
 *    TAX_INVOICE for a converted invoice). `TaxCalculationService::
 *    calculateDocumentTaxes()` filters `TaxConfiguration::forDocumentType()`
 *    on that raw value; TN/FR's seeded rate rows never list `NON_FISCAL` in
 *    `applicable_document_types`, so a converted invoice matched ZERO
 *    configs regardless of how well-configured its rate was.
 *
 * 2. Even with the correct fiscal_category, `TaxCalculationService`
 *    STEP 1 contributed NOTHING for any line whose rate had no matching
 *    active `TaxConfiguration` row -- this is also why confirming a Quote or
 *    SalesOrder (both permanently `NonFiscal` by design; TN/FR's rate rows
 *    never include a NonFiscal token) always zeroed VAT entirely, even for
 *    the fully-configured 19%/13%/7% rates.
 *
 * ORCHESTRATOR RULING: an explicitly-supplied line rate must NEVER be
 * silently zeroed. Where no TaxConfiguration row matches, confirm()/
 * conversion honours the explicit line rate directly (the same formula
 * `InvoiceController::store()` and `CopiesDocumentData::recalculateTotals()`
 * already use for drafts) instead of contributing zero. This keeps the
 * draft==confirm identity 18e61a554 established, extended to the case where
 * confirm() itself was the one computing wrong.
 *
 * @see CopiesDocumentData::createTargetDocument()
 * @see TaxCalculationService::calculateDocumentTaxes()
 */
class ConversionChainVatIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'VAT Chain Tenant',
            'slug' => 'vat-chain-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'VAT Chain Co',
            'legal_name' => 'VAT Chain Co SARL',
            'tax_id' => 'TN-TAX-VC',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        // Real TN rate table: 19%/13%/7%/0% LINE_ITEMS VAT + the 1.000 TND
        // STAMP_TAX_INVOICE row (applies_to = DOCUMENT_TOTAL, TAX_INVOICE only).
        $this->seed(TunisiaTaxConfigurationSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'email' => 'owner@vatchain.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'VAT Chain Customer',
            'type' => PartnerType::Customer,
        ]);

        // A default location -- SalesOrderService::confirm() requires one to
        // reserve stock (auto-reserve is on by default), even for a
        // service-only order with no physical product lines.
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    /**
     * The full quote -> order -> invoice chain, on a fully-configured 19%
     * rate. VAT must be IDENTICAL (19.000, on a 100.000 net subtotal) at
     * every hop that isn't itself a genuine TAX_INVOICE -- the invoice's own
     * confirm() additionally picks up the 1.000 TND stamp duty (a real,
     * document-level tax that only applies to TAX_INVOICE), matching what a
     * directly-created invoice with identical line data would confirm to.
     */
    public function test_quote_to_order_to_invoice_chain_preserves_vat_at_every_hop(): void
    {
        // Draft quote: net 2 * 50.000 = 100.000, VAT 19% = 19.000, total 119.000.
        $quoteResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/quotes', [
            'partner_id' => $this->customer->id,
            'document_date' => now()->toDateString(),
            'lines' => [
                ['description' => 'Chain probe', 'quantity' => '2.0000', 'unit_price' => '50.000', 'tax_rate' => '19.00'],
            ],
        ]);
        $quoteResponse->assertCreated();
        $quote = $quoteResponse->json('data');
        $this->assertSame('100.000', $quote['subtotal']);
        $this->assertSame('19.000', $quote['tax_amount']);
        $this->assertSame('119.000', $quote['total']);

        // Quote confirm: NonFiscal by design, but the explicit 19% line rate
        // must still be honoured -- NOT silently zeroed.
        $quoteConfirmResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote['id']}/confirm");
        $quoteConfirmResponse->assertOk();
        $quoteConfirm = $quoteConfirmResponse->json('data');
        $this->assertSame('100.000', $quoteConfirm['subtotal']);
        $this->assertSame(
            '19.000',
            $quoteConfirm['tax_amount'],
            'confirming a NonFiscal quote must not silently zero an explicitly-configured line rate'
        );
        $this->assertSame('119.000', $quoteConfirm['total']);

        // Convert quote -> order.
        $orderResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote['id']}/convert-to-order");
        $orderResponse->assertCreated();
        $order = $orderResponse->json('data');
        $this->assertSame('100.000', $order['subtotal']);
        $this->assertSame('19.000', $order['tax_amount']);
        $this->assertSame('119.000', $order['total']);

        // Order confirm: same NonFiscal shape as the quote.
        $orderConfirmResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order['id']}/confirm");
        $orderConfirmResponse->assertOk();
        $orderConfirm = $orderConfirmResponse->json('data');
        $this->assertSame(
            '19.000',
            $orderConfirm['tax_amount'],
            'confirming a NonFiscal sales order must not silently zero an explicitly-configured line rate'
        );
        $this->assertSame('119.000', $orderConfirm['total']);

        // Convert order -> invoice (service line, no product_id -> no
        // delivery-note gate).
        $invoiceResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order['id']}/convert-to-invoice");
        $invoiceResponse->assertCreated();
        $invoice = $invoiceResponse->json('data');
        $this->assertSame('100.000', $invoice['subtotal']);
        // FIX (gate follow-up F3, docs/superpowers/tickets/2026-08-02-documents-gate-followups.md):
        // CopiesDocumentData::recalculateTotals() now folds document-level
        // taxes (the 1.000 TND STAMP_TAX_INVOICE) into the DRAFT the same way
        // InvoiceController::withDocumentLevelTaxes() (18e61a554) does for a
        // directly-created draft, so a converted invoice's draft tax_amount/
        // total already equal what confirm() below produces -- no more
        // 119.000 -> 120.000 jump on confirmation.
        $this->assertSame(
            '20.000',
            $invoice['tax_amount'],
            'FIX: a converted invoice DRAFT must already carry 19.000 VAT + 1.000 stamp duty, not just line VAT'
        );
        $this->assertSame('120.000', $invoice['total']);

        // The converted invoice's own fiscal_category must be the real
        // TAX_INVOICE category, not the NON_FISCAL DB column default.
        $invoiceModel = Document::find($invoice['id']);
        $this->assertSame(FiscalCategory::TaxInvoice, $invoiceModel->fiscal_category);

        // Invoice confirm: a genuinely FISCAL document on a fully-configured
        // 19% rate. Must pick up the real VAT (19.000) PLUS the 1.000 TND
        // stamp duty a TAX_INVOICE actually owes -- exactly what a
        // directly-created invoice with identical line data would confirm
        // to. Silently zeroing here was the P0 finding (MTP-DOC-06).
        $invoiceConfirmResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice['id']}/confirm");
        $invoiceConfirmResponse->assertOk();
        $invoiceConfirm = $invoiceConfirmResponse->json('data');
        $this->assertSame('100.000', $invoiceConfirm['subtotal']);
        $this->assertSame(
            $invoice['tax_amount'],
            $invoiceConfirm['tax_amount'],
            'FIX: draft==confirm identity through conversion -- confirm() must not change what the draft already showed'
        );
        $this->assertSame('20.000', $invoiceConfirm['tax_amount']);
        $this->assertSame($invoice['total'], $invoiceConfirm['total'], 'FIX: draft==confirm identity through conversion');
        $this->assertSame('120.000', $invoiceConfirm['total']);
    }

    /**
     * An explicitly-supplied rate with NO active TaxConfiguration row at all
     * (21% -- genuinely unconfigured for this tenant's TN seeder, which only
     * seeds 19/13/7/0) must survive quote, order, AND invoice confirm() --
     * never silently zeroed, per the orchestrator ruling.
     */
    public function test_unconfigured_explicit_rate_survives_confirm_on_every_document_type(): void
    {
        $quoteResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/quotes', [
            'partner_id' => $this->customer->id,
            'document_date' => now()->toDateString(),
            'lines' => [
                ['description' => 'Unconfigured rate probe', 'quantity' => '1.0000', 'unit_price' => '100.000', 'tax_rate' => '21.00'],
            ],
        ]);
        $quoteResponse->assertCreated();
        $quote = $quoteResponse->json('data');
        $this->assertSame('21.000', $quote['tax_amount']);

        $quoteConfirm = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote['id']}/confirm")
            ->json('data');
        $this->assertSame(
            '21.000',
            $quoteConfirm['tax_amount'],
            'an unconfigured 21% explicit rate must survive quote confirm(), not be zeroed'
        );

        $order = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$quote['id']}/convert-to-order")
            ->json('data');

        $orderConfirm = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order['id']}/confirm")
            ->json('data');
        $this->assertSame(
            '21.000',
            $orderConfirm['tax_amount'],
            'an unconfigured 21% explicit rate must survive order confirm(), not be zeroed'
        );

        $invoice = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order['id']}/convert-to-invoice")
            ->json('data');
        // FIX (F3): 21.000 VAT (unconfigured, honoured) + 1.000 TND stamp
        // (a real, correctly-configured TAX_INVOICE document-level tax)
        // already on the converted DRAFT -- not just after confirm().
        $this->assertSame(
            '22.000',
            $invoice['tax_amount'],
            'FIX: a converted invoice DRAFT must already carry the unconfigured 21% line rate + 1.000 stamp duty'
        );

        $invoiceConfirm = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice['id']}/confirm")
            ->json('data');
        $this->assertSame(
            $invoice['tax_amount'],
            $invoiceConfirm['tax_amount'],
            'FIX: draft==confirm identity through conversion'
        );
        // 21.000 VAT (unconfigured, honoured) + 1.000 TND stamp (a real,
        // correctly-configured TAX_INVOICE document-level tax).
        $this->assertSame(
            '22.000',
            $invoiceConfirm['tax_amount'],
            'an unconfigured 21% explicit rate must survive invoice confirm(), not be zeroed'
        );
        $this->assertSame('122.000', $invoiceConfirm['total']);
    }

    /**
     * A directly-created invoice (InvoiceController::store(), the control
     * path already exercised at Draft by InvoiceDraftDocumentTaxTest) on an
     * unconfigured rate must ALSO survive confirm() -- Finding 1 of the
     * parent VAT ticket, now closed by the same STEP 1 fallback.
     */
    public function test_directly_created_invoice_unconfigured_rate_survives_confirm(): void
    {
        $draftResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/invoices', [
            'partner_id' => $this->customer->id,
            'document_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'TND',
            'lines' => [
                ['description' => 'Unconfigured rate', 'quantity' => '1.0000', 'unit_price' => '100.000', 'tax_rate' => '21.00'],
            ],
        ]);
        $draftResponse->assertCreated();
        $draft = $draftResponse->json('data');
        $this->assertSame('22.000', $draft['tax_amount'], '21.000 VAT + 1.000 stamp');

        $confirmResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$draft['id']}/confirm");
        $confirmResponse->assertOk();
        $confirmed = $confirmResponse->json('data');

        $this->assertSame(
            $draft['tax_amount'],
            $confirmed['tax_amount'],
            'draft and confirmed tax must match -- an unconfigured rate must not be silently zeroed at confirm'
        );
        $this->assertSame($draft['total'], $confirmed['total']);
    }
}
