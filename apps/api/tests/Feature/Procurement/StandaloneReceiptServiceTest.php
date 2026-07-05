<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
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
use App\Modules\Document\Domain\Enums\PriceEntryMode;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptInput;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptLineInput;
use App\Modules\Procurement\Application\StandaloneReceiptService;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StandaloneReceiptServiceTest extends TestCase
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
            'name' => 'Standalone Receipt Tenant',
            'slug' => 'standalone-receipt-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Standalone Receipt Company',
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
            'name' => 'Standalone Receiver',
            'email' => 'standalone-receiver@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SR-WH',
            'name' => 'Standalone Receipt Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Standalone Receipt Supplier',
            'type' => PartnerType::Supplier,
        ]);
    }

    #[Test]
    public function it_fails_closed_when_receipt_first_policy_is_disabled(): void
    {
        $product = $this->createProduct('SR-POLICY');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Receipt-first procurement is disabled');

        app(StandaloneReceiptService::class)->execute($this->input($product, 'policy-disabled-key'));
    }

    #[Test]
    public function it_creates_an_auto_po_and_draft_receipt_idempotently(): void
    {
        $this->allowReceiptFirst();
        $product = $this->createProduct('SR-IDEMP');
        $service = app(StandaloneReceiptService::class);

        $first = $service->execute($this->input($product, 'idem-key-001'));
        $second = $service->execute($this->input($product, 'idem-key-001'));

        $this->assertSame($first->purchaseOrder->id, $second->purchaseOrder->id);
        $this->assertSame($first->receipt->id, $second->receipt->id);
        $this->assertSame(1, Document::query()->where('type', DocumentType::PurchaseOrder)->count());
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(1, DB::table('procurement_idempotency_keys')->count());

        $purchaseOrder = $first->purchaseOrder->fresh(['lines']);
        $receipt = $first->receipt->fresh(['lines']);

        $this->assertSame(DocumentStatus::Confirmed, $purchaseOrder->status);
        $this->assertSame($this->user->id, $purchaseOrder->confirmed_by);
        $this->assertSame('standalone_receipt', $purchaseOrder->payload['auto_generated']['source']);
        $this->assertSame($this->user->id, $purchaseOrder->payload['auto_generated']['actor']);
        $this->assertSame('4.0000', (string) $purchaseOrder->lines[0]->quantity);
        $this->assertSame('1.0000', (string) $purchaseOrder->lines[0]->free_quantity);
        $this->assertSame('5.200', (string) $purchaseOrder->lines[0]->unit_price);

        $this->assertSame(GoodsReceiptStatus::Draft, $receipt->status);
        $this->assertSame('BL-2026-8842', $receipt->external_reference);
        $this->assertSame('2026-07-05', $receipt->external_date?->toDateString());
        $this->assertNull($receipt->lines[0]->received_unit_price);
    }

    #[Test]
    public function it_posts_immediately_with_fail_closed_grir(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->allowReceiptFirst();
        $product = $this->createProduct('SR-POST');

        $result = app(StandaloneReceiptService::class)->execute($this->input(
            $product,
            'post-key-001',
            postImmediately: true,
        ));

        $this->assertSame(DocumentStatus::Received, $result->purchaseOrder->status);
        $this->assertSame(GoodsReceiptStatus::Posted, $result->receipt->status);
        $receiptLine = $result->receipt->lines[0];
        $this->assertNotNull($receiptLine->movement_id);
        $this->assertNull($receiptLine->received_unit_price);
        $this->assertNull($receiptLine->price_override_by);
        $this->assertNull($receiptLine->price_override_at);
        $this->assertNull($receiptLine->price_override_old_basis);
        $this->assertNull($receiptLine->price_override_reason);
        $this->assertSame('5.200000', (string) $receiptLine->accrual_unit_cost);

        $entry = JournalEntry::query()
            ->where('source_type', 'goods_receipt')
            ->where('source_id', $receiptLine->movement_id)
            ->with('lines')
            ->sole();
        $this->assertCount(2, $entry->lines);

        $inventoryAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Inventory);
        $grirAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $inventoryLeg = $entry->lines->where('account_id', $inventoryAccount->id)->first();
        $grirLeg = $entry->lines->where('account_id', $grirAccount->id)->first();

        $this->assertNotNull($inventoryLeg);
        $this->assertSame('20.800', (string) $inventoryLeg->debit);
        $this->assertSame('0.000', (string) $inventoryLeg->credit);
        $this->assertNotNull($grirLeg);
        $this->assertSame('0.000', (string) $grirLeg->debit);
        $this->assertSame('20.800', (string) $grirLeg->credit);
    }

    #[Test]
    public function it_cancels_the_auto_po_when_posting_fails(): void
    {
        $this->allowReceiptFirst();
        $product = $this->createProduct('SR-COMP', requiresBatchTracking: true);

        try {
            app(StandaloneReceiptService::class)->execute($this->input(
                $product,
                'comp-key-001',
                postImmediately: true,
            ));
            $this->fail('Standalone receipt posting should have failed without batch data.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('Batch data is required', $exception->getMessage());
        }

        $purchaseOrder = Document::query()->where('type', DocumentType::PurchaseOrder)->sole();
        $this->assertSame(DocumentStatus::Cancelled, $purchaseOrder->status);
        $this->assertSame(0, GoodsReceipt::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame('0.000000', (string) $product->fresh()->cost_price);
        $this->assertSame(0, DB::table('procurement_idempotency_keys')->count());
    }

    #[Test]
    public function same_key_retry_after_failed_posting_runs_fresh_and_succeeds(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->allowReceiptFirst();
        $product = $this->createProduct('SR-COMP-RETRY', requiresBatchTracking: true);
        $service = app(StandaloneReceiptService::class);

        try {
            $service->execute($this->input($product, 'comp-retry-key', postImmediately: true));
            $this->fail('Standalone receipt posting should have failed without batch data.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('Batch data is required', $exception->getMessage());
        }

        $result = $service->execute($this->input(
            $product,
            'comp-retry-key',
            postImmediately: true,
            batch: ['batch_number' => 'BATCH-RETRY-001', 'expiry_date' => '2027-01-01'],
        ));

        $this->assertSame(GoodsReceiptStatus::Posted, $result->receipt->status);
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(2, Document::query()->where('type', DocumentType::PurchaseOrder)->count());
        $this->assertSame(1, DB::table('procurement_idempotency_keys')->count());
        $this->assertSame($result->receipt->id, DB::table('procurement_idempotency_keys')->value('goods_receipt_id'));
    }

    #[Test]
    public function post_failure_after_grir_write_leaves_no_journal_residue(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->allowReceiptFirst();
        $product = $this->createProduct('SR-LATE-FAIL');

        GoodsReceiptLine::saving(function (GoodsReceiptLine $line): void {
            if ($line->movement_id !== null) {
                throw new \RuntimeException('forced failure after GR-IR write');
            }
        });

        try {
            app(StandaloneReceiptService::class)->execute($this->input(
                $product,
                'late-fail-key',
                postImmediately: true,
            ));
            $this->fail('Standalone receipt posting should have failed after the GR-IR write.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced failure after GR-IR write', $exception->getMessage());
        } finally {
            GoodsReceiptLine::flushEventListeners();
        }

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'goods_receipt')->count());
        $this->assertSame(0, GoodsReceipt::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame('0.000000', (string) $product->fresh()->cost_price);
        $this->assertSame(0, DB::table('procurement_idempotency_keys')->count());
    }

    #[Test]
    public function idempotency_replay_resumes_confirmed_auto_po_without_duplicate_po(): void
    {
        $this->allowReceiptFirst();
        $product = $this->createProduct('SR-RESUME');
        [$purchaseOrder] = $this->createConfirmedAutoPurchaseOrder($product, 'resume-key-001');

        $result = app(StandaloneReceiptService::class)->execute($this->input($product, 'resume-key-001'));

        $this->assertSame($purchaseOrder->id, $result->purchaseOrder->id);
        $this->assertSame($purchaseOrder->id, $result->receipt->purchase_order_id);
        $this->assertSame(1, Document::query()->where('type', DocumentType::PurchaseOrder)->count());
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame($result->receipt->id, DB::table('procurement_idempotency_keys')->value('goods_receipt_id'));
    }

    #[Test]
    public function endpoint_posts_standalone_receipt_without_price_edit_permission(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->allowReceiptFirst();
        $this->user->givePermissionTo([
            'goods-receipt.create-standalone',
            'inventory.view',
            'inventory.receive',
            'purchase-orders.receive',
        ]);
        $product = $this->createProduct('SR-HTTP');

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/goods-receipts/standalone', [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'idempotency_key' => 'http-key-001',
            'external_reference' => 'BL-HTTP-001',
            'external_date' => '2026-07-05',
            'post_immediately' => true,
            'lines' => [[
                'product_id' => $product->id,
                'variant_id' => null,
                'qty' => '3.0000',
                'free_qty' => '0.0000',
                'unit_price' => '7.250',
            ]],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.purchase_order.is_auto_generated', true);
        $response->assertJsonPath('data.goods_receipt.external_reference', 'BL-HTTP-001');
        $response->assertJsonPath('data.goods_receipt.status', 'posted');

        $receiptLine = GoodsReceiptLine::query()->sole();
        $this->assertNull($receiptLine->received_unit_price);
        $this->assertNull($receiptLine->price_override_by);
        $this->assertNull($receiptLine->price_override_at);
        $this->assertNull($receiptLine->price_override_old_basis);
        $this->assertNull($receiptLine->price_override_reason);
        $this->assertSame('7.250000', (string) $receiptLine->accrual_unit_cost);
    }

    #[Test]
    public function endpoint_requires_the_standalone_receipt_permission(): void
    {
        $this->allowReceiptFirst();
        $product = $this->createProduct('SR-HTTP-FORBIDDEN');

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/goods-receipts/standalone', [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'idempotency_key' => 'http-key-002',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => '1.0000',
                'free_qty' => '0.0000',
                'unit_price' => '7.250',
            ]],
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function variant_from_another_company_is_rejected(): void
    {
        $this->allowReceiptFirst();
        $product = $this->createProduct('SR-VAR-OWN');
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Variant Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $otherProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'sku' => 'SR-VAR-FOREIGN-PRODUCT',
            'name' => 'Foreign product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);
        $foreignVariant = $this->createVariant($otherProduct, $otherCompany->id, 'FOREIGN');

        $this->expectException(ModelNotFoundException::class);

        app(StandaloneReceiptService::class)->execute($this->input(
            $product,
            'variant-foreign-company',
            variantId: $foreignVariant->id,
        ));
    }

    #[Test]
    public function variant_from_a_different_product_is_rejected(): void
    {
        $this->allowReceiptFirst();
        $product = $this->createProduct('SR-VAR-OWN-PRODUCT');
        $otherProduct = $this->createProduct('SR-VAR-OTHER-PRODUCT');
        $otherVariant = $this->createVariant($otherProduct, $this->company->id, 'OTHER-PRODUCT');

        $this->expectException(ModelNotFoundException::class);

        app(StandaloneReceiptService::class)->execute($this->input(
            $product,
            'variant-other-product',
            variantId: $otherVariant->id,
        ));
    }

    #[Test]
    public function scoped_variant_id_is_persisted_from_the_resolved_model(): void
    {
        $this->allowReceiptFirst();
        $product = $this->createProduct('SR-VAR-OK');
        $variant = $this->createVariant($product, $this->company->id, 'OK');

        $result = app(StandaloneReceiptService::class)->execute($this->input(
            $product,
            'variant-ok',
            variantId: $variant->id,
        ));

        $this->assertSame($variant->id, $result->purchaseOrder->lines[0]->variant_id);
        $this->assertSame($variant->id, $result->receipt->lines[0]->variant_id);
    }

    private function allowReceiptFirst(): void
    {
        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
            'allow_receipt_first' => true,
            'allow_invoice_first' => false,
            'invoice_first_requires_approval' => true,
        ]);
    }

    /**
     * @param  array{batch_number: string, expiry_date: string, manufacturing_date?: string}|null  $batch
     */
    private function input(
        Product $product,
        string $idempotencyKey,
        bool $postImmediately = false,
        ?array $batch = null,
        ?string $variantId = null,
    ): StandaloneReceiptInput {
        return new StandaloneReceiptInput(
            companyId: $this->company->id,
            supplierId: $this->supplier->id,
            locationId: $this->warehouse->id,
            actorId: $this->user->id,
            idempotencyKey: $idempotencyKey,
            source: 'standalone_receipt',
            externalReference: 'BL-2026-8842',
            externalDate: '2026-07-05',
            postImmediately: $postImmediately,
            lines: [
                new StandaloneReceiptLineInput(
                    productId: $product->id,
                    variantId: $variantId,
                    quantity: '4.0000',
                    freeQuantity: '1.0000',
                    unitPrice: '5.200',
                    batch: $batch,
                ),
            ],
        );
    }

    private function createProduct(string $sku, bool $requiresBatchTracking = false): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $sku.' product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => $requiresBatchTracking,
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);
    }

    /**
     * @return array{Document, DocumentLine}
     */
    private function createConfirmedAutoPurchaseOrder(Product $product, string $idempotencyKey): array
    {
        $purchaseOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->warehouse->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-RESUME-001',
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '20.800',
            'discount_amount' => '0.000',
            'tax_amount' => '0.000',
            'total' => '20.800',
            'balance_due' => '20.800',
            'payload' => [
                'auto_generated' => [
                    'source' => 'standalone_receipt',
                    'actor' => $this->user->id,
                    'created_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $line = DocumentLine::create([
            'document_id' => $purchaseOrder->id,
            'location_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => (string) $product->name,
            'quantity' => '4.0000',
            'free_quantity' => '1.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'free_quantity_invoiced' => '0.0000',
            'unit_price' => '5.200',
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'line_total' => '20.800',
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '5.200000',
            'price_entry_mode' => PriceEntryMode::Unit->value,
        ]);

        DB::table('procurement_idempotency_keys')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'idempotency_key' => $idempotencyKey,
            'purchase_order_id' => $purchaseOrder->id,
            'goods_receipt_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var Document $fresh */
        $fresh = $purchaseOrder->fresh(['lines']);

        return [$fresh, $line];
    }

    private function createVariant(Product $product, string $companyId, string $suffix): ProductVariant
    {
        return ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $companyId,
            'product_id' => $product->id,
            'variant_code' => 'VAR-'.$suffix,
            'sku' => $product->sku.'-'.$suffix,
            'name_suffix' => $suffix,
            'is_default' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);
    }
}
