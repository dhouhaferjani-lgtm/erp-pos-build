<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
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
use App\Modules\Document\Domain\Services\Conversion\Converters\PurchaseOrderToGoodsReceiptConverter;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\GoodsReceiptResult;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Inventory\Domain\StockMovement;
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

final class GoodsReceiptLedgerWriteTest extends TestCase
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
            'name' => 'GR Ledger Tenant',
            'slug' => 'gr-ledger-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GR Ledger Company',
            'legal_name' => 'GR Ledger Company SARL',
            'tax_id' => 'GR-LEDGER-TAX',
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
            'name' => 'GR Ledger Receiver',
            'email' => 'gr-ledger-receiver@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
            'inventory.receive',
            'purchase-orders.receive',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'GRL-WH',
            'name' => 'GR Ledger Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'GR Ledger Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    #[Test]
    public function receive_goods_writes_one_posted_header_and_lines_with_movement_links(): void
    {
        $productA = $this->createProduct('GRL-A', 'Ledger Product A');
        $productB = $this->createProduct('GRL-B', 'Ledger Product B');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $productA, 'quantity' => '10.0000', 'free_quantity' => '0.0000', 'unit_price' => '5.000', 'landed_unit_cost' => '5.500000'],
            ['product' => $productB, 'quantity' => '4.0000', 'free_quantity' => '0.0000', 'unit_price' => '8.000', 'landed_unit_cost' => '8.000000'],
        ]);

        $result = app(GoodsReceiptService::class)->receiveGoods(
            $po,
            [
                $po->lines[0]->id => '6.0000',
                $po->lines[1]->id => '4.0000',
            ],
            [],
            [],
            [],
            null,
            $this->user->id,
        );

        $this->assertInstanceOf(GoodsReceiptResult::class, $result);
        $this->assertSame($result->purchaseOrder->id, $po->id);
        $this->assertSame(GoodsReceiptStatus::Posted, $result->receipt->status);
        $this->assertSame($this->user->id, $result->receipt->received_by);
        $this->assertMatchesRegularExpression('/^GRN-\d{4}-0001$/', $result->receipt->receipt_number);

        $lines = GoodsReceiptLine::query()->where('goods_receipt_id', $result->receipt->id)->orderBy('po_line_id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('0.0000', (string) $lines[0]->free_qty);
        $this->assertSame('5.500000', (string) $lines[0]->landed_unit_cost);
        $this->assertSame('5.500000', (string) $lines[0]->accrual_unit_cost);
        $this->assertSame('5.500000', (string) $lines[0]->effective_unit_cost);
        $this->assertNotNull($lines[0]->movement_id);
        $this->assertNull($lines[0]->free_movement_id);
        $this->assertSame('0.0000', (string) $lines[0]->quantity_invoiced);
        $this->assertNull($lines[0]->received_unit_price);

        $this->assertLedgerCountersMatchPo($result->purchaseOrder->fresh(['lines']) ?? $result->purchaseOrder);
    }

    #[Test]
    public function create_draft_persists_uncosted_receipt_lines_without_side_effects(): void
    {
        $product = $this->createProduct('GRL-DRAFT', 'Draft Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '10.0000', 'free_quantity' => '2.0000', 'unit_price' => '5.000', 'landed_unit_cost' => '5.000000'],
        ]);
        $line = $po->lines->first();

        $draft = app(GoodsReceiptService::class)->createDraft(
            $po,
            [$line->id => '4.0000'],
            [],
            [$line->id => '1.0000'],
            [$line->id => '5.200'],
            'Delivery note unit price',
            $this->user->id,
        );

        $this->assertSame(GoodsReceiptStatus::Draft, $draft->status);
        $this->assertNull($draft->receipt_number);
        $this->assertSame($this->user->id, $draft->received_by);

        $draftLine = GoodsReceiptLine::query()->where('goods_receipt_id', $draft->id)->sole();
        $this->assertSame('4.0000', (string) $draftLine->received_qty);
        $this->assertSame('1.0000', (string) $draftLine->free_qty);
        $this->assertSame('5.200', (string) $draftLine->received_unit_price);
        $this->assertNull($draftLine->landed_unit_cost);
        $this->assertNull($draftLine->accrual_unit_cost);
        $this->assertNull($draftLine->effective_unit_cost);
        $this->assertNull($draftLine->movement_id);
        $this->assertNull($draftLine->free_movement_id);
        $this->assertSame('Delivery note unit price', $draftLine->price_override_reason);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame('0.0000', (string) $line->fresh()->quantity_received);
        $this->assertSame('0.0000', (string) $line->fresh()->free_quantity_received);
    }

    #[Test]
    public function post_draft_assigns_grn_and_applies_existing_paid_and_free_side_effects(): void
    {
        // Seed real GL accounts so the GoodsReceived listener can post GR-IR —
        // without them it swallows the failure and the GR-IR pin below would
        // assert against a silently-empty journal (the exact drift scenario).
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->user->givePermissionTo('goods-receipt.edit-price');
        $product = $this->createProduct('GRL-DRAFT-POST', 'Draft Post Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '10.0000', 'free_quantity' => '2.0000', 'unit_price' => '5.000', 'landed_unit_cost' => '5.000000'],
        ]);
        $line = $po->lines->first();
        $service = app(GoodsReceiptService::class);

        $draft = $service->createDraft(
            $po,
            [$line->id => '4.0000'],
            [],
            [$line->id => '1.0000'],
            [$line->id => '5.200'],
            'Delivery note unit price',
            $this->user->id,
        );

        $posted = $service->post($draft, $this->user->id);

        $this->assertSame($draft->id, $posted->id);
        $this->assertSame(GoodsReceiptStatus::Posted, $posted->status);
        $this->assertMatchesRegularExpression('/^GRN-\d{4}-0001$/', (string) $posted->receipt_number);

        $postedLine = GoodsReceiptLine::query()->where('goods_receipt_id', $posted->id)->sole();
        $this->assertNotNull($postedLine->movement_id);
        $this->assertNotNull($postedLine->free_movement_id);
        $this->assertSame('5.200000', (string) $postedLine->landed_unit_cost);
        $this->assertSame('5.200000', (string) $postedLine->accrual_unit_cost);
        $this->assertSame('4.160000', (string) $postedLine->effective_unit_cost);
        $this->assertSame($this->user->id, $postedLine->price_override_by);
        $this->assertNotNull($postedLine->price_override_at);
        $this->assertSame('5.000000', (string) $postedLine->price_override_old_basis);
        $this->assertSame('Delivery note unit price', $postedLine->price_override_reason);

        $movements = StockMovement::query()->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame('1.0000', (string) $movements[0]->quantity);
        $this->assertSame('0.000000', (string) $movements[0]->unit_cost);
        $this->assertSame('4.0000', (string) $movements[1]->quantity);
        $this->assertSame('5.200000', (string) $movements[1]->unit_cost);

        $freshLine = $line->fresh();
        $this->assertSame('4.0000', (string) $freshLine->quantity_received);
        $this->assertSame('1.0000', (string) $freshLine->free_quantity_received);
        $this->assertSame('5.200000', (string) $freshLine->accrual_unit_cost);

        // GR-IR contract pin (documented Wave 3 deviation): the paid movement gets a
        // journal entry; the free movement (zero amount) legitimately gets NONE —
        // GeneralLedgerService early-returns on amount <= 0, never zero-value entries.
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'goods_receipt',
            'source_id' => $postedLine->movement_id,
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'goods_receipt',
            'source_id' => $postedLine->free_movement_id,
        ]);
    }

    #[Test]
    public function partial_receipts_create_one_header_per_receive_call(): void
    {
        $product = $this->createProduct('GRL-PARTIAL', 'Ledger Partial Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '10.0000', 'free_quantity' => '0.0000', 'unit_price' => '5.000', 'landed_unit_cost' => '5.000000'],
        ]);
        $line = $po->lines->first();

        $first = app(GoodsReceiptService::class)->receiveGoods($po, [$line->id => '4.0000'], [], [], [], null, $this->user->id);
        $second = app(GoodsReceiptService::class)->receiveGoods($first->purchaseOrder, [$line->id => '6.0000'], [], [], [], null, $this->user->id);

        $this->assertCount(2, GoodsReceipt::query()->where('purchase_order_id', $po->id)->orderBy('receipt_number')->get());
        $this->assertNotSame($first->receipt->id, $second->receipt->id);
        $this->assertMatchesRegularExpression('/^GRN-\d{4}-0002$/', $second->receipt->receipt_number);
        $this->assertLedgerCountersMatchPo($second->purchaseOrder->fresh(['lines']) ?? $second->purchaseOrder);
    }

    #[Test]
    public function free_only_receipt_writes_a_line_with_only_the_free_movement_link(): void
    {
        $product = $this->createProduct('GRL-FREE', 'Ledger Free Product', lastPurchaseCost: '5.000000');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '10.0000', 'free_quantity' => '2.0000', 'unit_price' => '5.000', 'landed_unit_cost' => '5.000000'],
        ]);
        $line = $po->lines->first();

        $result = app(GoodsReceiptService::class)->receiveGoods($po, [], [], [$line->id => '2.0000'], [], null, $this->user->id);

        $receiptLine = GoodsReceiptLine::query()->where('goods_receipt_id', $result->receipt->id)->sole();
        $this->assertSame('0.0000', (string) $receiptLine->received_qty);
        $this->assertSame('2.0000', (string) $receiptLine->free_qty);
        $this->assertNull($receiptLine->movement_id);
        $this->assertNotNull($receiptLine->free_movement_id);
        $this->assertSame('0.000000', (string) $receiptLine->effective_unit_cost);
        $this->assertLedgerCountersMatchPo($result->purchaseOrder->fresh(['lines']) ?? $result->purchaseOrder);
    }

    #[Test]
    public function paid_only_effective_unit_cost_uses_the_same_half_up_rounding_as_landed_cost(): void
    {
        $product = $this->createProduct('GRL-ROUND', 'Ledger Rounding Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '1.0000', 'free_quantity' => '0.0000', 'unit_price' => '1.000', 'landed_unit_cost' => '1.1234567'],
        ]);
        $line = $po->lines->first();

        $result = app(GoodsReceiptService::class)->receiveGoods($po, [$line->id => '1.0000'], [], [], [], null, $this->user->id);

        $receiptLine = GoodsReceiptLine::query()->where('goods_receipt_id', $result->receipt->id)->sole();
        $this->assertSame('1.123457', (string) $receiptLine->landed_unit_cost);
        $this->assertSame('1.123457', (string) $receiptLine->effective_unit_cost);
    }

    #[Test]
    public function receive_all_writes_a_receipt_header(): void
    {
        $product = $this->createProduct('GRL-ALL', 'Ledger Receive All Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '3.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);

        $result = app(GoodsReceiptService::class)->receiveAll($po, $this->user->id);

        $this->assertInstanceOf(GoodsReceiptResult::class, $result);
        $this->assertSame($po->id, $result->receipt->purchase_order_id);
    }

    #[Test]
    public function purchase_order_converter_stamps_the_actor_on_the_goods_receipt(): void
    {
        $product = $this->createProduct('GRL-CONVERT', 'Ledger Converter Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '2.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);
        $line = $po->lines->first();

        app(PurchaseOrderToGoodsReceiptConverter::class)->convert($po, [
            'received_quantities' => [$line->id => '2.0000'],
            'actor_user_id' => $this->user->id,
        ]);

        $this->assertSame($this->user->id, GoodsReceipt::query()->sole()->received_by);
    }

    #[Test]
    public function domain_exception_rolls_back_the_receipt_header(): void
    {
        $product = $this->createProduct('GRL-ROLLBACK', 'Ledger Rollback Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '3.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);
        $line = $po->lines->first();

        try {
            app(GoodsReceiptService::class)->receiveGoods($po, [$line->id => '4.0000'], [], [], [], null, $this->user->id);
            $this->fail('Expected over-receipt to throw.');
        } catch (\DomainException) {
            $this->assertDatabaseCount('goods_receipts', 0);
            $this->assertDatabaseCount('goods_receipt_lines', 0);
        }
    }

    #[Test]
    public function receive_endpoint_returns_goods_receipt_meta(): void
    {
        $product = $this->createProduct('GRL-META', 'Ledger Meta Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '3.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '3.0000'],
            ]);

        $response->assertOk();
        $response->assertJsonPath('meta.goods_receipt.receipt_number', fn (string $number): bool => str_starts_with($number, 'GRN-'));
        $response->assertJsonPath('meta.goods_receipt.id', GoodsReceipt::query()->sole()->id);
    }

    #[Test]
    public function receive_endpoint_can_save_a_draft_without_posting_stock(): void
    {
        $product = $this->createProduct('GRL-DRAFT-ENDPOINT', 'Ledger Draft Endpoint Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '3.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '3.0000'],
                'save_as_draft' => true,
            ]);

        $response->assertOk();
        $response->assertJsonPath('meta.goods_receipt.status', GoodsReceiptStatus::Draft->value);
        $response->assertJsonPath('meta.goods_receipt.receipt_number', null);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame('0.0000', (string) $line->fresh()->quantity_received);
    }

    #[Test]
    public function post_draft_endpoint_posts_the_receipt(): void
    {
        $product = $this->createProduct('GRL-DRAFT-ENDPOINT-POST', 'Ledger Draft Endpoint Post Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '3.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);
        $line = $po->lines->first();
        $draft = app(GoodsReceiptService::class)->createDraft(
            $po,
            [$line->id => '3.0000'],
            [],
            [],
            [],
            null,
            $this->user->id,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/goods-receipts/{$draft->id}/post");

        $response->assertOk();
        $response->assertJsonPath('data.status', GoodsReceiptStatus::Posted->value);
        $response->assertJsonPath('data.receipt_number', fn (string $number): bool => str_starts_with($number, 'GRN-'));
        $this->assertSame('3.0000', (string) $line->fresh()->quantity_received);
    }

    #[Test]
    public function posting_a_stale_draft_that_would_over_receive_throws(): void
    {
        // Two drafts each claim the full remaining quantity; the first post consumes
        // it, so the second post's re-validation (reading CURRENT counters inside
        // the cost lock) must reject the now-stale draft. pgsql caveat: the truly
        // concurrent variant needs FOR UPDATE semantics SQLite cannot exercise.
        $product = $this->createProduct('GRL-STALE', 'Stale Draft Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '10.0000', 'free_quantity' => '0.0000', 'unit_price' => '5.000', 'landed_unit_cost' => '5.000000'],
        ]);
        $line = $po->lines->first();
        $service = app(GoodsReceiptService::class);

        // Partial quantities: the first post leaves the PO Confirmed (7 of 10),
        // so the second post reaches the counter re-validation (7 > remaining 3)
        // rather than the earlier PO-status guard.
        $draftA = $service->createDraft($po, [$line->id => '7.0000'], [], [], [], null, $this->user->id);
        $draftB = $service->createDraft($po, [$line->id => '7.0000'], [], [], [], null, $this->user->id);

        $service->post($draftA, $this->user->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot receive more than ordered');

        $service->post($draftB, $this->user->id);
    }

    #[Test]
    public function goods_receipt_endpoints_return_404_for_other_company_receipts(): void
    {
        $product = $this->createProduct('GRL-XCOMPANY', 'Cross Company Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '5.0000', 'free_quantity' => '0.0000', 'unit_price' => '7.000', 'landed_unit_cost' => '7.000000'],
        ]);
        $line = $po->lines->first();
        $draft = app(GoodsReceiptService::class)->createDraft(
            $po,
            [$line->id => '2.0000'],
            [],
            [],
            [],
            null,
            $this->user->id,
        );

        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $otherCompany->id,
            'role' => MembershipRole::Admin,
        ]);

        // Acting inside company B (real X-Company-Id header → CompanyContextMiddleware),
        // company A's draft must be invisible on every endpoint.
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $otherCompany->id)
            ->getJson("/api/v1/goods-receipts/{$draft->id}")
            ->assertNotFound();
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $otherCompany->id)
            ->postJson("/api/v1/goods-receipts/{$draft->id}/post")
            ->assertNotFound();
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $otherCompany->id)
            ->deleteJson("/api/v1/goods-receipts/{$draft->id}")
            ->assertNotFound();

        $this->assertNotNull($draft->fresh());
        $this->assertSame(GoodsReceiptStatus::Draft, $draft->fresh()?->status);
    }

    #[Test]
    public function goods_receipt_index_can_count_drafts_for_the_workbench(): void
    {
        $product = $this->createProduct('GRL-DRAFT-INDEX', 'Ledger Draft Index Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '5.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);
        $line = $po->lines->first();

        $draft = app(GoodsReceiptService::class)->createDraft(
            $po,
            [$line->id => '2.0000'],
            [],
            [],
            [],
            null,
            $this->user->id,
        );
        app(GoodsReceiptService::class)->receiveGoods($po, [$line->id => '1.0000'], [], [], [], null, $this->user->id);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/goods-receipts?status=draft&per_page=10');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $draft->id);
        $response->assertJsonPath('data.0.status', GoodsReceiptStatus::Draft->value);
    }

    #[Test]
    public function delete_draft_endpoint_removes_uncosted_receipt_lines(): void
    {
        $product = $this->createProduct('GRL-DRAFT-ENDPOINT-DELETE', 'Ledger Draft Endpoint Delete Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '3.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);
        $line = $po->lines->first();
        $draft = app(GoodsReceiptService::class)->createDraft(
            $po,
            [$line->id => '2.0000'],
            [],
            [],
            [],
            null,
            $this->user->id,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/goods-receipts/{$draft->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('goods_receipts', ['id' => $draft->id]);
        $this->assertDatabaseMissing('goods_receipt_lines', ['goods_receipt_id' => $draft->id]);
        $this->assertSame('0.0000', (string) $line->fresh()->quantity_received);
    }

    #[Test]
    public function delete_draft_endpoint_rejects_posted_receipts(): void
    {
        $product = $this->createProduct('GRL-DRAFT-ENDPOINT-POSTED', 'Ledger Draft Endpoint Posted Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '3.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);
        $line = $po->lines->first();
        $posted = app(GoodsReceiptService::class)->receiveGoods($po, [$line->id => '2.0000'], [], [], [], null, $this->user->id)->receipt;

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/goods-receipts/{$posted->id}");

        $response->assertUnprocessable();
        $this->assertDatabaseHas('goods_receipts', ['id' => $posted->id]);
        $this->assertSame('2.0000', (string) $line->fresh()->quantity_received);
    }

    /**
     * @param  list<array{product: Product, quantity: string, free_quantity: string, unit_price: string, landed_unit_cost: string}>  $lines
     */
    private function createConfirmedPurchaseOrder(array $lines): Document
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
            'document_number' => 'PO-GRL-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        $lineNumber = 1;
        foreach ($lines as $line) {
            DocumentLine::create([
                'document_id' => $po->id,
                'product_id' => $line['product']->id,
                'product_code' => $line['product']->sku,
                'line_number' => $lineNumber++,
                'description' => $line['product']->name,
                'quantity' => $line['quantity'],
                'free_quantity' => $line['free_quantity'],
                'quantity_delivered' => '0.0000',
                'quantity_received' => '0.0000',
                'free_quantity_received' => '0.0000',
                'quantity_invoiced' => '0.0000',
                'unit_price' => $line['unit_price'],
                'line_total' => bcmul($line['quantity'], $line['unit_price'], 3),
                'allocated_costs' => '0.000000',
                'landed_unit_cost' => $line['landed_unit_cost'],
                'price_entry_mode' => 'unit',
            ]);
        }

        return $po->fresh(['lines']);
    }

    private function createProduct(string $sku, string $name, string $lastPurchaseCost = '0.000000'): Product
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
            'last_purchase_cost' => $lastPurchaseCost,
        ]);
    }

    private function assertLedgerCountersMatchPo(Document $purchaseOrder): void
    {
        foreach ($purchaseOrder->lines as $line) {
            $ledgerTotals = GoodsReceiptLine::query()
                ->where('po_line_id', $line->id)
                ->selectRaw('COALESCE(SUM(received_qty), 0) as received_qty')
                ->selectRaw('COALESCE(SUM(free_qty), 0) as free_qty')
                ->first();

            $this->assertSame(0, bccomp((string) $line->quantity_received, (string) $ledgerTotals->received_qty, 4));
            $this->assertSame(0, bccomp((string) $line->free_quantity_received, (string) $ledgerTotals->free_qty, 4));
        }
    }
}
