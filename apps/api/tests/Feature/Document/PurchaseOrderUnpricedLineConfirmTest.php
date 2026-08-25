<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * W2-6 gate r2 finding 2 (C2) — a purchase order with an UNPRICED line must not confirm.
 *
 * W2-6 gives a purchase line with no `products.purchase_price` an EMPTY price so the
 * operator has to type it, and the submit path refuses a blank. But the DRAFT AUTOSAVE
 * deliberately coerces that blank to `'0'` so a keystroke-rate save cannot 422 — and
 * that coercion converts "unpriced" into "priced at zero" AT THE PERSISTENCE BOUNDARY.
 * After it, nothing could tell the two apart:
 *
 *   - `PurchaseOrderService::confirm()` checked only type and draft status, so the
 *     autosaved draft was directly confirmable from the detail page
 *     (`PurchaseOrderDetailPage.tsx:155` → POST /purchase-orders/{id}/confirm);
 *   - re-opening the draft loaded `'0.000'` verbatim, which is not blank, so the
 *     client guard passed it and `CreateDocumentRequest`'s `required|numeric` rule
 *     accepts `'0'` (probed: `''` REJECTED, `null` REJECTED, `'0'` ACCEPTED).
 *
 * A confirmed zero-priced PO posts `Dr 37` at zero on goods receipt, drags the
 * weighted-average cost down, and leaves the three-way match to raise nothing worse
 * than an ADVISORY `price_variance` under the shipped `warn` policy. So the durable
 * closure is server-side, on confirm — an FE guard cannot cover the detail-page route.
 *
 * FREE OF CHARGE: `document_lines.is_bonus_line` is the explicit zero-value flag
 * ("Marks explicit zero-value supplier invoice / credit-note bonus lines",
 * migration 2026_07_02_100000). A bonus line at 0.000 is legitimate and still confirms.
 * Same-product purchase bonuses ride `free_quantity` on a normally PRICED line, so they
 * are unaffected either way.
 */
final class PurchaseOrderUnpricedLineConfirmTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Partner $supplier;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Unpriced PO Tenant',
            'slug' => 'unpriced-po-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Unpriced PO Company',
            'legal_name' => 'Unpriced PO Company LLC',
            'tax_id' => 'UNPRICED123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->location = Location::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Main Location',
            'code' => 'MAIN',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Société Générale de Parapharmacie',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Crème hydratante Bébé 200ml',
            'sku' => 'CREM-BEBE_200',
            'is_active' => true,
            'sale_price' => '24.900',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Purchasing User',
            'email' => 'unpriced-po@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);
    }

    public function test_confirm_refuses_a_purchase_order_line_with_a_zero_unit_price(): void
    {
        $purchaseOrder = $this->createDraftPurchaseOrder([
            ['quantity' => '30.0000', 'unit_price' => '0.000'],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PO_LINE_UNPRICED');

        $this->assertSame(
            DocumentStatus::Draft,
            $purchaseOrder->fresh()->status,
            'the purchase order must stay a draft',
        );
    }

    public function test_confirm_refuses_when_only_one_of_several_lines_is_unpriced(): void
    {
        $purchaseOrder = $this->createDraftPurchaseOrder([
            ['quantity' => '30.0000', 'unit_price' => '15.000'],
            ['quantity' => '20.0000', 'unit_price' => '0.000'],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PO_LINE_UNPRICED');
    }

    public function test_confirm_refusal_is_not_reported_as_a_status_transition_error(): void
    {
        $purchaseOrder = $this->createDraftPurchaseOrder([
            ['quantity' => '30.0000', 'unit_price' => '0.000'],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/confirm");

        // The controller maps EVERY \DomainException to INVALID_STATUS_TRANSITION.
        // "this line has no price" is not a status-transition error (N-2 precedent).
        $this->assertNotSame('INVALID_STATUS_TRANSITION', $response->json('error.code'));
    }

    public function test_confirm_allows_an_explicitly_free_of_charge_bonus_line_at_zero(): void
    {
        $purchaseOrder = $this->createDraftPurchaseOrder([
            ['quantity' => '30.0000', 'unit_price' => '15.000'],
            ['quantity' => '3.0000', 'unit_price' => '0.000', 'is_bonus_line' => true],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/confirm")
            ->assertOk();

        $this->assertSame(DocumentStatus::Confirmed, $purchaseOrder->fresh()->status);
    }

    public function test_confirm_still_works_for_a_fully_priced_purchase_order(): void
    {
        $purchaseOrder = $this->createDraftPurchaseOrder([
            ['quantity' => '30.0000', 'unit_price' => '15.000'],
            ['quantity' => '20.0000', 'unit_price' => '20.000'],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/confirm")
            ->assertOk();

        $this->assertSame(DocumentStatus::Confirmed, $purchaseOrder->fresh()->status);
    }

    /**
     * @param  list<array{quantity: string, unit_price: string, is_bonus_line?: bool}>  $lines
     */
    private function createDraftPurchaseOrder(array $lines): Document
    {
        $subtotal = '0.000';
        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, bcmul($line['quantity'], $line['unit_price'], 3), 3);
        }

        $purchaseOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'PO-UNPRICED-'.uniqid(),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $subtotal,
            'tax_amount' => '0.000',
            'total' => $subtotal,
        ]);

        foreach ($lines as $index => $lineData) {
            DocumentLine::create([
                'document_id' => $purchaseOrder->id,
                'product_id' => $this->product->id,
                'line_number' => $index + 1,
                'description' => 'Purchase line '.($index + 1),
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => '0.00',
                'tax_amount' => '0.000',
                'line_total' => bcmul($lineData['quantity'], $lineData['unit_price'], 3),
                'is_bonus_line' => $lineData['is_bonus_line'] ?? false,
            ]);
        }

        /** @var Document */
        return $purchaseOrder->fresh(['lines']);
    }
}
