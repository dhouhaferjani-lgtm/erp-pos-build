<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Ticket 2026-08-03-w4-purchasing-inventory-defects.md #2 (MTP-PUR-16).
 *
 * `CreateSupplierInvoiceRequest::rules()` never declared `lines.*.is_bonus_line`,
 * so Laravel's `validated()` stripped the flag on every request, and the bonus
 * receipt could never be invoiced (the free units were counted against the PAID
 * matchable window, producing a HARD `quantity_variance` block at post).
 *
 * This is a real receipt -> real supplier invoice -> real post round trip through
 * the HTTP boundary (only the PO itself is DB-seeded, matching the convention in
 * SupplierInvoiceApiTest / PurchaseBonusGoodsReceiptTest — receipt and invoicing
 * are the surfaces under test).
 */
final class SupplierInvoiceBonusLineTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'SI Bonus Line Tenant',
            'slug' => 'si-bonus-line-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            // Parapharmacy's default_modules includes PurchaseBonus, and TN is in
            // procurement.bonus_quantity_countries — the real TN-pharmacy shape
            // the ticket names.
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SI Bonus Line Company',
            'legal_name' => 'SI Bonus Line Company SARL',
            'tax_id' => 'SI-BONUS-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SI Bonus Line Admin',
            'email' => 'si-bonus-line@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SI-BONUS-WH',
            'name' => 'SI Bonus Line Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'SI Bonus Line Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    #[Test]
    public function a_fully_received_bonus_receipt_can_be_invoiced_and_posted_with_correct_totals(): void
    {
        // ── PO: 10 paid + 2 free @ 10.000, fully received ────────────────────
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SI-BONUS-PROD',
            'name' => 'SI Bonus Line Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'cost_price' => '0.000000',
        ]);

        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-SI-BONUS-0001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        $poLine = DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => '10.0000',
            'free_quantity' => '2.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity_received' => '0.0000',
            'unit_price' => '10.000',
            'line_total' => '100.000',
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '10.000000',
            'price_entry_mode' => 'unit',
        ]);

        $receive = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$poLine->id => '10.0000'],
                'free_quantities' => [$poLine->id => '2.0000'],
            ]);
        $receive->assertOk();

        $poLine->refresh();
        $this->assertSame('10.0000', (string) $poLine->quantity_received);
        $this->assertSame('2.0000', (string) $poLine->free_quantity_received);

        // ── Supplier invoice: 10 paid + 2 bonus against the SAME PO line ─────
        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'source_document_ids' => [$po->id],
                'currency' => 'TND',
                'issue_date' => now()->toDateString(),
                'lines' => [
                    [
                        'source_line_id' => $poLine->id,
                        'quantity' => '10',
                        'unit_price' => '10.000',
                        'vat_rate' => '19.00',
                    ],
                    [
                        // Bonus lines carry NO value — the module's canonical bonus
                        // shape is a zero-price line (SupplierInvoiceSnapshotTest,
                        // SupplierInvoiceGlTest's "Remise en nature" both use
                        // unit_price 0.000). Now ENFORCED at the request boundary
                        // (see bonus_line_with_non_zero_unit_price_is_rejected below).
                        'source_line_id' => $poLine->id,
                        'quantity' => '2',
                        'unit_price' => '0.000',
                        'vat_rate' => '19.00',
                        'is_bonus_line' => true,
                    ],
                ],
            ]);

        $create->assertCreated();
        $invoiceId = $create->json('data.id');
        $this->assertIsString($invoiceId);

        // Totals: only the 10 paid units carry value. 19% VAT on the paid subtotal
        // only — the free units contribute NEITHER price NOR VAT.
        $this->assertSame('100.000', $create->json('data.subtotal'));
        $this->assertSame('19.000', $create->json('data.tax_amount'));
        $this->assertSame('119.000', $create->json('data.total'));

        // The bonus flag SURVIVES to the persisted invoice line — this is the
        // exact assertion that failed before the fix (validated() stripped the key).
        $bonusLine = DocumentLine::query()
            ->where('document_id', $invoiceId)
            ->where('quantity', '2.0000')
            ->firstOrFail();
        $this->assertTrue((bool) $bonusLine->is_bonus_line, 'is_bonus_line must survive the HTTP boundary');
        $this->assertSame('0.000', (string) $bonusLine->unit_price);
        $this->assertSame('0.000', (string) $bonusLine->line_total);

        $paidLine = DocumentLine::query()
            ->where('document_id', $invoiceId)
            ->where('quantity', '10.0000')
            ->firstOrFail();
        $this->assertFalse((bool) $paidLine->is_bonus_line);

        // Matched: the bonus line is matched against free_matchable_qty (2.0000
        // received free, 0 invoiced free), the paid line against the paid window
        // (10.0000 received, 0 invoiced) — both exactly consumed, no price variance
        // (the paid line's invoiced price equals the PO price; the bonus line's
        // price is not checked at all).
        $show = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/supplier-invoices/{$invoiceId}");
        $show->assertOk();
        $this->assertSame('matched', $show->json('data.match.status'));

        // And the consequence that actually costs money: the invoice CAN be posted.
        $post = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoiceId}/post");
        $post->assertOk();
        $this->assertSame('posted', $post->json('data.status'));

        // The bonus receipt line's free window is fully consumed, the paid line's
        // paid window is fully consumed — both by the correct (disjoint) buckets.
        $poLine->refresh();
        $this->assertSame('10.0000', (string) $poLine->quantity_invoiced);
        $this->assertSame('2.0000', (string) $poLine->free_quantity_invoiced);

        // GL: NO price-variance leg (the free goods are never billed, so there is
        // no delta to absorb into PPV), VAT deductible is 19.000 (the PAID line
        // only — the free units carry no VAT at all), 408 accrues and clears at
        // exactly the paid value, 401 is credited for exactly 119.000.
        $entry = JournalEntry::query()
            ->where('source_type', 'supplier_invoice')
            ->where('source_id', $invoiceId)
            ->firstOrFail()
            ->load('lines');

        $ppvExpense = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchasePriceVarianceExpense);
        $ppvIncome = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchasePriceVarianceIncome);
        $grir = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $vatDeductible = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VatDeductible);
        $payable = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);

        $this->assertNull($entry->lines->firstWhere('account_id', $ppvExpense->id), 'no PPV expense leg — free goods are never billed');
        $this->assertNull($entry->lines->firstWhere('account_id', $ppvIncome->id), 'no PPV income leg — free goods are never billed');

        $grirLeg = $entry->lines->firstWhere('account_id', $grir->id);
        $this->assertNotNull($grirLeg);
        $this->assertSame('100.000', (string) $grirLeg->debit, '408 accrues the PAID value only');

        $vatLeg = $entry->lines->firstWhere('account_id', $vatDeductible->id);
        $this->assertNotNull($vatLeg);
        $this->assertSame('19.000', (string) $vatLeg->debit, 'VAT deductible excludes the free units');

        $payableLeg = $entry->lines->firstWhere('account_id', $payable->id);
        $this->assertNotNull($payableLeg);
        $this->assertSame('119.000', (string) $payableLeg->credit, '401 is credited for exactly the paid value + its VAT');
    }

    /**
     * B1(ii) enforcement — the wrong-economics shape (a bonus line billed at a
     * non-zero price, which would post the free goods' value as an unfavourable
     * PPV and deduct input VAT on goods received free of charge, invisible to
     * 3-way matching since the matcher skips the price check for bonus lines)
     * must be inexpressible through the API.
     */
    #[Test]
    public function bonus_line_with_non_zero_unit_price_is_rejected(): void
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-SI-BONUS-VALUE-0001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        $poLine = DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'Bonus value probe product',
            'quantity' => '10.0000',
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'free_quantity' => '2.0000',
            'free_quantity_received' => '2.0000',
            'free_quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
            'line_total' => '100.000',
            'allocated_costs' => '0.000000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'source_document_ids' => [$po->id],
                'currency' => 'TND',
                'issue_date' => now()->toDateString(),
                'lines' => [
                    [
                        'source_line_id' => $poLine->id,
                        'quantity' => '2',
                        'unit_price' => '10.000',
                        'vat_rate' => '19.00',
                        'is_bonus_line' => true,
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.unit_price']);
        /** @var array<string, array<int, string>> $errors */
        $errors = $response->json('error.errors');
        $this->assertSame(
            'A bonus/free-goods line must be billed at a zero unit price.',
            $errors['lines.0.unit_price'][0],
        );

        $frResponse = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Language', 'fr')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'source_document_ids' => [$po->id],
                'currency' => 'TND',
                'issue_date' => now()->toDateString(),
                'lines' => [
                    [
                        'source_line_id' => $poLine->id,
                        'quantity' => '2',
                        'unit_price' => '10.000',
                        'vat_rate' => '19.00',
                        'is_bonus_line' => true,
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($frResponse, ['lines.0.unit_price']);
        /** @var array<string, array<int, string>> $frErrors */
        $frErrors = $frResponse->json('error.errors');
        $this->assertSame(
            'Une ligne de bonus/gratuité doit être facturée à un prix unitaire nul.',
            $frErrors['lines.0.unit_price'][0],
        );
    }

    /**
     * B2 — invoice-first (delivery-note / pending-receipt) requests map EACH
     * request line 1:1 to one auto-generated PO line and one invoice line, and
     * declare no per-line free_quantity, so there is no way to express a
     * paid+free split the way the ordinary receive-then-invoice flow does with
     * two invoice lines against the same source_line_id. Before is_bonus_line
     * was declared at all (pre-e9971cf03), the flag was silently stripped and
     * the line posted as an ordinary paid line; now that it survives, an
     * invoice-first line combined with is_bonus_line:true would reach the
     * matcher with an auto-receipt whose free window is always 0 and hard-fail
     * with an opaque Exception. DECISION: refuse the combination outright with
     * a clean 422 rather than invent a new free-quantity contract for a flow
     * the ticket never scoped — no invoice-first + bonus test previously
     * existed, and no current web code sends the combination.
     */
    #[Test]
    public function bonus_line_is_rejected_on_invoice_first_requests(): void
    {
        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
            'allow_invoice_first' => true,
            'invoice_first_requires_approval' => false,
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SI-BONUS-IF-PROD',
            'name' => 'SI Bonus Invoice-First Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'cost_price' => '0.000000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => now()->toDateString(),
                'invoice_first_delivered' => true,
                'location_id' => $this->warehouse->id,
                'idempotency_key' => 'si-bonus-invoice-first-001',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '2.0000',
                    'unit_price' => '0.000',
                    'vat_rate' => '19.00',
                    'is_bonus_line' => true,
                ]],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.is_bonus_line']);
        $this->assertSame(0, Document::query()->where('type', DocumentType::PurchaseOrder)->count(), 'no auto-PO must be created for a refused request');
    }

    /**
     * Ticket #2's prescription: gate `is_bonus_line` on `PurchaseBonusGate` the
     * same way `CreateDocumentRequest` gates PO bonus lines, so a company
     * outside the module + country allowlist gets `prohibited` rather than a
     * silent accept. Mechanic has no PurchaseBonus in its compatible extras at
     * all (config/verticals.php), so the gate is off regardless of country.
     */
    #[Test]
    public function is_bonus_line_is_prohibited_when_the_purchase_bonus_gate_is_off(): void
    {
        ['user' => $user, 'supplier' => $supplier, 'poLine' => $poLine] = $this->gateOffFixture();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $supplier->id,
                'source_document_ids' => [$poLine->document_id],
                'currency' => 'TND',
                'issue_date' => now()->toDateString(),
                'lines' => [
                    [
                        'source_line_id' => $poLine->id,
                        'quantity' => '2',
                        'unit_price' => '10.000',
                        'vat_rate' => '19.00',
                        'is_bonus_line' => true,
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.is_bonus_line']);
    }

    /**
     * B3 — `prohibited` is `! validateRequired`, and `false` satisfies `required`
     * (it is a present, non-null value), so an explicit `is_bonus_line: false` is
     * ALSO rejected on a gate-off company — not just `true`. This mirrors
     * `CreateDocumentRequest.php:129-131`'s identical behaviour for PO bonus
     * lines and matters because any client that always emits the flag (e.g. the
     * web document form's `is_bonus_line: l.is_bonus_line ?? false`) would
     * hard-fail on a non-allowlisted tenant even when it never intended a bonus
     * line. This is a deliberate, consistent API-compat decision — pinned here,
     * not changed.
     */
    #[Test]
    public function is_bonus_line_false_is_also_prohibited_when_the_gate_is_off(): void
    {
        ['user' => $user, 'supplier' => $supplier, 'poLine' => $poLine] = $this->gateOffFixture();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $supplier->id,
                'source_document_ids' => [$poLine->document_id],
                'currency' => 'TND',
                'issue_date' => now()->toDateString(),
                'lines' => [
                    [
                        'source_line_id' => $poLine->id,
                        'quantity' => '2',
                        'unit_price' => '10.000',
                        'vat_rate' => '19.00',
                        'is_bonus_line' => false,
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.is_bonus_line']);
    }

    /**
     * B5 — the gate has two independent axes: module (`PurchaseBonus` in the
     * tenant's enabled modules) and country (`procurement.bonus_quantity_countries`,
     * default `['TN']`). The other gate-off tests exercise the MODULE axis only
     * (Mechanic has no PurchaseBonus extra at all). This pins the COUNTRY axis:
     * Parapharmacy DOES carry PurchaseBonus in its default modules, but a French
     * company is outside the country allowlist, so the gate is still off.
     */
    #[Test]
    public function is_bonus_line_is_prohibited_when_the_module_is_enabled_but_the_country_is_not_allowlisted(): void
    {
        $tenant = Tenant::create([
            'name' => 'SI Bonus Country-Off Tenant',
            'slug' => 'si-bonus-country-off-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            // Parapharmacy DOES default-enable PurchaseBonus (unlike Mechanic) —
            // this isolates the country axis.
            'vertical' => Vertical::Parapharmacy,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'SI Bonus Country-Off Company',
            // procurement.bonus_quantity_countries defaults to ['TN'] — FR is outside it.
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'SI Bonus Country-Off Admin',
            'email' => 'si-bonus-country-off@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);

        $supplier = Partner::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'SI Bonus Country-Off Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $po = Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-SI-COUNTRY-OFF-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        $poLine = DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'Country-off product',
            'quantity' => '10.0000',
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
            'line_total' => '100.000',
            'allocated_costs' => '0.000000',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $supplier->id,
                'source_document_ids' => [$po->id],
                'currency' => 'EUR',
                'issue_date' => now()->toDateString(),
                'lines' => [
                    [
                        'source_line_id' => $poLine->id,
                        'quantity' => '2',
                        'unit_price' => '10.000',
                        'vat_rate' => '20.00',
                        'is_bonus_line' => true,
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['lines.0.is_bonus_line']);
    }

    /**
     * @return array{user: User, supplier: Partner, poLine: DocumentLine}
     */
    private function gateOffFixture(): array
    {
        $tenant = Tenant::create([
            'name' => 'SI Bonus Gate-Off Tenant',
            'slug' => 'si-bonus-gate-off-tenant-'.Str::lower(Str::random(8)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'SI Bonus Gate-Off Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'SI Bonus Gate-Off Admin',
            'email' => 'si-bonus-gate-off-'.Str::lower(Str::random(8)).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);

        $supplier = Partner::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'SI Bonus Gate-Off Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $po = Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-SI-GATE-OFF-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
        ]);

        $poLine = DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'Gate-off product',
            'quantity' => '10.0000',
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
            'line_total' => '100.000',
            'allocated_costs' => '0.000000',
        ]);

        return ['user' => $user, 'supplier' => $supplier, 'poLine' => $poLine];
    }
}
