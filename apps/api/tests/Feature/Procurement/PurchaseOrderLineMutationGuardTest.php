<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
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
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
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

final class PurchaseOrderLineMutationGuardTest extends TestCase
{
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
            'name' => 'PO Line Guard Tenant',
            'slug' => 'po-line-guard-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PO Line Guard Company',
            'legal_name' => 'PO Line Guard Company SARL',
            'tax_id' => 'PO-LINE-GUARD',
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
            'name' => 'PO Line Guard Admin',
            'email' => 'po-line-guard-admin@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'POLG-WH',
            'name' => 'PO Line Guard Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'PO Line Guard Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    #[Test]
    public function purchase_order_lines_cannot_be_replaced_after_receipt_lines_exist(): void
    {
        $product = $this->createProduct('POLG-LOCKED', 'Locked Product');
        $purchaseOrder = $this->createConfirmedPurchaseOrder($product, quantity: '10.0000');
        $line = $purchaseOrder->lines->sole();

        app(GoodsReceiptService::class)->receiveGoods(
            $purchaseOrder,
            [$line->id => '4.0000'],
            [],
            [],
            [],
            null,
            $this->user->id,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/purchase-orders/{$purchaseOrder->id}", [
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Mutated locked product',
                        'quantity' => '8.0000',
                        'unit_price' => '5.000',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PO_LINES_LOCKED_BY_RECEIPTS');
        $this->assertDatabaseHas('document_lines', [
            'id' => $line->id,
            'document_id' => $purchaseOrder->id,
            'quantity' => '10.0000',
        ]);
    }

    #[Test]
    public function combined_header_and_lines_update_on_locked_po_commits_nothing(): void
    {
        // Regression pin: the receipt-lock rejection must precede the transaction —
        // a 422 on locked lines must NOT silently commit the header fields.
        $product = $this->createProduct('POLG-COMBINED', 'Combined Product');
        $purchaseOrder = $this->createConfirmedPurchaseOrder($product, quantity: '10.0000');
        $line = $purchaseOrder->lines->sole();
        $originalNotes = $purchaseOrder->notes;

        app(GoodsReceiptService::class)->receiveGoods(
            $purchaseOrder,
            [$line->id => '4.0000'],
            [],
            [],
            [],
            null,
            $this->user->id,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/purchase-orders/{$purchaseOrder->id}", [
                'notes' => 'Sneaky header change alongside locked lines',
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Mutated locked product',
                        'quantity' => '8.0000',
                        'unit_price' => '5.000',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PO_LINES_LOCKED_BY_RECEIPTS');
        $this->assertDatabaseHas('documents', [
            'id' => $purchaseOrder->id,
            'notes' => $originalNotes,
        ]);
        $this->assertDatabaseMissing('documents', [
            'id' => $purchaseOrder->id,
            'notes' => 'Sneaky header change alongside locked lines',
        ]);
        $this->assertDatabaseHas('document_lines', [
            'id' => $line->id,
            'document_id' => $purchaseOrder->id,
            'quantity' => '10.0000',
        ]);
    }

    #[Test]
    public function unreceipted_purchase_order_lines_remain_editable(): void
    {
        $product = $this->createProduct('POLG-OPEN', 'Open Product');
        $purchaseOrder = $this->createConfirmedPurchaseOrder($product, quantity: '10.0000');

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/purchase-orders/{$purchaseOrder->id}", [
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Editable product',
                        'quantity' => '8.0000',
                        'unit_price' => '6.000',
                    ],
                ],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('document_lines', [
            'document_id' => $purchaseOrder->id,
            'description' => 'Editable product',
            'quantity' => '8.0000',
            'unit_price' => '6.000',
        ]);
    }

    #[Test]
    public function safe_header_fields_remain_editable_after_receipts_exist(): void
    {
        $product = $this->createProduct('POLG-HEADER', 'Header Product');
        $purchaseOrder = $this->createConfirmedPurchaseOrder($product, quantity: '10.0000');
        $line = $purchaseOrder->lines->sole();

        app(GoodsReceiptService::class)->receiveGoods(
            $purchaseOrder,
            [$line->id => '4.0000'],
            [],
            [],
            [],
            null,
            $this->user->id,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/purchase-orders/{$purchaseOrder->id}", [
                'notes' => 'Header-only update after receipt',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('documents', [
            'id' => $purchaseOrder->id,
            'notes' => 'Header-only update after receipt',
        ]);
        $this->assertDatabaseHas('document_lines', [
            'id' => $line->id,
            'document_id' => $purchaseOrder->id,
        ]);
    }

    private function createProduct(string $sku, string $name): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $name,
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);
    }

    private function createConfirmedPurchaseOrder(Product $product, string $quantity): Document
    {
        $purchaseOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-POLG-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        DocumentLine::create([
            'document_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => $quantity,
            'free_quantity' => '0.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => bcmul($quantity, '5.000', 3),
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '5.000000',
            'price_entry_mode' => 'unit',
        ]);

        /** @var Document $fresh */
        $fresh = $purchaseOrder->fresh(['lines']);

        return $fresh;
    }
}
