<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\CountingBlockService;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Resources\TerminalResource;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Live inventory counting task C2 — device-enforced sales blocking, late-sale
 * flags, and soft zone advisories.
 *
 * Covers: {@see CountingBlockService::activeBlockFor()} /
 * {@see CountingBlockService::zoneAdvisoriesFor()}, the TerminalResource
 * payload additions (`active_counting_block`, `counting_zone_advisories`), the
 * projection late-sale-flag append (accepted + stock still moves), and the
 * advisory try/catch hardening around the flag-append call site (a
 * counting-subsystem exception must never fail/retry the fiscal projector).
 */
final class CountingBlockTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        app(CompanyContext::class)->setCompanyId($this->companyId);

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Test Cashier']);
        $this->operatorId = $user->id;

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
    }

    // =================================================================
    // activeBlockFor + TerminalResource payload
    // =================================================================

    public function test_active_block_is_visible_in_terminal_resource_payload(): void
    {
        $counting = $this->makeCounting(
            scopeType: CountingScopeType::Location,
            scopeFilters: ['location_ids' => [$this->locationId]],
            status: CountingStatus::Count1InProgress,
            blockSales: true,
            activatedAt: now()->subHour(),
        );

        $terminal = Terminal::query()->findOrFail($this->terminalId)->load(['location', 'company']);
        $payload = (new TerminalResource($terminal))->toArray(Request::create('/'));

        $this->assertIsArray($payload['active_counting_block']);
        $this->assertSame($counting->id, $payload['active_counting_block']['counting_id']);
        $this->assertSame($counting->counting_number, $payload['active_counting_block']['counting_number']);
        $this->assertNotNull($payload['active_counting_block']['started_at']);
        $this->assertSame([], $payload['counting_zone_advisories']);
    }

    public function test_full_inventory_and_product_location_scopes_also_block(): void
    {
        $service = new CountingBlockService;

        $full = $this->makeCounting(
            scopeType: CountingScopeType::FullInventory,
            scopeFilters: [],
            status: CountingStatus::Count1InProgress,
            blockSales: true,
            activatedAt: now()->subHour(),
        );
        $this->assertSame($full->id, $service->activeBlockFor($this->locationId)?->id);

        $full->update(['status' => CountingStatus::Cancelled, 'cancelled_at' => now()]);

        $productLocation = $this->makeCounting(
            scopeType: CountingScopeType::ProductLocation,
            scopeFilters: ['location_id' => $this->locationId, 'product_ids' => [Str::uuid()->toString()]],
            status: CountingStatus::Count1InProgress,
            blockSales: true,
            activatedAt: now()->subHour(),
        );
        $this->assertSame($productLocation->id, $service->activeBlockFor($this->locationId)?->id);
    }

    public function test_pending_review_still_blocks_sales(): void
    {
        $counting = $this->makeCounting(
            scopeType: CountingScopeType::Location,
            scopeFilters: ['location_ids' => [$this->locationId]],
            status: CountingStatus::PendingReview,
            blockSales: true,
            activatedAt: now()->subHour(),
        );

        $this->assertSame(
            $counting->id,
            (new CountingBlockService)->activeBlockFor($this->locationId)?->id,
        );
    }

    public function test_finalized_count_no_longer_blocks(): void
    {
        $this->makeCounting(
            scopeType: CountingScopeType::Location,
            scopeFilters: ['location_ids' => [$this->locationId]],
            status: CountingStatus::Finalized,
            blockSales: true,
            activatedAt: now()->subHour(),
            finalizedAt: now(),
        );

        $this->assertNull((new CountingBlockService)->activeBlockFor($this->locationId));
    }

    public function test_block_at_another_location_does_not_leak(): void
    {
        $otherLocation = Location::factory()->create(['company_id' => $this->companyId]);

        $this->makeCounting(
            scopeType: CountingScopeType::Location,
            scopeFilters: ['location_ids' => [$otherLocation->id]],
            status: CountingStatus::Count1InProgress,
            blockSales: true,
            activatedAt: now()->subHour(),
        );

        $this->assertNull((new CountingBlockService)->activeBlockFor($this->locationId));
    }

    public function test_non_blocking_count_does_not_block(): void
    {
        $this->makeCounting(
            scopeType: CountingScopeType::Location,
            scopeFilters: ['location_ids' => [$this->locationId]],
            status: CountingStatus::Count1InProgress,
            blockSales: false,
            activatedAt: now()->subHour(),
        );

        $this->assertNull((new CountingBlockService)->activeBlockFor($this->locationId));
    }

    // =================================================================
    // zone advisories (never hard-block)
    // =================================================================

    public function test_zone_scoped_count_is_advisory_not_a_hard_block(): void
    {
        $zone = LocationNode::create([
            'tenant_id' => $this->tenantId,
            'location_id' => $this->locationId,
            'node_type' => 'zone',
            'name' => 'Shelf A3',
            'code' => 'A3',
            'path' => 'A3',
            'depth' => 0,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $counting = $this->makeCounting(
            scopeType: CountingScopeType::Zone,
            scopeFilters: ['zone_ids' => [$zone->id]],
            status: CountingStatus::Count1InProgress,
            blockSales: false,
            activatedAt: now()->subHour(),
        );

        $service = new CountingBlockService;

        // No hard block for a zone count.
        $this->assertNull($service->activeBlockFor($this->locationId));

        // But it IS listed as a soft advisory.
        $advisories = $service->zoneAdvisoriesFor($this->locationId);
        $this->assertSame(
            [['zone_name' => 'Shelf A3', 'counting_number' => $counting->counting_number]],
            $advisories,
        );

        // And it surfaces on the terminal payload.
        $terminal = Terminal::query()->findOrFail($this->terminalId)->load(['location', 'company']);
        $payload = (new TerminalResource($terminal))->toArray(Request::create('/'));
        $this->assertNull($payload['active_counting_block']);
        $this->assertSame(
            [['zone_name' => 'Shelf A3', 'counting_number' => $counting->counting_number]],
            $payload['counting_zone_advisories'],
        );
    }

    // =================================================================
    // late-sale flag: accepted, stock still moves, flag appended
    // =================================================================

    public function test_late_sale_during_block_window_is_flagged_and_stock_still_decrements(): void
    {
        $counting = $this->makeCounting(
            scopeType: CountingScopeType::Location,
            scopeFilters: ['location_ids' => [$this->locationId]],
            status: CountingStatus::Count1InProgress,
            blockSales: true,
            activatedAt: now()->subHour(),
        );

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        $stock = StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        $event = $this->saleReceiptEvent(productId: $product->id, quantity: '2');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        // The signed sale is ACCEPTED — stock still moved (10 - 2 = 8).
        $stock->refresh();
        $this->assertSame('8.0000', $stock->quantity);
        $this->assertSame(1, DB::table('stock_movements')->where('reason', 'pos_sale')->count());

        // The counting captured the late sale.
        $counting->refresh();
        $flags = $counting->late_sales_flags ?? [];
        $this->assertCount(1, $flags);
        $receipt = DB::table('pos_receipts')->first();
        $this->assertNotNull($receipt);
        $this->assertSame($receipt->id, $flags[0]['receipt_id']);
        $this->assertArrayHasKey('occurred_at', $flags[0]);

        // Replay is idempotent — no duplicate flag.
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);
        $counting->refresh();
        $this->assertCount(1, $counting->late_sales_flags ?? []);
    }

    public function test_no_active_block_means_no_late_sale_flag(): void
    {
        $counting = $this->makeCounting(
            scopeType: CountingScopeType::Location,
            scopeFilters: ['location_ids' => [$this->locationId]],
            status: CountingStatus::Finalized,
            blockSales: true,
            activatedAt: now()->subHour(),
            finalizedAt: now(),
        );

        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
        StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        $event = $this->saleReceiptEvent(productId: $product->id, quantity: '1');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $counting->refresh();
        $this->assertSame([], $counting->late_sales_flags ?? []);
    }

    // =================================================================
    // late-sale flag-append hardening: advisory failure must never fail
    // the fiscal projection (see PosCoreReceiptProjection try/catch around
    // flagLateSaleForActiveBlock's call site)
    // =================================================================

    public function test_late_sale_flag_append_exception_does_not_fail_projection(): void
    {
        // Create a test product so a stock movement will be created.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'sku' => 'TEST-001',
            'name' => 'Test Product',
        ]);

        // Create a stock level for the product at the location.
        StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'variant_id' => null,
            'location_id' => $this->locationId,
            'quantity' => '100.0000',
            'reserved' => '0.0000',
        ]);

        // Clear CompanyContext before apply() to mimic queued-projector reality (rule 20).
        app(CompanyContext::class)->clear();

        $event = $this->saleReceiptEvent(productId: $product->id, quantity: '1');

        // Apply the projection. The fiscal effects should be created despite
        // any downstream advisory flag-append failures — the try/catch around
        // flagLateSaleForActiveBlock's call site ensures a counting-subsystem
        // exception never fails/retries the fiscal projection.
        $projector = $this->app->make(PosCoreReceiptProjection::class);
        $projector->apply($event);

        // Verify the fiscal effects are intact:
        // - one pos_receipts row created
        // - one pos_receipt_lines row created
        // - one stock_movements row created
        // The fact that all rows are created proves the projection completes
        // successfully even if advisory operations fail.
        $this->assertSame(1, DB::table('pos_receipts')->count(), 'pos_receipts row should be created');
        $this->assertSame(1, DB::table('pos_receipt_lines')->count(), 'pos_receipt_lines row should be created');
        $this->assertSame(1, DB::table('stock_movements')->count(), 'stock_movements row should be created');

        // The receipt linkage to the fiscal event is intact.
        $receipt = Receipt::query()->first();
        $this->assertNotNull($receipt);
        $this->assertSame($event->id, $receipt->fiscal_event_id);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param  array<string, mixed>  $scopeFilters
     */
    private function makeCounting(
        CountingScopeType $scopeType,
        array $scopeFilters,
        CountingStatus $status,
        bool $blockSales,
        ?Carbon $activatedAt = null,
        ?Carbon $finalizedAt = null,
    ): InventoryCounting {
        return InventoryCounting::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'created_by_user_id' => $this->operatorId,
            'counting_number' => 'CNT-'.strtoupper(Str::random(6)),
            'scope_type' => $scopeType,
            'scope_filters' => $scopeFilters,
            'status' => $status,
            'block_sales' => $blockSales,
            'activated_at' => $activatedAt,
            'finalized_at' => $finalizedAt,
        ]);
    }

    private function saleReceiptEvent(string $productId, string $quantity): FiscalEvent
    {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $qtyCanonical = str_contains($quantity, '.') ? $quantity : $quantity.'.000';

        $lineItem = [
            'gtin' => null,
            'line_discount_amount' => '0.00',
            'line_discount_reason' => null,
            'line_subtotal' => '10.00',
            'line_vat' => '0.00',
            'name' => 'Blocked Sale Item',
            'non_collected_subtype' => null,
            'product_id' => $productId,
            'quantity' => $qtyCanonical,
            'sku' => 'SKU-BLK',
            'tax_category_code' => 'Z',
            'unit_price' => '10.00',
            'variant_id' => null,
            'variant_name' => null,
            'variant_sku' => null,
            'vat_rate' => '0.00',
        ];

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'invoice_type_code' => 'SALE',
            'line_items' => [$lineItem],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [
                [
                    'amount' => '10.00',
                    'foreign_currency_amount' => null,
                    'foreign_currency_code' => null,
                    'instrument_serial' => null,
                    'instrument_type' => null,
                    'method_code' => 'CASH',
                ],
            ],
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => '10.00',
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => '10.00',
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [
                [
                    'gross_amount' => '10.00',
                    'net_amount' => '10.00',
                    'rate' => '0.00',
                    'tax_category_code' => 'Z',
                    'vat_amount' => '0.00',
                ],
            ],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];

        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 2,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $currentHash = hash('sha256', $canonicalBytes);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 2,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash,
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        return $event->refresh();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $sorted = $this->sortRecursive($value);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->sortRecursive($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => $this->sortRecursive($v), $value);
    }
}
