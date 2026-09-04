<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
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
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.receive']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);
    }

    public function test_can_list_stock_movements(): void
    {
        $service = app(StockAdjustmentService::class);

        // Create multiple movements
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );
        $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '25.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_stock_movement_list_exposes_product_unit_quantity_decimals(): void
    {
        $category = UnitCategory::factory()->create([
            'tenant_id' => null,
            'code' => 'stock-movement-weight',
            'name' => 'Stock Movement Weight',
            'is_system' => true,
            'is_active' => true,
        ]);
        $unit = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'stock-movement-kg',
            'name' => 'Stock Movement Kilogram',
            'symbol' => 'kg',
            'decimal_places' => 3,
            'is_system' => true,
            'is_active' => true,
        ]);
        $this->product->update(['unit_id' => $unit->id]);
        app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '2.5000',
            reference: 'UNIT-PRECISION',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements');

        $response->assertOk();
        $response->assertJsonPath('data.0.quantity', '2.5000');
        $response->assertJsonPath('data.0.quantity_decimals', 3);
    }

    public function test_can_filter_movements_by_product(): void
    {
        $product2 = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-002',
            'name' => 'Second Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);

        $service = app(StockAdjustmentService::class);

        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '50.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );
        $service->receive(
            productId: $product2->id,
            locationId: $this->warehouse->id,
            quantity: '30.00',
            reference: 'PO-002',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements?product_id='.$this->product->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_filter_movements_by_type(): void
    {
        $service = app(StockAdjustmentService::class);

        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );
        $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );
        $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '15.00',
            reference: 'SO-002',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements?movement_type=issue');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_list_exposes_source_document_provenance(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2026-0001',
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'discount_amount' => '0.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '100.000',
        ]);

        $movement = app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: $document->document_number,
            userId: $this->user->id,
        );
        $movement->forceFill([
            'reference_type' => 'Document',
            'reference_id' => $document->id,
        ])->save();

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements?product_id='.$this->product->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.source_document_id', $document->id);
        $response->assertJsonPath('data.0.source_document_type', 'invoice');
    }

    public function test_movements_are_ordered_by_date_descending(): void
    {
        $service = app(StockAdjustmentService::class);

        $receiptMovement = $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->user->id,
        );

        // Wait 1 second to ensure different timestamps
        sleep(1);

        $issueMovement = $service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '20.00',
            reference: 'SO-001',
            userId: $this->user->id,
        );

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements');

        $response->assertStatus(200);
        // Most recent (issue) should be first
        $response->assertJsonPath('data.0.movement_type', 'issue');
        $response->assertJsonPath('data.1.movement_type', 'receipt');
    }

    /**
     * DPA V7 / T11 — the four raw write endpoints are GONE.
     *
     * A 404 (no route) rather than a 403, because the routes themselves were
     * deleted. Their coverage did not evaporate: the precision boundary moved to
     * IngressPrecisionTest, the reserved-aware refusal below and in
     * StockAdjustByDeltaTest, and the permission matrix to
     * StockAdjustmentEndpointTest.
     */
    public function test_the_four_raw_stock_write_endpoints_no_longer_exist(): void
    {
        foreach ([
            '/api/v1/stock-movements/receive',
            '/api/v1/stock-movements/issue',
            '/api/v1/stock-movements/transfer',
            '/api/v1/stock-movements/adjust',
        ] as $route) {
            $this->actingAs($this->user)->postJson($route, [])->assertNotFound();
        }

        // The READ surface is untouched.
        $this->actingAs($this->user)->getJson('/api/v1/stock-movements')->assertOk();
    }

    /**
     * The replacement for test_issue_fails_with_insufficient_stock.
     *
     * It maps onto the RESERVED-AWARE boundary, not the on-hand one (D1a /
     * gate I-1): `issue()` refused against `quantity - reserved`, so the
     * replacement MUST exercise `reserved > 0` or the loosening would ship
     * green — 10 on hand with 8 reserved leaves only 2 available.
     */
    public function test_a_negative_adjustment_is_refused_at_the_reserved_aware_boundary(): void
    {
        $this->user->givePermissionTo([
            'inventory.adjustments.view',
            'inventory.adjustments.create',
            'inventory.adjustments.post',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '10.0000',
            'reserved' => '8.0000',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'post_immediately' => true,
            'lines' => [[
                'product_id' => $this->product->id,
                'reason_code' => 'adjustment_negative',
                'delta_quantity' => '-5.0000',
                'observed_before' => '10.0000',
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ADJUSTMENT_EXCEEDS_AVAILABLE');
        // On hand would have allowed −5; AVAILABLE does not.
        $response->assertJsonPath('error.details.quantity_before', '10.0000');
        $response->assertJsonPath('error.details.reserved', '8.0000');
        $response->assertJsonPath('error.details.available', '2.0000');
        $response->assertJsonPath('error.details.overridable', true);

        $this->assertSame('10.0000', (string) StockLevel::query()
            ->where('product_id', $this->product->id)
            ->value('quantity'));
    }

    /**
     * Retargeted by T11: the raw receive endpoint it guarded is deleted, so the
     * authorization boundary it protected is now the ADJUSTMENT document's. The
     * full matrix lives in StockAdjustmentEndpointTest; this keeps a direct
     * assertion in the file that used to own it.
     */
    public function test_unauthorized_user_cannot_write_stock(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'reason_code' => 'adjustment_positive',
                'delta_quantity' => '10.0000',
                'observed_before' => '0.0000',
            ]],
        ]);

        // The user holds inventory.adjust but NOT inventory.adjustments.create.
        $response->assertStatus(403);
    }

    private function ledgerRow(
        int $index,
        MovementType $type = MovementType::Receipt,
        ?MovementReason $reason = null,
    ): StockMovement {
        return StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'movement_type' => $type,
            'reason' => $reason,
            'quantity' => '1.0000',
            'quantity_before' => (string) $index.'.0000',
            'quantity_after' => (string) ($index + 1).'.0000',
            'reference' => 'CAP-'.$index,
            'user_id' => $this->user->id,
            'occurred_at' => now()->addSeconds($index),
        ]);
    }

    public function test_index_without_page_is_bounded_to_25(): void
    {
        foreach (range(1, 30) as $index) {
            $this->ledgerRow($index);
        }

        $response = $this->actingAs($this->user)->getJson('/api/v1/stock-movements');
        $response->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 30);
    }

    public function test_transfer_alias_is_server_side_across_pages(): void
    {
        foreach (range(1, 26) as $index) {
            $this->ledgerRow($index, $index % 2 === 0 ? MovementType::TransferIn : MovementType::TransferOut);
        }
        $this->ledgerRow(99, MovementType::Receipt);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/stock-movements?movement_type=transfer&page=1&per_page=25');

        $response->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 26);
    }

    public function test_write_off_alias_is_server_side_across_pages(): void
    {
        $reasons = [MovementReason::WriteOff, MovementReason::Expiry, MovementReason::Damage];
        foreach (range(1, 26) as $index) {
            $this->ledgerRow($index, MovementType::Issue, $reasons[$index % 3]);
        }
        $this->ledgerRow(99, MovementType::Issue, MovementReason::Delivery);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/stock-movements?reason=write_off&page=1&per_page=25');

        $response->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 26);
    }

    public function test_search_matches_product_name_sku_and_movement_reference(): void
    {
        $match = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SKU-BETA',
            'name' => 'Alpha Needle',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);
        $service = app(StockAdjustmentService::class);
        $service->receive($match->id, $this->warehouse->id, '1.0000', 'REF-GAMMA', $this->user->id);
        $service->receive($this->product->id, $this->warehouse->id, '1.0000', 'NO-MATCH', $this->user->id);

        foreach (['alpha', 'sku-beta', 'ref-gamma'] as $search) {
            $response = $this->actingAs($this->user)->getJson(
                '/api/v1/stock-movements?search='.rawurlencode($search),
            );
            $response->assertOk()->assertJsonCount(1, 'data');
            self::assertSame($match->id, $response->json('data.0.product_id'));
        }
    }

    public function test_search_treats_percent_and_underscore_as_literals(): void
    {
        $percent = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'LITERAL-PERCENT',
            'name' => 'Percent%Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);
        $underscore = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'LITERAL_UNDERSCORE',
            'name' => 'Underscore_Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);
        $service = app(StockAdjustmentService::class);
        $service->receive($percent->id, $this->warehouse->id, '1.0000', 'LITERAL-PERCENT', $this->user->id);
        $service->receive($underscore->id, $this->warehouse->id, '1.0000', 'LITERAL-UNDERSCORE', $this->user->id);
        $service->receive($this->product->id, $this->warehouse->id, '1.0000', 'ORDINARY', $this->user->id);

        $this->actingAs($this->user)
            ->getJson('/api/v1/stock-movements?search=%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_id', $percent->id);
        $this->actingAs($this->user)
            ->getJson('/api/v1/stock-movements?search=_')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_id', $underscore->id);
    }

    public function test_search_rejects_121_characters(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/stock-movements?search='.str_repeat('x', 121))
            ->assertUnprocessable();
    }

    public function test_tied_created_at_rows_cross_two_pages_without_duplicates_or_omissions(): void
    {
        $createdAt = CarbonImmutable::parse('2026-09-03 12:00:00');

        // Explicit v4-shaped ids, inserted in a deliberately shuffled
        // (non-monotonic) order so that neither insertion order nor
        // reverse-insertion order coincides with `id DESC`. Without this the
        // model's ORDERED `HasUuids` keys (Laravel 12 emits uuid7) make
        // insertion order and `id DESC` agree, and the assertion below passes
        // even with the `orderByDesc('id')` tie-break removed (gate r1, B1).
        /** @var list<string> $ids */
        $ids = array_map(
            static fn (int $sequence): string => sprintf('7f000000-0000-4000-8000-%012x', $sequence),
            range(1, 30),
        );

        // 1, 3, 5, ..., 29, 30, 28, ..., 2 — the first row inserted holds the
        // LOWEST id and the last holds the SECOND-lowest, so `id DESC` matches
        // neither the insertion order nor its reverse.
        $insertionOrder = [...range(1, 29, 2), ...range(30, 2, -2)];
        self::assertCount(30, $insertionOrder);

        foreach ($insertionOrder as $sequence) {
            $movement = new StockMovement;
            $movement->forceFill([
                'id' => $ids[$sequence - 1],
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'product_id' => $this->product->id,
                'location_id' => $this->warehouse->id,
                'movement_type' => MovementType::Receipt,
                'reason' => null,
                'quantity' => '1.0000',
                'quantity_before' => $sequence.'.0000',
                'quantity_after' => ($sequence + 1).'.0000',
                'reference' => 'TIED-'.$sequence,
                'user_id' => $this->user->id,
                'occurred_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }

        // Expected sequence computed from the ids themselves, never from a
        // query: the ids share a fixed prefix and a zero-padded hex suffix, so a
        // descending string sort is exactly `id DESC` on both drivers (PG's
        // `uuid` type orders by the same canonical lowercase byte sequence).
        $expectedIds = $ids;
        rsort($expectedIds, SORT_STRING);

        $pageOne = $this->actingAs($this->user)
            ->getJson('/api/v1/stock-movements?page=1&per_page=15')->assertOk()->json('data');
        $pageTwo = $this->actingAs($this->user)
            ->getJson('/api/v1/stock-movements?page=2&per_page=15')->assertOk()->json('data');
        $actualIds = array_column([...$pageOne, ...$pageTwo], 'id');

        self::assertCount(30, $actualIds);
        self::assertCount(30, array_unique($actualIds));
        self::assertSame($expectedIds, $actualIds);
    }

    public function test_index_accepts_cleared_filters_sent_as_empty_strings(): void
    {
        $this->ledgerRow(1);

        // The web list page sends `search=` / `movement_type=` / `reason=` when
        // the operator clears a filter; the global ConvertEmptyStringsToNull
        // middleware turns those into a present null, which must read as "no
        // filter", not as a 422 (gate r1, B2).
        $this->actingAs($this->user)
            ->getJson('/api/v1/stock-movements?search=&movement_type=&reason=&location_id=&product_id=')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 1);
    }
}
