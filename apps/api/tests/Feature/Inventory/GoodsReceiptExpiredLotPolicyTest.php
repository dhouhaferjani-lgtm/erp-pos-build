<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
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
use App\Modules\Inventory\Application\DTOs\CreateStockAdjustmentData;
use App\Modules\Inventory\Application\DTOs\StockAdjustmentLineInput;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Application\Services\StockAdjustmentDocumentService;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptFailureReason;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Exceptions\BatchNotApplicableException;
use App\Modules\Inventory\Domain\Exceptions\GoodsReceiptException;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\StockAdjustment;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class GoodsReceiptExpiredLotPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const TRACKED_TABLES = [
        'goods_receipts',
        'goods_receipt_lines',
        'product_batches',
        'inventory_batch_stock',
        'stock_movements',
        'stock_levels',
        'journal_entries',
    ];

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Retail]);
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
            'timezone' => 'Africa/Tunis',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Expired lot receipt user',
            'email' => 'expired-lot-receipt@example.test',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['purchase-orders.receive', 'goods-receipt.create-standalone']);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->supplier = $this->supplier($this->company);
    }

    public function test_past_expiry_without_override_is_a_typed_noop_refusal(): void
    {
        $product = $this->product($this->company, 'P-LOT-6');
        $po = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $product);
        $before = $this->snapshot();

        $response = $this->receive($po, 'LOT-EXPIRED', '2020-01-01');

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'GOODS_RECEIPT_FAILED')
            ->assertJsonPath('error.reason', 'EXPIRED_LOT_REFUSED')
            ->assertJsonPath('error.message', 'Cannot receive expired lot for line 1 (P-LOT-6): expiry date 2020-01-01 is before today.');
        self::assertSame($before, $this->snapshot());
    }

    public function test_http_and_service_refusals_choose_document_order_over_batch_payload_order(): void
    {
        $firstProduct = $this->product($this->company, 'P-ORDER-FIRST');
        $secondProduct = $this->product($this->company, 'P-ORDER-SECOND');
        $po = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $firstProduct);
        $secondLine = $this->addPurchaseOrderLine($po, $secondProduct, 2);
        $firstLine = $po->fresh(['lines'])?->lines->firstWhere('line_number', 1);
        self::assertInstanceOf(DocumentLine::class, $firstLine);

        $this->assertHttpAndServiceExpiredRefusalMatch(
            $po->fresh(['lines']) ?? $po,
            [$firstLine->id => '1.0000', $secondLine->id => '1.0000'],
            [
                $secondLine->id => ['batch_number' => 'LOT-ORDER-SECOND', 'expiry_date' => '2020-01-02'],
                $firstLine->id => ['batch_number' => 'LOT-ORDER-FIRST', 'expiry_date' => '2020-01-01'],
            ],
            expectedSku: 'P-ORDER-FIRST',
        );
    }

    public function test_http_and_service_refusals_ignore_expired_batches_on_zero_quantity_lines(): void
    {
        $zeroProduct = $this->product($this->company, 'P-ZERO-SKIP');
        $positiveProduct = $this->product($this->company, 'P-POSITIVE-REFUSE');
        $po = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $zeroProduct);
        $positiveLine = $this->addPurchaseOrderLine($po, $positiveProduct, 2);
        $zeroLine = $po->fresh(['lines'])?->lines->firstWhere('line_number', 1);
        self::assertInstanceOf(DocumentLine::class, $zeroLine);

        $this->assertHttpAndServiceExpiredRefusalMatch(
            $po->fresh(['lines']) ?? $po,
            [$zeroLine->id => '0.0000', $positiveLine->id => '1.0000'],
            [
                $zeroLine->id => ['batch_number' => 'LOT-ZERO-SKIP', 'expiry_date' => '2020-01-01'],
                $positiveLine->id => ['batch_number' => 'LOT-POSITIVE-REFUSE', 'expiry_date' => '2020-01-02'],
            ],
            expectedSku: 'P-POSITIVE-REFUSE',
        );
    }

    public function test_allow_expired_is_prohibited_without_permission(): void
    {
        $po = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $this->product($this->company, 'P-LOT-NO-PERM'));

        $response = $this->receive($po, 'LOT-NO-PERM', '2020-01-01', allowExpired: true);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        self::assertArrayHasKey('allow_expired', $response->json('error.errors'));
        self::assertNull($response->json('error.reason'));
        self::assertSame(0, GoodsReceipt::query()->count());
    }

    public function test_receive_route_rejects_a_non_uuid_purchase_order_before_controller_queries(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders/not-a-uuid/receive', [
                'quantities' => [],
                'batches' => [],
            ])
            ->assertNotFound();
    }

    public function test_create_draft_service_refuses_unpermitted_expired_override(): void
    {
        $po = $this->purchaseOrder(
            $this->company,
            $this->warehouse,
            $this->supplier,
            $this->product($this->company, 'P-SERVICE-NO-PERM'),
        );
        $line = $po->lines->sole();
        $before = $this->snapshot();

        try {
            app(GoodsReceiptService::class)->createDraft(
                $po,
                [$line->id => '1.0000'],
                [$line->id => ['batch_number' => 'LOT-SERVICE-NO-PERM', 'expiry_date' => '2020-01-01']],
                [],
                [],
                null,
                actorId: $this->user->id,
                allowExpired: true,
            );
            self::fail('Expected an unpermitted service actor to be refused.');
        } catch (GoodsReceiptException $exception) {
            self::assertSame(GoodsReceiptFailureReason::ExpiredLotRefused, $exception->reason);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function test_expired_validation_envelope_does_not_leak_a_foreign_company_line(): void
    {
        $companyResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/companies', [
            'name' => 'Foreign Receipt Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ])->assertCreated();
        $foreignCompany = Company::query()->findOrFail((string) $companyResponse->json('data.id'));
        $foreignWarehouse = Location::query()->where('company_id', $foreignCompany->id)->where('code', 'MAIN')->sole();
        $foreignProduct = $this->product($foreignCompany, 'P-FOREIGN-SECRET');
        $foreignPo = $this->purchaseOrder(
            $foreignCompany,
            $foreignWarehouse,
            $this->supplier($foreignCompany),
            $foreignProduct,
        );
        $foreignLine = $foreignPo->lines->sole();

        app(CompanyContext::class)->setCompanyId($this->company->id);
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-orders/{$foreignPo->id}/receive", [
                'quantities' => [$foreignLine->id => '1.0000'],
                'batches' => [$foreignLine->id => [
                    'batch_number' => 'LOT-FOREIGN-SECRET',
                    'expiry_date' => '2020-01-01',
                ]],
            ]);

        $response->assertUnprocessable()->assertJsonPath('error.reason', 'EXPIRED_LOT_REFUSED');
        self::assertStringNotContainsString('P-FOREIGN-SECRET', (string) $response->json('error.message'));
        self::assertStringNotContainsString($foreignProduct->id, (string) $response->getContent());
    }

    public function test_expired_validation_envelope_does_not_leak_a_non_purchase_order_line(): void
    {
        $product = $this->product($this->company, 'P-NON-PO-SECRET');
        $document = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $product);
        $document->update(['type' => DocumentType::Invoice]);
        $line = $document->lines->sole();

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-orders/{$document->id}/receive", [
                'quantities' => [$line->id => '1.0000'],
                'batches' => [$line->id => [
                    'batch_number' => 'LOT-NON-PO-SECRET',
                    'expiry_date' => '2020-01-01',
                ]],
            ]);

        $response->assertUnprocessable()->assertJsonPath('error.reason', 'EXPIRED_LOT_REFUSED');
        self::assertStringNotContainsString('P-NON-PO-SECRET', (string) $response->json('error.message'));
        self::assertStringNotContainsString($product->id, (string) $response->getContent());
    }

    public function test_manager_role_receives_the_expired_lot_override_permission(): void
    {
        $manager = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Expired lot manager',
            'email' => 'expired-lot-manager@example.test',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $manager->assignRole('manager');

        self::assertTrue($manager->can('goods-receipt.receive-expired'));
    }

    public function test_tenant_team_permission_carries_to_a_real_second_company_and_lots_stay_company_scoped(): void
    {
        $this->user->assignRole('manager');
        self::assertTrue($this->user->can('goods-receipt.receive-expired'));

        $companyResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/companies', [
            'name' => 'Receipt Company B',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ])->assertCreated();
        $companyB = Company::query()->findOrFail((string) $companyResponse->json('data.id'));
        $warehouseB = Location::query()->where('company_id', $companyB->id)->where('code', 'MAIN')->sole();
        $supplierB = $this->supplier($companyB);
        $productA = $this->product($this->company, 'P-LOT-DUP');
        $productB = $this->product($companyB, 'P-LOT-DUP');
        $variantA = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $productA->id,
            'is_active' => true,
        ]);
        $variantB = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $companyB->id,
            'product_id' => $productB->id,
            'is_active' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        $poA = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $productA, variant: $variantA);
        $this->receiveAllWithBatch($poA, 'LOT-DUP', '2020-01-01')->assertOk();

        app(CompanyContext::class)->setCompanyId($companyB->id);
        $poB = $this->purchaseOrder($companyB, $warehouseB, $supplierB, $productB, variant: $variantB);
        $this->receiveAllWithBatch($poB, 'LOT-DUP', '2020-01-01')->assertOk();

        $lots = Batch::query()->where('batch_number', 'LOT-DUP')->get();
        self::assertCount(2, $lots);
        self::assertEqualsCanonicalizing([$this->company->id, $companyB->id], $lots->pluck('company_id')->all());
        self::assertEqualsCanonicalizing([$variantA->id, $variantB->id], $lots->pluck('variant_id')->all());
        self::assertSame(0, BatchStock::query()
            ->whereIn('batch_id', $lots->where('company_id', $companyB->id)->pluck('id'))
            ->where('location_id', $this->warehouse->id)
            ->count());
    }

    public function test_permitted_override_creates_truthfully_expired_lot_and_balanced_ledgers(): void
    {
        $this->grantExpiredPermission();
        $product = $this->product($this->company, 'P-LOT-OVERRIDE');
        $po = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $product, '6.0000');

        $this->receive($po, 'LOT-OVERRIDE', '2020-01-01', allowExpired: true, quantity: '6.0000')->assertOk();

        $batch = Batch::query()->where('batch_number', 'LOT-OVERRIDE')->sole();
        self::assertTrue($batch->is_expired);
        self::assertSame('6.0000', (string) BatchStock::query()->where('batch_id', $batch->id)->sole()->quantity);
        self::assertSame('6.0000', (string) StockLevel::query()->where('product_id', $product->id)->sole()->quantity);
        self::assertSame(0, Artisan::call('batch-expiry:daily-check'));
        self::assertTrue($batch->fresh()?->is_expired);
    }

    public function test_override_admitted_expired_lot_is_immediately_un_toppable_up_by_stock_adjustment(): void
    {
        $this->grantExpiredPermission();
        $product = $this->product($this->company, 'P-LOT-EXPIRED-TOP-UP');
        $po = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $product);
        $this->receive($po, 'LOT-EXPIRED-TOP-UP', '2020-01-01', allowExpired: true)->assertOk();

        $batch = Batch::query()->where('batch_number', 'LOT-EXPIRED-TOP-UP')->sole();
        $beforeMovements = StockMovement::query()->count();

        try {
            DB::transaction(fn (): StockAdjustment => app(StockAdjustmentDocumentService::class)->createDraft(
                new CreateStockAdjustmentData(
                    tenantId: $this->tenant->id,
                    companyId: $this->company->id,
                    locationId: $this->warehouse->id,
                    createdByUserId: $this->user->id,
                    lines: [new StockAdjustmentLineInput(
                        productId: $product->id,
                        variantId: null,
                        batchUuid: (string) $batch->uuid,
                        reasonCode: MovementReason::AdjustmentPositive,
                        deltaQuantity: '1.0000',
                        observedBefore: '1.0000',
                    )],
                ),
            ));
            self::fail('Expected the expired lot to refuse inbound adjustment stock.');
        } catch (BatchNotApplicableException) {
            self::assertSame(0, StockAdjustment::query()->count());
            self::assertSame($beforeMovements, StockMovement::query()->count());
            self::assertSame('1.0000', (string) BatchStock::query()->where('batch_id', $batch->id)->sole()->quantity);
        }
    }

    public function test_expired_lot_refusal_is_identical_at_a_second_location(): void
    {
        $this->grantExpiredPermission();
        $product = $this->product($this->company, 'P-LOT-EXPIRED-ANNEX');
        $mainPo = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $product);
        $this->receive($mainPo, 'LOT-EXPIRED-ANNEX', '2020-01-01', allowExpired: true)->assertOk();

        $annex = Location::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'ANNEX',
            'is_active' => true,
        ]);
        $annexPo = $this->purchaseOrder($this->company, $annex, $this->supplier, $product);
        $this->user->revokePermissionTo('goods-receipt.receive-expired');
        $before = $this->snapshot();

        $response = $this->receive($annexPo, 'LOT-EXPIRED-ANNEX', '2020-01-01', locationId: $annex->id);

        $response->assertUnprocessable()
            ->assertJsonPath('error.reason', 'EXPIRED_LOT_REFUSED')
            ->assertJsonPath(
                'error.message',
                'Cannot receive expired lot for line 1 (P-LOT-EXPIRED-ANNEX): expiry date 2020-01-01 is before today.',
            );
        self::assertSame($before, $this->snapshot());
        self::assertSame(0, BatchStock::query()->where('location_id', $annex->id)->count());
    }

    public function test_today_is_not_expired_under_validation_service_storage_and_daily_check(): void
    {
        $product = $this->product($this->company, 'P-LOT-TODAY');
        $po = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $product);

        $this->receive($po, 'LOT-TODAY', now()->startOfDay()->toDateString())->assertOk();

        $batch = Batch::query()->where('batch_number', 'LOT-TODAY')->sole();
        self::assertFalse($batch->is_expired);
        self::assertSame(0, Artisan::call('batch-expiry:daily-check'));
        self::assertFalse($batch->fresh()?->is_expired);
    }

    public function test_expired_draft_without_override_is_refused_before_persistence(): void
    {
        $po = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $this->product($this->company, 'P-LOT-DRAFT'));

        $this->receive($po, 'LOT-DRAFT', '2020-01-01', saveAsDraft: true)
            ->assertUnprocessable()
            ->assertJsonPath('error.reason', 'EXPIRED_LOT_REFUSED');

        self::assertSame(0, GoodsReceipt::query()->count());
    }

    public function test_expired_override_is_persisted_and_posting_actor_is_reauthorized(): void
    {
        $this->grantExpiredPermission();
        $permittedPo = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $this->product($this->company, 'P-DRAFT-PERMITTED'));
        $permittedDraft = $this->createExpiredDraft($permittedPo, 'LOT-DRAFT-PERMITTED');
        $payload = $permittedDraft->payload;
        self::assertIsArray($payload);
        self::assertTrue($payload['allow_expired'] ?? false);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/goods-receipts/{$permittedDraft->id}/post")
            ->assertOk();

        $unpermittedPo = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $this->product($this->company, 'P-DRAFT-REAUTH'));
        $unpermittedDraft = $this->createExpiredDraft($unpermittedPo, 'LOT-DRAFT-REAUTH');
        $this->user->revokePermissionTo('goods-receipt.receive-expired');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/goods-receipts/{$unpermittedDraft->id}/post");

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'GOODS_RECEIPT_POST_FAILED')
            ->assertJsonPath('error.reason', 'EXPIRED_LOT_REFUSED');
        self::assertSame(DocumentStatus::Confirmed, $unpermittedPo->fresh()?->status);
        self::assertSame(0, StockMovement::query()->where('product_id', $unpermittedPo->lines->sole()->product_id)->count());
    }

    public function test_standalone_surface_returns_reason_and_honours_permitted_override(): void
    {
        $this->allowReceiptFirst();
        $product = $this->product($this->company, 'P-STANDALONE-EXPIRED');
        $payload = $this->standalonePayload($product, 'standalone-expired-no-flag');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/goods-receipts/standalone', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'STANDALONE_RECEIPT_FAILED')
            ->assertJsonPath('error.reason', 'EXPIRED_LOT_REFUSED')
            ->assertJsonPath('error.details.lines.0.line_number', 1)
            ->assertJsonPath('error.details.lines.0.sku', 'P-STANDALONE-EXPIRED')
            ->assertJsonPath('error.details.lines.0.description', 'P-STANDALONE-EXPIRED')
            ->assertJsonPath('error.details.supplied_expiry', '2020-01-01');

        $this->grantExpiredPermission();
        $payload['idempotency_key'] = 'standalone-expired-override';
        $payload['allow_expired'] = true;
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/goods-receipts/standalone', $payload)
            ->assertCreated();
    }

    public function test_undated_default_lot_stays_not_expired(): void
    {
        $product = $this->product($this->company, 'P-DEFAULT-UNDATED');
        $batch = app(BatchStockService::class)->ensureDefaultBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $product->id,
            locationId: $this->warehouse->id,
            targetQuantity: '1.0000',
            shelfLifeDays: null,
            asOfDate: now()->toDateString(),
        );

        self::assertNotNull($batch);
        self::assertNull($batch->expiry_date);
        self::assertFalse($batch->is_expired);
    }

    public function test_missing_and_null_expiry_shapes_remain_validation_errors(): void
    {
        $product = $this->product($this->company, 'P-LOT-NULL');
        $po = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $product);
        $line = $po->lines->sole();
        $base = ['quantities' => [$line->id => '1.0000']];

        $missing = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/purchase-orders/{$po->id}/receive", $base + [
            'batches' => [$line->id => ['batch_number' => 'LOT-MISSING-EXPIRY']],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        self::assertArrayHasKey("batches.{$line->id}.expiry_date", $missing->json('error.errors'));

        $null = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/purchase-orders/{$po->id}/receive", $base + [
            'batches' => [$line->id => ['batch_number' => 'LOT-NULL-EXPIRY', 'expiry_date' => null]],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        self::assertArrayHasKey("batches.{$line->id}.expiry_date", $null->json('error.errors'));
    }

    public function test_lane_refusals_are_repeatable_noops_across_all_seven_tracked_tables(): void
    {
        $expiredPo = $this->purchaseOrder(
            $this->company,
            $this->warehouse,
            $this->supplier,
            $this->product($this->company, 'P-IDEM-EXPIRED'),
        );
        $this->assertRepeatedRefusalIsNoop(
            fn (): TestResponse => $this->receive($expiredPo, 'LOT-IDEM-EXPIRED', '2020-01-01'),
            'EXPIRED_LOT_REFUSED',
        );

        $conflictPo = $this->purchaseOrder(
            $this->company,
            $this->warehouse,
            $this->supplier,
            $this->product($this->company, 'P-IDEM-CONFLICT'),
            '3.0000',
        );
        $this->receive($conflictPo, 'LOT-IDEM-CONFLICT', '2027-01-31')->assertOk();
        $this->assertRepeatedRefusalIsNoop(
            fn (): TestResponse => $this->receive(
                $conflictPo->fresh(['lines']) ?? $conflictPo,
                'LOT-IDEM-CONFLICT',
                '2028-01-31',
            ),
            'BATCH_EXPIRY_CONFLICT',
        );

        $missingBatchPo = $this->purchaseOrder(
            $this->company,
            $this->warehouse,
            $this->supplier,
            $this->product($this->company, 'P-IDEM-BATCH'),
        );
        $missingBatchLine = $missingBatchPo->lines->sole();
        $this->assertRepeatedRefusalIsNoop(
            fn (): TestResponse => $this->actingAs($this->user, 'sanctum')
                ->postJson("/api/v1/purchase-orders/{$missingBatchPo->id}/receive", [
                    'quantities' => [$missingBatchLine->id => '1.0000'],
                ]),
            'BATCH_DATA_REQUIRED',
        );

        $variantProduct = $this->product($this->company, 'P-IDEM-VARIANT');
        ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $variantProduct->id,
            'is_active' => true,
        ]);
        $variantPo = $this->purchaseOrder($this->company, $this->warehouse, $this->supplier, $variantProduct);
        $this->assertRepeatedRefusalIsNoop(
            fn (): TestResponse => $this->receive($variantPo, 'LOT-IDEM-VARIANT', '2027-01-31'),
            'VARIANT_REQUIRED',
        );

        $this->grantExpiredPermission();
        $draftPo = $this->purchaseOrder(
            $this->company,
            $this->warehouse,
            $this->supplier,
            $this->product($this->company, 'P-IDEM-DRAFT'),
        );
        $draft = $this->createExpiredDraft($draftPo, 'LOT-IDEM-DRAFT');
        $this->user->revokePermissionTo('goods-receipt.receive-expired');
        $this->assertRepeatedRefusalIsNoop(
            fn (): TestResponse => $this->actingAs($this->user, 'sanctum')
                ->postJson("/api/v1/goods-receipts/{$draft->id}/post"),
            'EXPIRED_LOT_REFUSED',
        );
    }

    private function createExpiredDraft(Document $po, string $batchNumber): GoodsReceipt
    {
        $this->receive($po, $batchNumber, '2020-01-01', allowExpired: true, saveAsDraft: true)->assertOk();

        return GoodsReceipt::query()->where('purchase_order_id', $po->id)->sole();
    }

    /** @param \Closure(): TestResponse<Response> $call */
    private function assertRepeatedRefusalIsNoop(\Closure $call, string $reason): void
    {
        $before = $this->snapshot();
        $first = $call();
        $afterFirst = $this->snapshot();
        $second = $call();

        $first->assertUnprocessable()->assertJsonPath('error.reason', $reason);
        $second->assertUnprocessable()
            ->assertJsonPath('error.reason', $first->json('error.reason'))
            ->assertJsonPath('error.message', $first->json('error.message'));
        self::assertSame($before, $afterFirst);
        self::assertSame($before, $this->snapshot());
    }

    private function grantExpiredPermission(): void
    {
        Permission::findOrCreate('goods-receipt.receive-expired', 'sanctum');
        $this->user->givePermissionTo('goods-receipt.receive-expired');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function product(Company $company, string $sku): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'sku' => $sku,
            'name' => $sku,
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => true,
            'cost_price' => '1.000000',
        ]);
    }

    private function supplier(Company $company): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'Supplier '.$company->id,
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    private function purchaseOrder(
        Company $company,
        Location $location,
        Partner $supplier,
        Product $product,
        string $quantity = '1.0000',
        ?ProductVariant $variant = null,
    ): Document {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => $supplier->id,
            'location_id' => $location->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-EXP-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $quantity,
            'tax_amount' => '0.000',
            'total' => $quantity,
        ]);
        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'line_number' => 1,
            'description' => $product->sku,
            'quantity' => $quantity,
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity' => '0.0000',
            'free_quantity_received' => '0.0000',
            'unit_price' => '1.000',
            'line_total' => $quantity,
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '1.000000',
        ]);

        $po->load('lines');

        return $po;
    }

    private function addPurchaseOrderLine(Document $po, Product $product, int $lineNumber): DocumentLine
    {
        return DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'line_number' => $lineNumber,
            'description' => $product->sku,
            'quantity' => '1.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity' => '0.0000',
            'free_quantity_received' => '0.0000',
            'unit_price' => '1.000',
            'line_total' => '1.000',
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '1.000000',
        ]);
    }

    /**
     * @param  array<string, string>  $quantities
     * @param  array<string, array{batch_number: string, expiry_date: string}>  $batches
     */
    private function assertHttpAndServiceExpiredRefusalMatch(
        Document $po,
        array $quantities,
        array $batches,
        string $expectedSku,
    ): void {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => $quantities,
                'batches' => $batches,
            ])
            ->assertUnprocessable();

        try {
            app(GoodsReceiptService::class)->createDraft(
                $po,
                $quantities,
                $batches,
                [],
                [],
                null,
                actorId: $this->user->id,
            );
            self::fail('Expected the direct service call to refuse the expired lot.');
        } catch (GoodsReceiptException $exception) {
            self::assertSame($exception->reason->value, $response->json('error.reason'));
            self::assertSame($exception->getMessage(), $response->json('error.message'));
            self::assertSame($exception->details->toArray(), $response->json('error.details'));
            self::assertSame($expectedSku, $response->json('error.details.lines.0.sku'));
        }
    }

    /** @return TestResponse<Response> */
    private function receiveAllWithBatch(Document $po, string $batchNumber, string $expiry): TestResponse
    {
        $line = $po->lines->sole();

        return $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $po->company_id)
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'batches' => [$line->id => ['batch_number' => $batchNumber, 'expiry_date' => $expiry]],
                'allow_expired' => true,
            ]);
    }

    /** @return TestResponse<Response> */
    private function receive(
        Document $po,
        string $batchNumber,
        string $expiry,
        bool $allowExpired = false,
        bool $saveAsDraft = false,
        string $quantity = '1.0000',
        ?string $locationId = null,
    ): TestResponse {
        $line = $po->lines->sole();

        $payload = [
            'quantities' => [$line->id => $quantity],
            'batches' => [$line->id => ['batch_number' => $batchNumber, 'expiry_date' => $expiry]],
        ];
        if ($allowExpired) {
            $payload['allow_expired'] = true;
        }
        if ($saveAsDraft) {
            $payload['save_as_draft'] = true;
        }
        if ($locationId !== null) {
            $payload['location_id'] = $locationId;
        }

        return $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $po->company_id)
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", $payload);
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (self::TRACKED_TABLES as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }

        return $snapshot;
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

    /** @return array<string, mixed> */
    private function standalonePayload(Product $product, string $idempotencyKey): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'idempotency_key' => $idempotencyKey,
            'post_immediately' => false,
            'lines' => [[
                'product_id' => $product->id,
                'qty' => '1.0000',
                'free_qty' => '0.0000',
                'unit_price' => '1.000',
                'batch' => ['batch_number' => 'LOT-STANDALONE-EXPIRED', 'expiry_date' => '2020-01-01'],
            ]],
        ];
    }
}
