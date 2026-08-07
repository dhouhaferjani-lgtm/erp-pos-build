<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
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
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                        'source_line_id' => $poLine->id,
                        'quantity' => '2',
                        'unit_price' => '10.000',
                        'vat_rate' => '19.00',
                        'is_bonus_line' => true,
                    ],
                ],
            ]);

        $create->assertCreated();
        $invoiceId = $create->json('data.id');
        $this->assertIsString($invoiceId);

        // Totals: (10 + 2) x 10.000 = 120.000 subtotal, 19% VAT on both lines = 22.800.
        $this->assertSame('120.000', $create->json('data.subtotal'));
        $this->assertSame('22.800', $create->json('data.tax_amount'));
        $this->assertSame('142.800', $create->json('data.total'));

        // The bonus flag SURVIVES to the persisted invoice line — this is the
        // exact assertion that failed before the fix (validated() stripped the key).
        $bonusLine = DocumentLine::query()
            ->where('document_id', $invoiceId)
            ->where('quantity', '2.0000')
            ->firstOrFail();
        $this->assertTrue((bool) $bonusLine->is_bonus_line, 'is_bonus_line must survive the HTTP boundary');

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
        $tenant = Tenant::create([
            'name' => 'SI Bonus Gate-Off Tenant',
            'slug' => 'si-bonus-gate-off-tenant',
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
            'email' => 'si-bonus-gate-off@example.com',
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
            'document_number' => 'PO-SI-GATE-OFF-0001',
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

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $supplier->id,
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

        $this->assertApiValidationErrors($response, ['lines.0.is_bonus_line']);
    }
}
